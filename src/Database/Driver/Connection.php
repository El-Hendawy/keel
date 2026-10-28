<?php

declare(strict_types=1);

namespace Keel\Database\Driver;

/** One database session. PdoConnection (any PDO driver) or AsyncPgConnection (non-blocking PostgreSQL). */
interface Connection
{
    /**
     * @param array<int|string, scalar|null> $params positional (0-based) or named (`:name`)
     * @param int $maxRows throw ResultTooLarge beyond this many rows (0: no limit)
     */
    public function query(string $sql, array $params = [], int $maxRows = 0): Result;

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    /** Runs a statement without parameters or results (SAVEPOINT, SET ...). */
    public function exec(string $sql): void;

    public function inTransaction(): bool;

    public function lastInsertId(): string;

    /** The PDO-style driver name: pgsql, mysql, sqlite, ... */
    public function driver(): string;

    public function close(): void;
}
