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
 */
#[Singleton]
final class ConnectionManager implements Disposable
{
    private ?\PDO $writer = null;
    private ?\PDO $reader = null;

    public function __construct(
        #[Config('database.dsn')] private readonly string $dsn,
        #[Config('database.replica_dsn')] private readonly string $replicaDsn = '',
        #[Config('database.username')] private readonly string $username = '',
        #[Config('database.password')] private readonly string $password = '',
        #[Config('database.timeout_ms')] private readonly int $timeoutMs = 5000,
    ) {
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

    public function dispose(): void
    {
        $this->writer = null;
        $this->reader = null;
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
            default => null,
        };

        return $pdo;
    }
}
