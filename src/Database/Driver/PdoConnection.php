<?php

declare(strict_types=1);

namespace Keel\Database\Driver;

use Keel\Database\ResultTooLarge;

/** Any PDO driver, with prepared statements cached per connection. */
final class PdoConnection implements Connection
{
    /** @var array<string, \PDOStatement> */
    private array $statements = [];

    public function __construct(public readonly \PDO $pdo, private readonly int $statementCache = 256)
    {
    }

    public function query(string $sql, array $params = [], int $maxRows = 0): Result
    {
        $statement = $this->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(\is_int($key) ? $key + 1 : $key, $value, match (true) {
                \is_int($value) => \PDO::PARAM_INT,
                \is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
        $statement->execute();
        $rows = [];
        if ($statement->columnCount() > 0) {
            while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if ($maxRows > 0 && \count($rows) >= $maxRows) {
                    $statement->closeCursor();
                    throw new ResultTooLarge("Query returned more than $maxRows rows. Paginate, or raise database.max_rows deliberately: $sql");
                }
                /** @var array<string, mixed> $row PDO::FETCH_ASSOC rows are keyed by column name */
                $rows[] = $row;
            }
        }
        $affected = $statement->rowCount();
        $statement->closeCursor(); // the statement is cached and will be executed again

        return new Result($rows, $affected);
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function driver(): string
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return \is_string($driver) ? $driver : '';
    }

    public function close(): void
    {
        $this->statements = [];
    }

    private function prepare(string $sql): \PDOStatement
    {
        if (isset($this->statements[$sql])) {
            return $this->statements[$sql];
        }
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException("Could not prepare: $sql");
        }
        if ($this->statementCache > 0) {
            if (\count($this->statements) >= $this->statementCache) {
                array_shift($this->statements);
            }
            $this->statements[$sql] = $statement;
        }

        return $statement;
    }
}
