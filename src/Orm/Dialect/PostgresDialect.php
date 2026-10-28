<?php

declare(strict_types=1);

namespace Keel\Orm\Dialect;

final class PostgresDialect extends AnsiDialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function paginate(string $sql, ?int $limit, ?int $offset): string
    {
        return $sql . ($limit === null ? '' : ' LIMIT ' . $limit) . ($offset ? ' OFFSET ' . $offset : '');
    }

    public function bool(bool $value): bool
    {
        return $value;
    }

    public function upsert(string $table, array $columns, int $rows, array $conflict, array $update): string
    {
        return OnConflict::sql($this, $table, $columns, $rows, $conflict, $update);
    }

    public function generatedKeys(): string
    {
        return 'returning';
    }

    public function columnType(array $column): string
    {
        return match ($column['type']) {
            'text' => 'TEXT',
            'bool' => 'BOOLEAN',
            'datetime' => 'TIMESTAMP(6)',
            'json' => 'JSONB',
            default => parent::columnType($column),
        };
    }

    public function columnsQuery(): string
    {
        return 'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?';
    }

    public function tablesQuery(): string
    {
        return 'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema()';
    }

    /** @return positive-int */
    public function maxInList(): int
    {
        return 10_000;
    }
}
