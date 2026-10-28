<?php

declare(strict_types=1);

namespace Keel\Orm\Dialect;

/** MySQL 8 and MariaDB 10.5+. */
final class MySqlDialect extends AnsiDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function paginate(string $sql, ?int $limit, ?int $offset): string
    {
        return $sql . ($limit === null ? ($offset ? ' LIMIT 18446744073709551615' : '') : ' LIMIT ' . $limit) . ($offset ? ' OFFSET ' . $offset : '');
    }

    public function upsert(string $table, array $columns, int $rows, array $conflict, array $update): string
    {
        $row = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        $set = $update === []
            ? $this->quote($conflict[0]) . ' = ' . $this->quote($conflict[0])
            : implode(', ', array_map(fn (string $c): string => $this->quote($c) . ' = VALUES(' . $this->quote($c) . ')', $update));

        return 'INSERT INTO ' . $this->quote($table) . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES '
            . implode(', ', array_fill(0, $rows, $row)) . ' ON DUPLICATE KEY UPDATE ' . $set;
    }

    public function columnType(array $column): string
    {
        return match ($column['type']) {
            'text' => 'LONGTEXT',
            'float' => 'DOUBLE',
            'bool' => 'TINYINT(1)',
            'datetime' => 'DATETIME(6)',
            'json' => 'JSON',
            default => parent::columnType($column),
        };
    }

    public function autoIncrement(string $column): string
    {
        return $this->quote($column) . ' BIGINT AUTO_INCREMENT PRIMARY KEY';
    }

    public function columnsQuery(): string
    {
        return 'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?';
    }

    public function tablesQuery(): string
    {
        return 'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()';
    }

    /** @return positive-int */
    public function maxInList(): int
    {
        return 10_000;
    }
}
