<?php

declare(strict_types=1);

namespace Keel\Orm\Dialect;

/** @internal INSERT ... ON CONFLICT (...) DO UPDATE, shared by PostgreSQL and SQLite */
final class OnConflict
{
    /**
     * @param list<string> $columns
     * @param list<string> $conflict
     * @param list<string> $update
     */
    public static function sql(Dialect $d, string $table, array $columns, int $rows, array $conflict, array $update): string
    {
        $row = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        $set = implode(', ', array_map(static fn (string $c): string => $d->quote($c) . ' = excluded.' . $d->quote($c), $update));

        return 'INSERT INTO ' . $d->quote($table) . ' (' . implode(', ', array_map($d->quote(...), $columns)) . ') VALUES '
            . implode(', ', array_fill(0, $rows, $row))
            . ' ON CONFLICT (' . implode(', ', array_map($d->quote(...), $conflict)) . ') '
            . ($set === '' ? 'DO NOTHING' : 'DO UPDATE SET ' . $set);
    }
}
