<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Container\Disposable;

/**
 * Owns this worker's database connections: one to the primary and, if configured, one
 * to a replica. Connections are opened lazily, reused across requests, and every
 * session gets a statement timeout.
 *
 * Prepared statements are cached per connection (SQL is a literal string, so the set is
 * small and fixed). On PostgreSQL and MySQL this saves a round trip per query.
 */
#[Singleton]
final class ConnectionManager implements Disposable
{
    private ?\PDO $writer = null;
    private ?\PDO $reader = null;

    /** @var \WeakMap<\PDO, array<string, \PDOStatement>> */
    private \WeakMap $statements;

    public function __construct(
        #[Config('database.dsn')] private readonly string $dsn,
        #[Config('database.replica_dsn')] private readonly string $replicaDsn = '',
        #[Config('database.username')] private readonly string $username = '',
        #[Config('database.password')] private readonly string $password = '',
        #[Config('database.timeout_ms')] private readonly int $timeoutMs = 5000,
        #[Config('database.statement_cache')] private readonly int $statementCache = 256,
    ) {
        $this->statements = new \WeakMap();
    }

    public function writer(): \PDO
    {
        return $this->writer ??= $this->connect($this->dsn);
    }

    public function reader(): \PDO
    {
        if ($this->replicaDsn === '') {
            return $this->writer();
        }

        return $this->reader ??= $this->connect($this->replicaDsn);
    }

    /** A prepared statement for $sql, reused while the connection lives. */
    public function prepare(\PDO $pdo, string $sql): \PDOStatement
    {
        $cache = $this->statements[$pdo] ?? [];
        if (isset($cache[$sql])) {
            return $cache[$sql];
        }
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException("Could not prepare: $sql");
        }
        if ($this->statementCache > 0) {
            if (\count($cache) >= $this->statementCache) {
                array_shift($cache); // oldest first; statement sets are small, so this is rare
            }
            $cache[$sql] = $statement;
            $this->statements[$pdo] = $cache;
        }

        return $statement;
    }

    public function dispose(): void
    {
        $this->writer = null;
        $this->reader = null;
        $this->statements = new \WeakMap();
    }

    private function connect(string $dsn): \PDO
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
