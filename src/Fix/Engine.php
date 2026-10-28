<?php

declare(strict_types=1);

namespace Keel\Fix;

use Keel\Container\Container;
use Keel\Fix\Store\FileSessionStore;
use Keel\Fix\Store\InMemorySessionStore;
use Keel\Fix\Store\SessionStore;
use Psr\Log\LoggerInterface;

/**
 * The FIX process (`keel fix:run`): a single-threaded reactor over non-blocking sockets
 * that accepts and initiates sessions, drives their timers and dispatches application
 * messages to handlers. Initiators reconnect with backoff; SIGTERM logs every session
 * out before exiting.
 *
 * TLS: terminate it in front of the engine (stunnel, HAProxy), as most FIX venues expect.
 */
final class Engine
{
    /** @var array<string, Session> */
    private array $sessions = [];

    /** @var array<int, resource> listening sockets by port */
    private array $servers = [];

    /** @var array<int, array{socket: resource, parser: Parser, out: string, session: ?string, port: int, connecting: bool}> */
    private array $connections = [];

    /** @var array<string, float> initiator id => next connect attempt */
    private array $reconnectAt = [];

    private bool $stopping = false;

    /**
     * @param list<SessionSettings> $settings
     * @param string|null $storeDirectory null keeps sequence numbers in memory (tests)
     */
    public function __construct(
        private readonly Container $container,
        array $settings,
        private readonly ?string $storeDirectory,
        private readonly LoggerInterface $logger,
        private readonly string $listenHost = '127.0.0.1',
        private readonly float $reconnectSeconds = 5.0,
    ) {
        $gateway = $container->get(FixGateway::class);
        $application = new Application($container);
        foreach ($settings as $s) {
            $session = new Session($s, $this->store($s), function (string $bytes) use ($s): void {
                $this->write($s->id, $bytes);
            }, \Closure::fromCallable($application), $logger);
            $this->sessions[$s->id] = $session;
            $gateway->attach($session);
            if ($s->role === 'initiator') {
                $this->reconnectAt[$s->id] = 0.0;
            }
        }
    }

    /**
     * Runs until stop() (or a signal), then logs out and closes everything. With $seconds it
     * returns after that long and keeps connections open, so tests can interleave actions.
     */
    public function run(?float $seconds = null): void
    {
        $this->listen();
        $deadline = $seconds === null ? null : microtime(true) + $seconds;
        $lastTick = 0.0;
        while (true) {
            $now = microtime(true);
            if ($deadline !== null && $now >= $deadline) {
                break;
            }
            if ($this->stopping) {
                $this->shutdown();
                break;
            }
            $this->connectInitiators($now);
            $this->poll(0.05);
            if ($now - $lastTick >= 0.2) {
                foreach ($this->sessions as $session) {
                    $session->tick($now);
                }
                $lastTick = $now;
            }
            $this->reapDisconnected($now);
        }
        if ($deadline !== null && !$this->stopping) {
            return; // a bounded run (tests): keep connections for the next one
        }
        $this->flush(1.0);
        foreach (array_keys($this->connections) as $id) {
            $this->close($id, microtime(true));
        }
        foreach ($this->servers as $server) {
            fclose($server);
        }
        $this->servers = [];
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function session(string $id): Session
    {
        return $this->sessions[$id] ?? throw new FixError("No FIX session \"$id\".");
    }

    private function listen(): void
    {
        foreach ($this->sessions as $session) {
            $port = $session->settings->port;
            if ($session->settings->role !== 'acceptor' || isset($this->servers[$port])) {
                continue;
            }
            $server = @stream_socket_server("tcp://{$this->listenHost}:$port", $errno, $error);
            if ($server === false) {
                throw new \RuntimeException("Cannot listen for FIX on {$this->listenHost}:$port: $error");
            }
            stream_set_blocking($server, false);
            $this->servers[$port] = $server;
            $this->logger->info('FIX acceptor listening on {host}:{port}.', ['host' => $this->listenHost, 'port' => $port]);
        }
    }

    private function connectInitiators(float $now): void
    {
        foreach ($this->reconnectAt as $id => $at) {
            if ($at > $now || $this->connectionOf($id) !== null) {
                continue;
            }
            $s = $this->sessions[$id]->settings;
            $socket = @stream_socket_client("tcp://{$s->host}:{$s->port}", $errno, $error, 5, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket === false) {
                $this->logger->warning('FIX {session}: cannot connect to {host}:{port}: {error}', ['session' => $id, 'host' => $s->host, 'port' => $s->port, 'error' => $error]);
                $this->reconnectAt[$id] = $now + $this->reconnectSeconds;
                continue;
            }
            stream_set_blocking($socket, false);
            $this->connections[(int) $socket] = ['socket' => $socket, 'parser' => new Parser(), 'out' => '', 'session' => $id, 'port' => $s->port, 'connecting' => true];
            $this->reconnectAt[$id] = $now + $this->reconnectSeconds;
        }
    }

    private function poll(float $timeout): void
    {
        $read = array_values($this->servers);
        $write = [];
        foreach ($this->connections as $c) {
            $read[] = $c['socket'];
            if ($c['out'] !== '' || $c['connecting']) {
                $write[] = $c['socket'];
            }
        }
        if ($read === [] && $write === []) {
            usleep((int) ($timeout * 1e6));

            return;
        }
        $except = [];
        if (@stream_select($read, $write, $except, 0, (int) ($timeout * 1e6)) === false) {
            return;
        }
        $now = microtime(true);
        foreach ($write as $socket) {
            $id = (int) $socket;
            if (!isset($this->connections[$id])) {
                continue;
            }
            if ($this->connections[$id]['connecting']) {
                $this->connections[$id]['connecting'] = false;
                if (stream_socket_get_name($socket, true) === false) {
                    $this->close($id, $now); // connection refused

                    continue;
                }
                $session = $this->connections[$id]['session'];
                $this->logger->info('FIX {session}: connected.', ['session' => $session]);
                if ($session !== null) {
                    $this->sessions[$session]->connected($now);
                }
            }
            $this->drain($id);
        }
        foreach ($read as $socket) {
            if (\in_array($socket, $this->servers, true)) {
                $client = @stream_socket_accept($socket, 0);
                if ($client !== false) {
                    stream_set_blocking($client, false);
                    $port = (int) array_search($socket, $this->servers, true);
                    $this->connections[(int) $client] = ['socket' => $client, 'parser' => new Parser(), 'out' => '', 'session' => null, 'port' => $port, 'connecting' => false];
                }
                continue;
            }
            $this->readFrom((int) $socket, $now);
        }
    }

    private function readFrom(int $id, float $now): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        $bytes = @fread($connection['socket'], 65536);
        if ($bytes === '' || $bytes === false) {
            if (feof($connection['socket'])) {
                $this->close($id, $now);
            }

            return;
        }
        try {
            $messages = $connection['parser']->feed($bytes);
        } catch (FixError $e) {
            $this->logger->error('FIX: {error}; closing the connection.', ['error' => $e->getMessage()]);
            $this->close($id, $now);

            return;
        }
        foreach ($messages as $raw) {
            $sessionId = $this->connections[$id]['session'] ?? null;
            if ($sessionId === null && ($sessionId = $this->identify($id, $raw, $now)) === null) {
                return;
            }
            $this->sessions[$sessionId]->receive($raw, $now);
        }
    }

    /** Binds an accepted connection to the acceptor session named by its first message's CompIDs. */
    private function identify(int $id, string $raw, float $now): ?string
    {
        try {
            $message = Codec::decode($raw);
        } catch (FixError) {
            $this->close($id, $now);

            return null;
        }
        foreach ($this->sessions as $sessionId => $session) {
            $s = $session->settings;
            if ($s->role === 'acceptor' && $s->port === $this->connections[$id]['port']
                && $message->get(Tag::SENDER_COMP_ID) === $s->target && $message->get(Tag::TARGET_COMP_ID) === $s->sender) {
                if ($this->connectionOf($sessionId) !== null) {
                    $this->logger->warning('FIX {session}: second connection refused; the session is already connected.', ['session' => $sessionId]);
                    break;
                }
                $this->connections[$id]['session'] = $sessionId;
                $session->connected($now);

                return $sessionId;
            }
        }
        $this->logger->warning('FIX: no acceptor session for {sender} -> {target}; closing.', ['sender' => $message->get(Tag::SENDER_COMP_ID), 'target' => $message->get(Tag::TARGET_COMP_ID)]);
        $this->close($id, $now);

        return null;
    }

    private function write(string $sessionId, string $bytes): void
    {
        $id = $this->connectionOf($sessionId);
        if ($id === null) {
            return; // stored for resend; delivered after the next logon if the counterparty asks
        }
        $this->connections[$id]['out'] .= $bytes;
        $this->drain($id);
    }

    private function drain(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null || $connection['out'] === '' || $connection['connecting']) {
            return;
        }
        $written = @fwrite($connection['socket'], $connection['out']);
        if ($written === false) {
            return;
        }
        $this->connections[$id]['out'] = (string) substr($connection['out'], $written);
    }

    private function reapDisconnected(float $now): void
    {
        foreach ($this->sessions as $id => $session) {
            if ($session->shouldDisconnect && ($connection = $this->connectionOf($id)) !== null) {
                $this->flush(0.5, $connection);
                $this->close($connection, $now);
            }
        }
    }

    private function close(int $id, float $now): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        @fclose($connection['socket']);
        unset($this->connections[$id]);
        if ($connection['session'] !== null) {
            $this->sessions[$connection['session']]->disconnected();
            $this->sessions[$connection['session']]->shouldDisconnect = false;
            if (isset($this->reconnectAt[$connection['session']])) {
                $this->reconnectAt[$connection['session']] = $now + $this->reconnectSeconds;
            }
            $this->logger->info('FIX {session}: disconnected.', ['session' => $connection['session']]);
        }
    }

    private function shutdown(): void
    {
        $now = microtime(true);
        foreach ($this->sessions as $session) {
            if ($session->isLoggedOn()) {
                $session->logout('Shutting down', $now);
            }
        }
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && array_filter($this->sessions, static fn (Session $s): bool => $s->state === Session::LOGOUT_SENT) !== []) {
            $this->poll(0.05);
        }
    }

    private function flush(float $seconds, ?int $only = null): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $pending = array_filter($this->connections, static fn (array $c, int $id): bool => $c['out'] !== '' && ($only === null || $id === $only), \ARRAY_FILTER_USE_BOTH);
            if ($pending === []) {
                return;
            }
            foreach (array_keys($pending) as $id) {
                $this->drain($id);
            }
            usleep(5000);
        }
    }

    private function connectionOf(string $sessionId): ?int
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection['session'] === $sessionId) {
                return $id;
            }
        }

        return null;
    }

    private function store(SessionSettings $settings): SessionStore
    {
        return $this->storeDirectory === null
            ? new InMemorySessionStore()
            : new FileSessionStore($this->storeDirectory . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $settings->id));
    }
}
