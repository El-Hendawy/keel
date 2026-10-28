<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Container\Disposable;
use Keel\Observability\Tracing\Span;
use Keel\Observability\Tracing\Tracer;

/**
 * Parameterised SQL for one scope.
 *
 * - SQL must be a literal string (enforced by PHPStan's literal-string type): values
 *   go in $params, never into the statement. rawQuery() is the named escape hatch.
 * - Reads go to the replica until this scope writes; then they stick to the primary,
 *   so a request always reads its own writes.
 * - Results are capped at `database.max_rows`; every query is traced and counted.
 * - An open transaction is rolled back when the scope ends.
 */
#[Scoped]
final class Database implements Disposable
{
    private bool $wrote = false;

    private int $transactionDepth = 0;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly QueryLog $log,
        private readonly Tracer $tracer,
        #[Config('database.max_rows')] private readonly int $maxRows = 10_000,
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

        return $this->run($this->connections->writer(), $sql, $params)->rowCount();
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
        $pdo = $this->connections->writer();
        $this->wrote = true;
        $depth = $this->transactionDepth;
        $depth === 0 ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT keel_' . $depth);
        $this->transactionDepth++;

        try {
            $result = $work($this);
            $this->transactionDepth--;
            $depth === 0 ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT keel_' . $depth);

            return $result;
        } catch (\Throwable $e) {
            $this->transactionDepth--;
            $depth === 0 ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT keel_' . $depth);
            throw $e;
        }
    }

    public function lastInsertId(): string
    {
        return (string) $this->connections->writer()->lastInsertId();
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

        return $this->fetch($this->connections->writer(), $sql, $params);
    }

    public function dispose(): void
    {
        if ($this->transactionDepth > 0) {
            $this->connections->writer()->rollBack();
            $this->transactionDepth = 0;
        }
    }

    private function readConnection(): \PDO
    {
        return $this->wrote || $this->transactionDepth > 0 ? $this->connections->writer() : $this->connections->reader();
    }

    /**
     * @param array<int|string, scalar|list<scalar>|null> $params
     * @return list<Row>
     */
    private function fetch(\PDO $pdo, string $sql, array $params): array
    {
        $statement = $this->run($pdo, $sql, $params);
        $rows = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (\count($rows) >= $this->maxRows) {
                $statement->closeCursor();
                throw new ResultTooLarge("Query returned more than {$this->maxRows} rows. Paginate, or raise database.max_rows deliberately: $sql");
            }
            /** @var array<string, mixed> $row PDO::FETCH_ASSOC rows are keyed by column name */
            $rows[] = new Row($row);
        }

        return $rows;
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
    private function run(\PDO $pdo, string $sql, array $params): \PDOStatement
    {
        $start = hrtime(true);
        [$sql, $params] = self::expandLists($sql, $params);

        return $this->tracer->trace('db.query', function (Span $span) use ($pdo, $sql, $params, $start): \PDOStatement {
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $span->setAttribute('db.system', \is_string($driver) ? $driver : 'unknown');
            $span->setAttribute('db.query.text', $sql);
            $statement = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $statement->bindValue(\is_int($key) ? $key + 1 : $key, $value, match (true) {
                    \is_int($value) => \PDO::PARAM_INT,
                    \is_bool($value) => \PDO::PARAM_BOOL,
                    $value === null => \PDO::PARAM_NULL,
                    default => \PDO::PARAM_STR,
                });
            }
            $statement->execute();
            $this->log->record($sql, (hrtime(true) - $start) / 1e6);

            return $statement;
        }, kind: Span::KIND_CLIENT);
    }
}
