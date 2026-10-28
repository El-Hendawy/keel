<?php

declare(strict_types=1);

namespace Keel\Testing;

/**
 * Database helpers for tests.
 *
 * `dsn()` returns KEEL_TEST_DATABASE_DSN when set (to run the same tests against
 * PostgreSQL or MySQL in CI), else an in-memory SQLite database. `reset()` drops every
 * table so each test starts from an empty schema. For parallel runs (paratest), give each
 * process its own database, e.g. `dbname=app_test_${TEST_TOKEN}`.
 */
final class TestDatabase
{
    public static function dsn(): string
    {
        $dsn = getenv('KEEL_TEST_DATABASE_DSN');

        return \is_string($dsn) && $dsn !== '' ? str_replace('{token}', (string) (getenv('TEST_TOKEN') ?: '0'), $dsn) : 'sqlite::memory:';
    }

    public static function reset(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');

            return;
        }
        $query = match ($driver) {
            'mysql' => 'SHOW TABLES',
            default => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        };
        $statement = $pdo->query($query);
        $tables = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF');
        foreach ($tables as $table) {
            if (\is_string($table)) {
                $pdo->exec('DROP TABLE ' . ($driver === 'mysql' ? "`$table`" : "\"$table\""));
            }
        }
        $pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 1' : 'PRAGMA foreign_keys = ON');
    }
}
