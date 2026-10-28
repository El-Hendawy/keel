<?php

declare(strict_types=1);

namespace Keel\Orm\Dialect;

/** SQL Server 2012+ and Azure SQL (pdo_sqlsrv or pdo_dblib). */
final class SqlServerDialect extends AnsiDialect
{
    public function name(): string
    {
        return 'sqlsrv';
    }

    public function quote(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    public function paginate(string $sql, ?int $limit, ?int $offset): string
    {
        if ($limit === null && !$offset) {
            return $sql;
        }
        if (stripos($sql, ' ORDER BY ') === false) {
            $sql .= ' ORDER BY (SELECT NULL)'; // OFFSET/FETCH requires an ORDER BY
        }
        $sql .= ' OFFSET ' . ($offset ?? 0) . ' ROWS';

        return $limit === null ? $sql : $sql . ' FETCH NEXT ' . $limit . ' ROWS ONLY';
    }

    public function generatedKeys(): string
    {
        return 'output';
    }

    public function columnType(array $column): string
    {
        return match ($column['type']) {
            'string' => 'NVARCHAR(' . ($column['length'] ?? 255) . ')',
            'text', 'json' => 'NVARCHAR(MAX)',
            'float' => 'FLOAT',
            'bool' => 'BIT',
            'datetime' => 'DATETIME2(6)',
            default => parent::columnType($column),
        };
    }

    public function autoIncrement(string $column): string
    {
        return $this->quote($column) . ' BIGINT IDENTITY(1,1) PRIMARY KEY';
    }

    public function forUpdate(): string
    {
        return ''; // use WITH (UPDLOCK) hints; plain SELECT ... FOR UPDATE does not exist
    }

    public function upsert(string $table, array $columns, int $rows, array $conflict, array $update): string
    {
        return parent::upsert($table, $columns, $rows, $conflict, $update) . ';'; // MERGE must end with a semicolon
    }

    public function tablesQuery(): string
    {
        return "SELECT table_name AS name FROM information_schema.tables WHERE table_type = 'BASE TABLE'";
    }

    /** @return positive-int */
    public function maxInList(): int
    {
        return 2000; // 2100 parameters per statement
    }

    protected function dual(): string
    {
        return '';
    }
}
