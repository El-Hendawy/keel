<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Container\Disposable;
use Keel\Database\Driver\Connection;
use Keel\Observability\Tracing\Span;
use Keel\Observability\Tracing\Tracer;
use Keel\Tenancy\TenantContext;
use Keel\Tenancy\TenantGuard;

/**
 * Parameterised SQL for one scope.
 *
 * - SQL must be a literal string (enforced by PHPStan's literal-string type): values
 *   go in $params, never into the statement. rawQuery() is the named escape hatch.
 * - Reads go to the replica until this scope writes; then they stick to the primary,
 *   so a request always reads its own writes.
 * - Results are capped at `database.max_rows`; every query is traced and counted.
 * - An open transaction is rolled back when the scope ends.
 * - The scope leases its connections from the ConnectionManager (pooled under `keel run`),
 *   so concurrent requests never share a session.
 * - Statements on tenant-owned tables must filter by the current tenant (TenantGuard).
 */
#[Scoped]
final class Database implements Disposable
{
    private bool $wrote = false;

    private int $transactionDepth = 0;

    private ?Connection $writer = null;

    private ?Connection $reader = null;

    /** @var array<int, Connection> connections on which keel.tenant_id is set for this scope */
    private array $rlsConnections = [];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly QueryLog $log,
        private readonly Tracer $tracer,
        private readonly TenantContext $tenant,
        private readonly TenantGuard $guard,
        #[Config('database.max_rows')] private readonly int $maxRows = 10_000,
        #[Config('database.tenant_rls')] private readonly bool $rls = false,
    ) {
    }

    /**
     * @param literal-string $sql
     * @param array<int|string, scalar|list<scalar>|null> $params
     * @return list<Row>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->fetch($this->readConnection(), $sql, $params);
    }

    /**
     * @param literal-string $sql
     * @param array<int|string, scalar|list<scalar>|null> $params
     */
    public function selectOne(string $sql, array $params = []): ?Row
    {
        return $this->select($sql, $params)[0] ?? null;
    }

    /**
     * Executes a write and returns the number of affected rows.
     *
     * @param literal-string $sql
     * @param array<int|string, scalar|list<scalar>|null> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->wrote = true;

        return $this->run($this->writer(), $sql, $params, 0)->affected;
    }

    /**
     * Runs $work in a transaction; commits on return, rolls back on exception.
     * Nested calls use savepoints.
     *
     * @template T
     * @param \Closure(self): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        $connection = $this->writer();
        $this->wrote = true;
        $depth = $this->transactionDepth;
        $depth === 0 ? $connection->begin() : $connection->exec('SAVEPOINT keel_' . $depth);
        $this->transactionDepth++;

        try {
            $result = $work($this);
            $this->transactionDepth--;
            $depth === 0 ? $connection->commit() : $connection->exec('RELEASE SAVEPOINT keel_' . $depth);

            return $result;
        } catch (\Throwable $e) {
            $this->transactionDepth--;
            $depth === 0 ? $connection->rollBack() : $connection->exec('ROLLBACK TO SAVEPOINT keel_' . $depth);
            throw $e;
        }
    }

    public function lastInsertId(): string
    {
        return $this->writer()->lastInsertId();
    }

    /**
     * Unsafe by name: accepts a dynamically built statement. Every caller is a place to
     * review for injection.
     *
     * @param array<int|string, scalar|list<scalar>|null> $params
     * @return list<Row>
     */
    public function rawQuery(string $sql, array $params = []): array
    {
        $this->wrote = true;

        return $this->fetch($this->writer(), $sql, $params);
    }

    /**
     * @internal for the ORM only: the statement is built from compiled entity metadata
     * (identifiers never come from input) and every value is bound.
     *
     * @param list<scalar|null> $params
     * @return list<Row>
     */
    public function selectBuilt(string $sql, array $params, bool $primary = false): array
    {
        return $this->fetch($primary ? $this->writer() : $this->readConnection(), $sql, $params);
    }

    /**
     * @internal for the ORM only; see selectBuilt()
     *
     * @param list<scalar|null> $params
     */
    public function executeBuilt(string $sql, array $params): int
    {
        $this->wrote = true;

        return $this->run($this->writer(), $sql, $params, 0)->affected;
    }

    /**
     * @internal for the ORM only: an INSERT that returns the generated key through
     * RETURNING / OUTPUT (the first column of the first row), or lastInsertId().
     *
     * @param list<scalar|null> $params
     */
    public function insertBuilt(string $sql, array $params, bool $returnsRow): string
    {
        $this->wrote = true;
        $result = $this->run($this->writer(), $sql, $params, 0);
        if ($returnsRow) {
            $value = array_values($result->rows[0] ?? [])[0] ?? null;

            return \is_scalar($value) ? (string) $value : '';
        }

        return $this->writer()->lastInsertId();
    }

    public function driver(): string
    {
        return $this->writer()->driver();
    }

    public function dispose(): void
    {
        try {
            if ($this->transactionDepth > 0 && $this->writer !== null) {
                $this->writer->rollBack();
                $this->transactionDepth = 0;
            }
            foreach ($this->rlsConnections as $connection) {
                // Connections outlive the scope: never leave a tenant behind for the next request.
                $connection->exec("SELECT set_config('keel.tenant_id', '', false)");
            }
            $this->rlsConnections = [];
        } finally {
            foreach ([$this->writer, $this->reader] as $connection) {
                if ($connection !== null) {
                    $this->connections->release($connection);
                }
            }
            $this->writer = $this->reader = null;
        }
    }

    private function writer(): Connection
    {
        return $this->writer ??= $this->connections->acquire();
    }

    private function readConnection(): Connection
    {
        if ($this->wrote || $this->transactionDepth > 0) {
            return $this->writer();
        }

        return $this->reader ??= $this->connections->acquire(replica: true);
    }

    /**
     * @param array<int|string, scalar|list<scalar>|null> $params
     * @return list<Row>
     */
    private function fetch(Connection $connection, string $sql, array $params): array
    {
        return array_map(static fn (array $row): Row => new Row($row), $this->run($connection, $sql, $params, $this->maxRows)->rows);
    }

    /**
     * Expands a named list parameter into one placeholder per item, so `IN (:ids)` stays a
     * literal statement: select('... WHERE id IN (:ids)', ['ids' => [1, 2, 3]]).
     *
     * @param array<int|string, scalar|list<scalar>|null> $params
     * @return array{0: string, 1: array<int|string, scalar|null>}
     */
    private static function expandLists(string $sql, array $params): array
    {
        $flat = [];
        foreach ($params as $key => $value) {
            if (!\is_array($value)) {
                $flat[$key] = $value;
                continue;
            }
            if (!\is_string($key)) {
                throw new \InvalidArgumentException('List parameters must be named, e.g. IN (:ids).');
            }
            if ($value === []) {
                $sql = (string) preg_replace('/:' . preg_quote($key, '/') . '\\b/', 'NULL', $sql);
                continue;
            }
            $names = [];
            foreach ($value as $i => $item) {
                $names[] = ':' . $key . '_' . $i;
                $flat[$key . '_' . $i] = $item;
            }
            $sql = (string) preg_replace('/:' . preg_quote($key, '/') . '\\b/', implode(', ', $names), $sql);
        }

        return [$sql, $flat];
    }

    /** @param array<int|string, scalar|list<scalar>|null> $params */
    private function run(Connection $connection, string $sql, array $params, int $maxRows): Driver\Result
    {
        $start = hrtime(true);
        if ($this->guard->enabled()) {
            $this->guard->check($sql, $params, $this->tenant);
        }
        if ($this->rls && !isset($this->rlsConnections[spl_object_id($connection)]) && ($tenant = $this->tenant->current()) !== null) {
            // PostgreSQL row-level security policies read current_setting('keel.tenant_id').
            $connection->query("SELECT set_config('keel.tenant_id', ?, false)", [$tenant]);
            $this->rlsConnections[spl_object_id($connection)] = $connection;
        }
        [$sql, $params] = self::expandLists($sql, $params);

        return $this->tracer->trace('db.query', function (Span $span) use ($connection, $sql, $params, $start, $maxRows): Driver\Result {
            $span->setAttribute('db.system', $connection->driver());
            $span->setAttribute('db.query.text', $sql);
            $result = $connection->query($sql, $params, $maxRows);
            $this->log->record($sql, (hrtime(true) - $start) / 1e6);

            return $result;
        }, kind: Span::KIND_CLIENT);
    }
}
