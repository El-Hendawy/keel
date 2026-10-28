<?php

declare(strict_types=1);

namespace Keel\Queue;

/** Inspects and retries failed jobs (`keel queue:failed`, `keel queue:retry`). */
final readonly class FailedJobs
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array{id: string, type: string, attempts: string, failed_at: string, last_error: string}> */
    public function list(int $limit = 50): array
    {
        $statement = $this->pdo->prepare("SELECT id, type, attempts, failed_at, last_error FROM keel_jobs WHERE status = 'failed' ORDER BY failed_at DESC LIMIT " . max(1, $limit));
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (\is_array($row)) {
                $rows[] = [
                    'id' => self::str($row['id'] ?? ''),
                    'type' => self::str($row['type'] ?? ''),
                    'attempts' => self::str($row['attempts'] ?? ''),
                    'failed_at' => gmdate('Y-m-d H:i:s', (int) self::str($row['failed_at'] ?? '0')),
                    'last_error' => self::str($row['last_error'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /** Puts failed jobs back in the queue with a fresh attempt count. $id = 'all' retries every failed job. */
    public function retry(string $id, int $now): int
    {
        $sql = "UPDATE keel_jobs SET status = 'pending', attempts = 0, available_at = ?, failed_at = NULL WHERE status = 'failed'";
        $statement = $id === 'all'
            ? $this->pdo->prepare($sql)
            : $this->pdo->prepare($sql . ' AND id = ?');
        $statement->execute($id === 'all' ? [$now] : [$now, $id]);

        return $statement->rowCount();
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
