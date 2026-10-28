<?php

declare(strict_types=1);

namespace Keel\Orm\Dialect;

/**
 * What differs between databases: identifier quoting, pagination, booleans, upserts,
 * generated keys and column types. One implementation per engine; `AnsiDialect` (SQL:2008)
 * covers any other PDO driver.
 */
interface Dialect
{
    public function name(): string;

    public function quote(string $identifier): string;

    /** Appends pagination to a SELECT that already has its ORDER BY (when the engine needs one). */
    public function paginate(string $sql, ?int $limit, ?int $offset): string;

    public function bool(bool $value): bool|int;

    /**
     * @param list<string> $columns
     * @param list<string> $conflict columns of the unique key
     * @param list<string> $update columns to overwrite on conflict
     */
    public function upsert(string $table, array $columns, int $rows, array $conflict, array $update): string;

    /** How to read back an auto-increment id: 'returning' (INSERT ... RETURNING), 'output' (SQL Server) or 'last_insert_id'. */
    public function generatedKeys(): string;

    /**
     * DDL type for a column.
     *
     * @param array{type: string, nullable: bool, length?: int, precision?: int, scale?: int, enum_int?: bool} $column
     */
    public function columnType(array $column): string;

    /** The primary key column definition for an auto-increment id. */
    public function autoIncrement(string $column): string;

    public function forUpdate(): string;

    /** SQL listing the columns of a table, returning one row per column with a `name` field. */
    public function columnsQuery(): string;

    /** SQL listing tables, one row per table with a `name` field. */
    public function tablesQuery(): string;

    /**
     * Maximum number of bound values in an IN list before the ORM splits the query.
     *
     * @return positive-int
     */
    public function maxInList(): int;
}
