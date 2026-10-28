<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

use Keel\Async\Io;
use Keel\Async\Suspension;

/**
 * A MongoDB client in PHP, speaking the wire protocol (OP_MSG, MongoDB 3.6+) itself: no
 * extension needed, and under `keel run` a query suspends only the calling fiber.
 *
 *     $mongo = new MongoClient('mongodb://app:secret@db1,db2,db3/shop?authSource=admin');
 *     $orders = $mongo->collection('orders');
 *     $orders->insertOne(['customer' => 'ana', 'total' => Decimal128::fromString('19.99')]);
 *     $orders->find(['customer' => 'ana'], ['sort' => ['_id' => -1], 'limit' => 20]);
 *
 * - `mongodb+srv://` seed lists (Atlas) are resolved through DNS; `tls=true` (default for +srv)
 *   encrypts the connection.
 * - Commands go to the primary: the client asks each host `hello` and keeps the writable one,
 *   and reconnects once when a failover makes it step down.
 * - Authentication: SCRAM-SHA-256 (`authSource`, default admin).
 * - One connection runs one command at a time; concurrent fibers queue. Use one client per
 *   process (it is a singleton through MongoConnection).
 *
 * Not supported: multi-document transactions, change streams, compression and client-side
 * field-level encryption. Use the official extension (mongodb/mongodb) when you need them.
 */
final class MongoClient
{
    private const int OP_MSG = 2013;

    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    private int $requestId = 0;

    private bool $busy = false;

    /** @var list<Suspension> */
    private array $waiting = [];

    /** @var list<array{0: string, 1: int}> */
    private array $hosts;

    private string $database;

    /** @var array<string, string> */
    private array $options;

    private ?string $user;

    private ?string $password;

    public function __construct(string $uri, private readonly float $timeout = 10.0)
    {
        if (preg_match('#^mongodb(\+srv)?://(?:([^:@/]*)(?::([^@/]*))?@)?([^/?]+)(?:/([^?]*))?(?:\?(.*))?$#', $uri, $m) !== 1) {
            throw new \InvalidArgumentException('Not a MongoDB connection string: expected mongodb://[user:password@]host[:port][,host...]/[database][?options].');
        }
        parse_str($m[6] ?? '', $options);
        $this->options = array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', array_filter($options, \is_string(...), \ARRAY_FILTER_USE_KEY));
        $this->user = $m[2] === '' ? null : rawurldecode($m[2]);
        $this->password = $m[3] !== '' ? rawurldecode($m[3]) : null;
        $this->database = ($m[5] ?? '') === '' ? 'test' : rawurldecode($m[5]);
        $srv = $m[1] === '+srv';
        if ($srv) {
            $this->options += ['tls' => 'true'];
        }
        $this->hosts = $srv ? self::srv($m[4]) : array_map(static function (string $host): array {
            $parts = explode(':', $host);

            return [$parts[0], (int) ($parts[1] ?? 27017)];
        }, explode(',', $m[4]));
    }

    /** The database named in the connection string (default "test"). */
    public function database(): string
    {
        return $this->database;
    }

    public function collection(string $name, ?string $database = null): MongoCollection
    {
        return new MongoCollection($this, $database ?? $this->database, $name);
    }

    /**
     * Runs a command and returns the reply. A reply with ok: 0 or write errors throws.
     *
     * @param array<string, mixed> $command the command name first, e.g. ['find' => 'orders', 'filter' => [...]]
     * @return array<string, mixed>
     */
    public function command(string $database, array $command): array
    {
        $this->lock();
        try {
            try {
                return $this->roundTrip($database, $command);
            } catch (MongoException $e) {
                // After a failover, the old primary refuses writes: find the new one and retry once.
                if (!\in_array($e->codeName, ['NotWritablePrimary', 'NotPrimaryNoSecondaryOk', 'NotPrimaryOrSecondary', 'PrimarySteppedDown', 'InterruptedDueToReplStateChange'], true)) {
                    throw $e;
                }
                $this->close();

                return $this->roundTrip($database, $command);
            }
        } finally {
            $this->unlock();
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function roundTrip(string $database, array $command): array
    {
        $this->connect();
        $reply = $this->send($command + ['$db' => $database]);
        if (($reply['ok'] ?? 0) != 1) {
            $code = \is_int($reply['code'] ?? null) ? $reply['code'] : 0;
            throw new MongoException('MongoDB: ' . (\is_string($reply['errmsg'] ?? null) ? $reply['errmsg'] : 'command failed'), $code, \is_string($reply['codeName'] ?? null) ? $reply['codeName'] : '');
        }
        $writeErrors = $reply['writeErrors'] ?? null;
        if (\is_array($writeErrors) && \is_array($writeErrors[0] ?? null)) {
            $first = $writeErrors[0];
            $code = \is_int($first['code'] ?? null) ? $first['code'] : 0;
            throw new MongoException('MongoDB write error: ' . (\is_string($first['errmsg'] ?? null) ? $first['errmsg'] : 'unknown') . ($code === 11000 ? ' (duplicate key)' : ''), $code, $code === 11000 ? 'DuplicateKey' : '');
        }

        return $reply;
    }

    private function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }
        $errors = [];
        foreach ($this->hosts as [$host, $port]) {
            try {
                $this->open($host, $port);
                $hello = $this->send(['hello' => 1, '$db' => 'admin']);
                if (($hello['isWritablePrimary'] ?? false) === true || \count($this->hosts) === 1 || ($this->options['directConnection'] ?? '') === 'true') {
                    $this->authenticate();

                    return;
                }
                $errors[] = "$host:$port is not the primary";
            } catch (\RuntimeException $e) {
                $errors[] = "$host:$port: " . $e->getMessage();
            }
            $this->close();
        }

        throw new MongoException('No writable MongoDB primary reachable (' . implode('; ', $errors) . ').');
    }

    private function open(string $host, int $port): void
    {
        $tls = \in_array(strtolower($this->options['tls'] ?? $this->options['ssl'] ?? 'false'), ['true', '1'], true);
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'verify_peer' => ($this->options['tlsInsecure'] ?? '') !== 'true',
            'verify_peer_name' => ($this->options['tlsInsecure'] ?? '') !== 'true',
        ] + (isset($this->options['tlsCAFile']) ? ['cafile' => $this->options['tlsCAFile']] : [])]);
        // TLS handshakes complete before the socket goes non-blocking; plain TCP connects asynchronously.
        $flags = $tls ? \STREAM_CLIENT_CONNECT : \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
        $socket = @stream_socket_client(($tls ? 'tls://' : 'tcp://') . $host . ':' . $port, $errno, $error, $this->timeout, $flags, $context);
        if ($socket === false) {
            throw new MongoException("Cannot connect to MongoDB at $host:$port: $error");
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        Io::writable($socket, $this->timeout);
    }

    private function authenticate(): void
    {
        if ($this->user === null) {
            return;
        }
        $mechanism = $this->options['authMechanism'] ?? 'SCRAM-SHA-256';
        if ($mechanism !== 'SCRAM-SHA-256') {
            throw new MongoException("authMechanism $mechanism is not supported; use SCRAM-SHA-256.");
        }
        $source = $this->options['authSource'] ?? 'admin';
        $scram = new Scram($this->user, $this->password ?? '');
        $reply = $this->checked($this->send(['saslStart' => 1, 'mechanism' => 'SCRAM-SHA-256', 'payload' => new Binary($scram->first()), 'autoAuthorize' => 1, 'options' => ['skipEmptyExchange' => true], '$db' => $source]));
        $conversation = $reply['conversationId'] ?? 1;
        $reply = $this->checked($this->send(['saslContinue' => 1, 'conversationId' => $conversation, 'payload' => new Binary($scram->final(self::payload($reply))), '$db' => $source]));
        $scram->verify(self::payload($reply));
        while (($reply['done'] ?? true) !== true) {
            $reply = $this->checked($this->send(['saslContinue' => 1, 'conversationId' => $conversation, 'payload' => new Binary(''), '$db' => $source]));
        }
    }

    /**
     * @param array<string, mixed> $reply
     * @return array<string, mixed>
     */
    private function checked(array $reply): array
    {
        if (($reply['ok'] ?? 0) != 1) {
            throw new MongoException('MongoDB authentication failed: ' . (\is_string($reply['errmsg'] ?? null) ? $reply['errmsg'] : 'unknown error'), \is_int($reply['code'] ?? null) ? $reply['code'] : 0);
        }

        return $reply;
    }

    /** @param array<string, mixed> $reply */
    private static function payload(array $reply): string
    {
        $payload = $reply['payload'] ?? null;

        return $payload instanceof Binary ? $payload->data : (\is_string($payload) ? $payload : '');
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function send(array $document): array
    {
        \assert($this->socket !== null);
        $id = ++$this->requestId;
        $body = pack('V', 0) . "\x00" . Bson::encode($document);
        Io::writeAll($this->socket, pack('VVVV', 16 + \strlen($body), $id, 0, self::OP_MSG) . $body, $this->timeout);

        while (true) {
            [$length, , $to, $op] = self::words($this->read(16), 4);
            $message = $this->read($length - 16);
            if ($to !== $id) {
                continue; // a reply to an abandoned request
            }
            if ($op !== self::OP_MSG || ($message[4] ?? '') !== "\x00") {
                throw new MongoException('Unexpected reply from MongoDB.');
            }
            if ((self::words($message, 1)[0] & 1) === 1) {
                $length = self::words(substr($message, 5), 1)[0]; // a checksum follows the document

                return Bson::decode(substr($message, 5, $length));
            }

            return Bson::decode(substr($message, 5));
        }
    }

    /** @return list<int> the first $count little-endian unsigned 32-bit words */
    private static function words(string $bytes, int $count): array
    {
        $words = array_values(array_filter(unpack('V' . $count, $bytes) ?: [], \is_int(...)));
        if (\count($words) !== $count) {
            throw new MongoException('Truncated reply from MongoDB.');
        }

        return $words;
    }

    private function read(int $length): string
    {
        \assert($this->socket !== null);
        while (\strlen($this->buffer) < $length) {
            $chunk = @fread($this->socket, max(65536, $length - \strlen($this->buffer)));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                $this->close();
                throw new MongoException('MongoDB closed the connection.');
            }
            if ($chunk === '') {
                Io::readable($this->socket, $this->timeout);
                continue;
            }
            $this->buffer .= $chunk;
        }
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $data;
    }

    /** @return list<array{0: string, 1: int}> */
    private static function srv(string $host): array
    {
        $records = @dns_get_record('_mongodb._tcp.' . $host, \DNS_SRV);
        if (!\is_array($records) || $records === []) {
            throw new MongoException("No SRV records for _mongodb._tcp.$host.");
        }
        usort($records, static fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_map(static fn (array $r): array => [\is_string($r['target'] ?? null) ? $r['target'] : $host, \is_int($r['port'] ?? null) ? $r['port'] : 27017], $records);
    }

    private function lock(): void
    {
        if ($this->busy) {
            $suspension = Suspension::create();
            $this->waiting[] = $suspension;
            $suspension->suspend();
        }
        $this->busy = true;
    }

    private function unlock(): void
    {
        $next = array_shift($this->waiting);
        if ($next === null) {
            $this->busy = false;
        } else {
            $next->resume();
        }
    }
}
