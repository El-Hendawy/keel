<?php

declare(strict_types=1);

namespace Keel\Tests\Database;

use Keel\Database\ConnectionManager;
use Keel\Database\Driver\AsyncPgConnection;
use Keel\Database\Driver\Connection;
use Keel\Database\Driver\QueryFailed;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Keel\Async\await_all;

/**
 * The non-blocking PostgreSQL driver. Set KEEL_TEST_PGSQL_DSN (a pgsql: DSN) to run the
 * parts that need a server; placeholder rewriting is tested without one.
 */
#[RequiresPhpExtension('pgsql')]
final class AsyncPostgresTest extends TestCase
{
    private static function dsn(): string
    {
        $dsn = getenv('KEEL_TEST_PGSQL_DSN') ?: (str_starts_with((string) getenv('KEEL_TEST_DATABASE_DSN'), 'pgsql') ? (string) getenv('KEEL_TEST_DATABASE_DSN') : '');
        if ($dsn === '') {
            self::markTestSkipped('Set KEEL_TEST_PGSQL_DSN to run against PostgreSQL.');
        }

        return 'pgsql-async:' . preg_replace('/^pgsql(-async)?:/', '', $dsn);
    }

    public function testPlaceholdersAreRewritten(): void
    {
        self::assertSame(
            ["SELECT \$1, '?', \"a?\", x::int, \$2 -- ?\n, \$3", [1, 2, 3]],
            AsyncPgConnection::positional("SELECT ?, '?', \"a?\", x::int, ? -- ?\n, ?", [1, 2, 3]),
        );
        self::assertSame(['WHERE a = $1 AND b = $2 OR a = $1', ['A', 'B']], AsyncPgConnection::positional('WHERE a = :a AND b = :b OR a = :a', ['a' => 'A', 'b' => 'B']));
        self::assertSame(["SELECT 'it''s ?' , \$1", [7]], AsyncPgConnection::positional("SELECT 'it''s ?' , ?", [7]));
    }

    public function testQueriesFromConcurrentRequestsOverlap(): void
    {
        $pool = new ConnectionManager(self::dsn(), poolSize: 10);
        $sleep = static fn (): mixed => $pool->with(static fn (Connection $c) => $c->query('SELECT pg_sleep(0.3) AS slept, ?::int AS n', [42])->rows[0]['n']);

        $start = microtime(true);
        $results = await_all(...array_fill(0, 10, $sleep));
        $elapsed = microtime(true) - $start;

        self::assertSame(array_fill(0, 10, 42), $results);
        self::assertLessThan(1.5, $elapsed, 'Ten 300 ms queries ran concurrently, not one after the other (3 s).');
        $pool->dispose();
    }

    public function testThePoolLimitsConnectionsAndIsolatesTransactions(): void
    {
        $pool = new ConnectionManager(self::dsn(), poolSize: 2);
        $setup = $pool->acquire();
        $setup->exec('DROP TABLE IF EXISTS keel_async_test; CREATE TABLE keel_async_test (n INT)');
        $pool->release($setup);

        $start = microtime(true);
        await_all(
            static fn () => $pool->with(static function (Connection $c): void {
                $c->begin();
                $c->query('INSERT INTO keel_async_test VALUES (?)', [1]);
                $c->query('SELECT pg_sleep(0.3)');
                $c->rollBack();
            }),
            static fn () => $pool->with(static function (Connection $c): void {
                $c->query('SELECT pg_sleep(0.1)');
                // The other request's uncommitted row is not visible here.
                self::assertSame(0, $c->query('SELECT COUNT(*) AS n FROM keel_async_test')->rows[0]['n']);
            }),
            static fn () => $pool->with(static fn (Connection $c) => $c->query('SELECT pg_sleep(0.3)')), // waits for a free connection
        );
        $elapsed = microtime(true) - $start;
        self::assertGreaterThan(0.39, $elapsed, 'The third query waited for one of the two pooled connections.');
        self::assertLessThan(0.9, $elapsed);

        $types = $pool->with(static fn (Connection $c) => $c->query("SELECT 1::int8 AS i, true AS b, 1.5::float8 AS f, 2.50::numeric AS d, NULL AS z, 'x' AS s")->rows[0]);
        self::assertSame(['i' => 1, 'b' => true, 'f' => 1.5, 'd' => '2.50', 'z' => null, 's' => 'x'], $types);

        try {
            $pool->with(static fn (Connection $c) => $c->query('SELECT * FROM no_such_table'));
            self::fail('Expected a QueryFailed');
        } catch (QueryFailed $e) {
            self::assertSame('42P01', $e->getCode());
            self::assertInstanceOf(\PDOException::class, $e);
        }
        $pool->dispose();
    }
}
