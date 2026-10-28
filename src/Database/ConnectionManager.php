<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Async\Suspension;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Container\Disposable;
use Keel\Database\Driver\AsyncPgConnection;
use Keel\Database\Driver\Connection;
use Keel\Database\Driver\PdoConnection;

/**
 * Owns this worker's database connections.
 *
 * - **Shared** (`database.pool_size = 0`, the default for FrankenPHP/RoadRunner/FPM workers,
 *   which serve one request at a time): one connection to the primary and one to the replica,
 *   reused by every request.
 * - **Pooled** (`database.pool_size > 0`, the default under `keel run`, which serves many
 *   requests at once): each scope leases its own connection, so concurrent requests never
 *   share a session or a transaction. When the pool is exhausted, callers wait (up to
 *   `database.pool_timeout_ms`) without blocking the process.
 *
 * A `pgsql-async:` DSN uses the non-blocking PostgreSQL driver; every other DSN uses PDO.
 * writer()/reader() still return plain PDO handles for tools and migrations.
 */
#[Singleton]
final class ConnectionManager implements Disposable
{
    private ?\PDO $writer = null;
    private ?\PDO $reader = null;

    /** @var array<string, Connection> shared connections in shared mode */
    private array $shared = [];

    /** @var array<string, list<Connection>> idle pooled connections per target */
    private array $idle = [];

    /** @var array<string, int> open pooled connections per target */
    private array $open = [];

    /** @var array<string, list<Suspension>> */
    private array $waiting = [];

    /** @var \WeakMap<Connection, string> */
    private \WeakMap $targets;

    public function __construct(
        #[Config('database.dsn')] private readonly string $dsn,
        #[Config('database.replica_dsn')] private readonly string $replicaDsn = '',
        #[Config('database.username')] private readonly string $username = '',
        #[Config('database.password')] private readonly string $password = '',
        #[Config('database.timeout_ms')] private readonly int $timeoutMs = 5000,
        #[Config('database.statement_cache')] private readonly int $statementCache = 256,
        #[Config('database.pool_size')] private readonly int $poolSize = 0,
        #[Config('database.pool_timeout_ms')] private readonly int $poolTimeoutMs = 5000,
    ) {
        $this->targets = new \WeakMap();
    }

    /** A plain PDO handle to the primary (migrations, tools, background workers). */
    public function writer(): \PDO
    {
        return $this->writer ??= $this->pdo(self::pdoDsn($this->dsn));
    }

    public function reader(): \PDO
    {
        if ($this->replicaDsn === '') {
            return $this->writer();
        }

        return $this->reader ??= $this->pdo(self::pdoDsn($this->replicaDsn));
    }

    public function isPooled(): bool
    {
        return $this->poolSize > 0;
    }

    public function isAsync(): bool
    {
        return str_starts_with($this->dsn, 'pgsql-async:');
    }

    /** Leases a connection to the primary (or the replica). Give it back with release(). */
    public function acquire(bool $replica = false): Connection
    {
        $target = $replica && $this->replicaDsn !== '' ? 'reader' : 'writer';
        if (!$this->isPooled()) {
            return $this->shared[$target] ??= $this->open($target);
        }
        while (true) {
            $this->idle[$target] ??= [];
            $connection = array_pop($this->idle[$target]);
            if ($connection !== null) {
                return $connection;
            }
            if (($this->open[$target] ?? 0) < $this->poolSize) {
                $this->open[$target] = ($this->open[$target] ?? 0) + 1;
                try {
                    $connection = $this->open($target);
                } catch (\Throwable $e) {
                    $this->open[$target]--;
                    throw $e;
                }
                $this->targets[$connection] = $target;

                return $connection;
            }
            $this->wait($target);
        }
    }

    public function release(Connection $connection): void
    {
        $target = $this->targets[$connection] ?? null;
        if ($target === null) {
            return; // shared connection
        }
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack(); // never hand a transaction to the next request
            }
        } catch (\Throwable) {
            $connection->close();
            $this->open[$target]--;
            $this->wake($target);

            return;
        }
        $this->idle[$target][] = $connection;
        $this->wake($target);
    }

    /**
     * Runs $work on a leased connection (for singletons that need one statement at a time).
     *
     * @template T
     * @param \Closure(Connection): T $work
     * @return T
     */
    public function with(\Closure $work, bool $replica = false): mixed
    {
        $connection = $this->acquire($replica);
        try {
            return $work($connection);
        } finally {
            $this->release($connection);
        }
    }

    public function dispose(): void
    {
        foreach ([...array_values($this->shared), ...array_merge(...array_values($this->idle))] as $connection) {
            $connection->close();
        }
        $this->shared = $this->idle = $this->open = [];
        $this->writer = null;
        $this->reader = null;
    }

    private function open(string $target): Connection
    {
        $dsn = $target === 'reader' ? $this->replicaDsn : $this->dsn;
        if (str_starts_with($dsn, 'pgsql-async:')) {
            return AsyncPgConnection::connect($dsn, $this->username, $this->password, $this->timeoutMs);
        }
        if (!$this->isPooled()) {
            return new PdoConnection($target === 'reader' ? $this->reader() : $this->writer(), $this->statementCache);
        }

        return new PdoConnection($this->pdo($dsn), $this->statementCache);
    }

    private function wait(string $target): void
    {
        $suspension = Suspension::create();
        $this->waiting[$target][] = $suspension;
        $loop = \Keel\Async\Loop::get();
        $message = "No database connection became free within {$this->poolTimeoutMs} ms (database.pool_size = {$this->poolSize}).";
        $timer = $loop->delay($this->poolTimeoutMs / 1000, static fn () => $suspension->throw(new \RuntimeException($message)));
        try {
            $suspension->suspend();
        } finally {
            $loop->cancelTimer($timer);
            $this->waiting[$target] = array_values(array_filter($this->waiting[$target], static fn (Suspension $s): bool => $s !== $suspension));
        }
    }

    private function wake(string $target): void
    {
        $waiting = $this->waiting[$target] ?? [];
        $next = array_shift($waiting);
        $this->waiting[$target] = $waiting;
        $next?->resume();
    }

    private static function pdoDsn(string $dsn): string
    {
        return str_starts_with($dsn, 'pgsql-async:') ? 'pgsql:' . substr($dsn, \strlen('pgsql-async:')) : $dsn;
    }

    private function pdo(string $dsn): \PDO
    {
        $pdo = new \PDO($dsn, $this->username !== '' ? $this->username : null, $this->password !== '' ? $this->password : null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \PDO::ATTR_TIMEOUT => max(1, intdiv($this->timeoutMs, 1000)),
        ]);

        match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => $pdo->exec('SET SESSION max_execution_time = ' . $this->timeoutMs),
            'pgsql' => $pdo->exec('SET statement_timeout = ' . $this->timeoutMs),
            'sqlite' => $pdo->exec('PRAGMA busy_timeout = ' . $this->timeoutMs . '; PRAGMA foreign_keys = ON'),
            // Date-times travel as 'Y-m-d H:i:s.u' strings; teach Oracle that format.
            'oci' => $pdo->exec("ALTER SESSION SET NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF6' NLS_NUMERIC_CHARACTERS = '.,'"),
            'sqlsrv' => $pdo->exec('SET LOCK_TIMEOUT ' . $this->timeoutMs),
            default => null,
        };

        return $pdo;
    }
}
