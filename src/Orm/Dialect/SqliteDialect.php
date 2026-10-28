<?php

declare(strict_types=1);

namespace Keel\Orm\Dialect;

final class SqliteDialect extends AnsiDialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function paginate(string $sql, ?int $limit, ?int $offset): string
    {
        return $sql . ($limit === null ? ($offset ? ' LIMIT -1' : '') : ' LIMIT ' . $limit) . ($offset ? ' OFFSET ' . $offset : '');
    }

    public function upsert(string $table, array $columns, int $rows, array $conflict, array $update): string
    {
        return OnConflict::sql($this, $table, $columns, $rows, $conflict, $update);
    }

    public function columnType(array $column): string
    {
        return match ($column['type']) {
            'string', 'text', 'datetime', 'json', 'decimal' => 'TEXT',
            'int', 'bool' => 'INTEGER',
            'float' => 'REAL',
            'enum' => ($column['enum_int'] ?? false) ? 'INTEGER' : 'TEXT',
            default => parent::columnType($column),
        };
    }

    public function autoIncrement(string $column): string
    {
        return $this->quote($column) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function forUpdate(): string
    {
        return ''; // SQLite locks the whole database for writes
    }

    public function columnsQuery(): string
    {
        return 'SELECT name FROM pragma_table_info(?)';
    }

    public function tablesQuery(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
    }
}
