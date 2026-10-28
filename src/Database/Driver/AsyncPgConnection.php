<?php

declare(strict_types=1);

namespace Keel\Database\Driver;

use Keel\Async\Io;
use Keel\Async\Suspension;
use Keel\Database\ResultTooLarge;

/**
 * Non-blocking PostgreSQL over ext-pgsql: connecting and every query suspend only the
 * calling fiber, so a Keel server process keeps serving other requests while it waits.
 * Outside a fiber it drives the event loop and behaves like a blocking driver.
 *
 * Statements are prepared once per connection. Values come back typed like PDO's:
 * integers, booleans and floats as PHP scalars, numerics as strings.
 */
final class AsyncPgConnection implements Connection
{
    private bool $busy = false;

    /** @var list<Suspension> */
    private array $waiting = [];

    /** @var array<string, string> sql => prepared statement name */
    private array $prepared = [];

    private int $statements = 0;

    /** @param resource $socket */
    private function __construct(private \PgSql\Connection $pg, private $socket, private readonly float $timeout)
    {
    }

    /** @param string $dsn a PDO-style `pgsql:host=...;dbname=...` DSN */
    public static function connect(string $dsn, string $username = '', string $password = '', int $timeoutMs = 5000): self
    {
        if (!\function_exists('pg_connect')) {
            throw new \RuntimeException('The pgsql-async driver needs the pgsql extension (ext-pgsql).');
        }
        $timeout = max(1, $timeoutMs) / 1000;
        $pg = @pg_connect(self::conninfo($dsn, $username, $password, $timeoutMs), \PGSQL_CONNECT_ASYNC | \PGSQL_CONNECT_FORCE_NEW);
        if (!$pg instanceof \PgSql\Connection) {
            throw new QueryFailed('Cannot start a PostgreSQL connection.', '08001');
        }
        $socket = pg_socket($pg);
        if ($socket === false) {
            throw new QueryFailed('Cannot read the PostgreSQL socket.', '08001');
        }
        while (true) {
            $state = pg_connect_poll($pg);
            if ($state === \PGSQL_POLLING_OK) {
                break;
            }
            if ($state === \PGSQL_POLLING_FAILED) {
                throw new QueryFailed('PostgreSQL connection failed: ' . trim(pg_last_error($pg)), '08001');
            }
            $state === \PGSQL_POLLING_WRITING ? Io::writable($socket, $timeout) : Io::readable($socket, $timeout);
        }
        $connection = new self($pg, $socket, $timeout);
        $connection->exec('SET statement_timeout = ' . max(1, $timeoutMs));

        return $connection;
    }

    public function query(string $sql, array $params = [], int $maxRows = 0): Result
    {
        [$sql, $ordered] = self::positional($sql, $params);
        $this->lock();
        try {
            $name = $this->prepared[$sql] ?? null;
            if ($name === null) {
                $name = 'keel_' . ++$this->statements;
                $this->send(pg_send_prepare($this->pg, $name, $sql));
                $this->result($sql);
                $this->prepared[$sql] = $name;
            }
            $this->send(pg_send_execute($this->pg, $name, array_map(self::param(...), $ordered)));
            $result = $this->result($sql);

            return $this->convert($result, $sql, $maxRows);
        } finally {
            $this->unlock();
        }
    }

    public function begin(): void
    {
        $this->exec('BEGIN');
    }

    public function commit(): void
    {
        $this->exec('COMMIT');
    }

    public function rollBack(): void
    {
        $this->exec('ROLLBACK');
    }

    public function exec(string $sql): void
    {
        $this->lock();
        try {
            $this->send(pg_send_query($this->pg, $sql));
            $this->result($sql);
        } finally {
            $this->unlock();
        }
    }

    public function inTransaction(): bool
    {
        return pg_transaction_status($this->pg) !== \PGSQL_TRANSACTION_IDLE;
    }

    public function lastInsertId(): string
    {
        $value = $this->query('SELECT lastval() AS id')->rows[0]['id'] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function isHealthy(): bool
    {
        return pg_connection_status($this->pg) === \PGSQL_CONNECTION_OK;
    }

    public function close(): void
    {
        @pg_close($this->pg);
    }

    private function send(int|bool $sent): void
    {
        if ($sent === false) {
            throw new QueryFailed('Sending to PostgreSQL failed: ' . trim(pg_last_error($this->pg)), '08006');
        }
        // pg_flush(): true when everything is sent, 0 when data is still pending, false on failure.
        while (($flushed = pg_flush($this->pg)) !== true) {
            if ($flushed === false) {
                throw new QueryFailed('Sending to PostgreSQL failed: ' . trim(pg_last_error($this->pg)), '08006');
            }
            Io::writable($this->socket, $this->timeout);
        }
    }

    /** Waits for the reply without blocking the process and returns the last result. */
    private function result(string $sql): \PgSql\Result
    {
        while (true) {
            if (!pg_consume_input($this->pg)) {
                throw new QueryFailed('Reading from PostgreSQL failed: ' . trim(pg_last_error($this->pg)), '08006');
            }
            if (!pg_connection_busy($this->pg)) {
                break;
            }
            Io::readable($this->socket, $this->timeout);
        }
        $last = null;
        $error = null;
        while (($result = pg_get_result($this->pg)) !== false) {
            if (pg_result_status($result) === \PGSQL_FATAL_ERROR) {
                $error ??= new QueryFailed(trim((string) pg_result_error($result)) . " SQL: $sql", (string) (pg_result_error_field($result, \PGSQL_DIAG_SQLSTATE) ?: 'HY000'));
            }
            $last = $result;
        }
        if ($error !== null) {
            throw $error;
        }

        return $last ?? throw new QueryFailed("PostgreSQL returned no result. SQL: $sql", 'HY000');
    }

    private function convert(\PgSql\Result $result, string $sql, int $maxRows): Result
    {
        $count = pg_num_rows($result);
        if ($maxRows > 0 && $count > $maxRows) {
            throw new ResultTooLarge("Query returned more than $maxRows rows. Paginate, or raise database.max_rows deliberately: $sql");
        }
        $types = [];
        for ($i = 0, $n = pg_num_fields($result); $i < $n; $i++) {
            $types[pg_field_name($result, $i)] = pg_field_type($result, $i);
        }
        $rows = [];
        foreach ($count > 0 ? pg_fetch_all($result, \PGSQL_ASSOC) : [] as $raw) {
            $row = [];
            foreach ($raw as $column => $value) {
                $row[(string) $column] = !\is_string($value) ? null : match ($types[$column] ?? '') {
                    'int2', 'int4' => (int) $value,
                    'int8', 'oid' => filter_var($value, \FILTER_VALIDATE_INT) !== false ? (int) $value : $value,
                    'bool' => $value === 't',
                    'float4', 'float8' => (float) $value,
                    default => $value,
                };
            }
            $rows[] = $row;
        }

        return new Result($rows, pg_affected_rows($result));
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

    private static function param(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            \is_bool($value) => $value ? 't' : 'f',
            \is_scalar($value) => (string) $value,
            default => throw new \InvalidArgumentException('Query parameters must be scalars or null.'),
        };
    }

    /**
     * Rewrites `?` and `:name` placeholders to PostgreSQL's `$1, $2, ...` (outside quotes,
     * comments and `::` casts) and orders the values to match.
     *
     * @param array<int|string, scalar|null> $params
     * @return array{0: string, 1: list<scalar|null>}
     */
    public static function positional(string $sql, array $params): array
    {
        $out = '';
        $ordered = [];
        $named = [];
        $index = 0;
        $length = \strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"') {
                $end = $i + 1;
                while ($end < $length && ($sql[$end] !== $c || ($sql[$end + 1] ?? '') === $c)) {
                    $end += $sql[$end] === $c ? 2 : 1;
                }
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $out .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($c === '?') {
                $ordered[] = $params[$index] ?? null;
                $out .= '$' . \count($ordered);
                $index++;
                continue;
            }
            if ($c === ':' && ($sql[$i + 1] ?? '') === ':') {
                $out .= '::';
                $i++;
                continue;
            }
            if ($c === ':' && preg_match('/\G:([A-Za-z_]\w*)/', $sql, $m, 0, $i) === 1) {
                if (!isset($named[$m[1]])) {
                    $ordered[] = $params[$m[1]] ?? $params[':' . $m[1]] ?? null;
                    $named[$m[1]] = \count($ordered);
                }
                $out .= '$' . $named[$m[1]];
                $i += \strlen($m[0]) - 1;
                continue;
            }
            $out .= $c;
        }

        return [$out, $ordered];
    }

    private static function conninfo(string $dsn, string $username, string $password, int $timeoutMs): string
    {
        $body = preg_replace('/^pgsql(?:-async)?:/', '', $dsn) ?? '';
        $parts = [];
        foreach (array_filter(explode(';', $body)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }
        if ($username !== '') {
            $parts['user'] ??= $username;
        }
        if ($password !== '') {
            $parts['password'] ??= $password;
        }
        $parts['connect_timeout'] ??= (string) max(1, intdiv($timeoutMs, 1000));

        return implode(' ', array_map(
            static fn (string $k, string $v): string => $k . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'",
            array_keys($parts),
            $parts,
        ));
    }
}
