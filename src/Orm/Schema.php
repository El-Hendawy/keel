<?php

declare(strict_types=1);

namespace Keel\Orm;

use Keel\Orm\Dialect\Dialect;

/**
 * DDL from entity mappings: CREATE TABLE statements for a dialect, and a forward-only diff
 * against a live database (new tables, new columns, their indexes). Columns or tables that
 * exist only in the database are listed as comments, never dropped automatically.
 *
 * @phpstan-import-type Meta from EntityMap
 */
final readonly class Schema
{
    public function __construct(private EntityMap $map, private Dialect $dialect)
    {
    }

    /** @return list<string> */
    public function create(): array
    {
        $sql = [];
        foreach ($this->map->all() as $meta) {
            array_push($sql, ...$this->createTable($meta));
        }
        foreach ($this->pivots() as $pivot => [$local, $related]) {
            $sql[] = 'CREATE TABLE ' . $this->q($pivot) . ' (' . $this->q($local) . ' VARCHAR(64) NOT NULL, ' . $this->q($related) . ' VARCHAR(64) NOT NULL, PRIMARY KEY (' . $this->q($local) . ', ' . $this->q($related) . '))';
        }

        return $sql;
    }

    /**
     * Statements that bring a database up to the mappings, without dropping anything.
     *
     * @return list<string>
     */
    public function diff(\PDO $pdo): array
    {
        $tables = array_map(strtolower(...), $this->column($pdo, $this->dialect->tablesQuery(), []));
        $sql = [];
        foreach ($this->map->all() as $meta) {
            if (!\in_array(strtolower($meta['table']), $tables, true)) {
                array_push($sql, ...$this->createTable($meta));
                continue;
            }
            $existing = array_map(strtolower(...), $this->column($pdo, $this->dialect->columnsQuery(), [$meta['table']]));
            $mapped = [];
            foreach ($meta['columns'] as $property => $column) {
                $mapped[] = strtolower($column['column']);
                if (!\in_array(strtolower($column['column']), $existing, true)) {
                    $definition = $this->dialect->columnType($column) . ($column['nullable'] ? ' NULL' : ' NOT NULL' . $this->defaultFor($column));
                    $sql[] = 'ALTER TABLE ' . $this->q($meta['table']) . ' ADD ' . ($this->dialect->name() === 'sqlsrv' || $this->dialect->name() === 'oci' ? '' : 'COLUMN ') . $this->q($column['column']) . ' ' . $definition;
                    if (($column['unique'] ?? false) || ($column['index'] ?? false)) {
                        $sql[] = $this->index($meta['table'], [$column['column']], $column['unique'] ?? false);
                    }
                }
            }
            foreach (array_diff($existing, $mapped) as $unmapped) {
                $sql[] = "-- {$meta['table']}.$unmapped is not mapped by {$meta['class']}; drop it in a migration of its own if it is unused";
            }
        }
        foreach ($this->pivots() as $pivot => [$local, $related]) {
            if (!\in_array(strtolower($pivot), $tables, true)) {
                $sql[] = 'CREATE TABLE ' . $this->q($pivot) . ' (' . $this->q($local) . ' VARCHAR(64) NOT NULL, ' . $this->q($related) . ' VARCHAR(64) NOT NULL, PRIMARY KEY (' . $this->q($local) . ', ' . $this->q($related) . '))';
            }
        }

        return $sql;
    }

    /**
     * @param Meta $meta
     * @return list<string>
     */
    private function createTable(array $meta): array
    {
        $lines = [];
        $indexes = [];
        foreach ($meta['columns'] as $property => $column) {
            if ($property === $meta['id'] && $meta['idGenerated'] === 'auto') {
                $lines[] = $this->dialect->autoIncrement($column['column']);
                continue;
            }
            $line = $this->q($column['column']) . ' ' . $this->dialect->columnType($column) . ($column['nullable'] ? ' NULL' : ' NOT NULL');
            if ($property === $meta['id']) {
                $line .= ' PRIMARY KEY';
            }
            $lines[] = $line;
            if ($property !== $meta['id'] && (($column['unique'] ?? false) || ($column['index'] ?? false))) {
                $indexes[] = $this->index($meta['table'], [$column['column']], $column['unique'] ?? false);
            }
        }
        foreach ($meta['indexes'] as $index) {
            $indexes[] = $this->index($meta['table'], $index['columns'], $index['unique']);
        }
        foreach ($meta['relations'] as $relation) {
            if ($relation['kind'] === 'belongsTo') {
                $indexes[] = $this->index($meta['table'], [$meta['columns'][$relation['key']]['column']], false);
            }
        }
        if ($meta['tenant'] !== null) {
            $indexes[] = $this->index($meta['table'], [$meta['columns'][$meta['tenant']]['column']], false);
        }

        return ['CREATE TABLE ' . $this->q($meta['table']) . " (\n    " . implode(",\n    ", $lines) . "\n)", ...array_values(array_unique($indexes))];
    }

    /** @param list<string> $columns */
    private function index(string $table, array $columns, bool $unique): string
    {
        $name = substr($table . '_' . implode('_', $columns) . ($unique ? '_unique' : '_index'), 0, 60);

        return 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->q($name) . ' ON ' . $this->q($table) . ' (' . implode(', ', array_map($this->q(...), $columns)) . ')';
    }

    /** @param array{type: string} $column */
    private function defaultFor(array $column): string
    {
        return match ($column['type']) {
            'int', 'float', 'decimal' => ' DEFAULT 0',
            'bool' => ' DEFAULT ' . ($this->dialect->name() === 'pgsql' ? 'FALSE' : '0'),
            'string', 'text', 'enum' => " DEFAULT ''",
            default => '',
        };
    }

    /** @return array<string, array{0: string, 1: string}> pivot table => [local column, related column] */
    private function pivots(): array
    {
        $pivots = [];
        foreach ($this->map->all() as $meta) {
            foreach ($meta['relations'] as $relation) {
                if ($relation['kind'] === 'belongsToMany' && isset($relation['pivot'], $relation['local'], $relation['related'])) {
                    $pivots[$relation['pivot']] ??= [$relation['local'], $relation['related']];
                }
            }
        }

        return $pivots;
    }

    /**
     * @param list<string> $params
     * @return list<string>
     */
    private function column(\PDO $pdo, string $sql, array $params): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $values = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $value = \is_array($row) ? ($row['name'] ?? $row['NAME'] ?? null) : null;
            if (\is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function q(string $identifier): string
    {
        return $this->dialect->quote($identifier);
    }
}
