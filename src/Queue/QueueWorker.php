<?php

declare(strict_types=1);

namespace Keel\Queue;

use Keel\Container\Container;
use Keel\Database\ConnectionManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes jobs from `keel_jobs` (`keel queue:work`).
 *
 * Jobs are reserved for `queue.visibility_seconds`; a worker that dies mid-job leaves a
 * reservation that expires, and another worker takes the job again. On PostgreSQL and
 * MySQL, reservation uses FOR UPDATE SKIP LOCKED, so many workers never contend.
 */
final class QueueWorker
{
    private bool $stopping = false;

    private readonly \PDO $pdo;

    private readonly string $driver;

    public function __construct(
        private readonly Container $container,
        private readonly int $visibilitySeconds = 300,
    ) {
        $this->pdo = $container->get(ConnectionManager::class)->writer();
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = \is_string($driver) ? $driver : '';
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Runs until stopped, idle for $maxIdleSeconds, or $maxJobs have been processed.
     *
     * @param list<string> $queues
     * @return int jobs processed
     */
    public function work(array $queues = ['default'], int $maxJobs = 0, float $sleepSeconds = 1.0, ?float $maxIdleSeconds = null): int
    {
        $processed = 0;
        $idleSince = microtime(true);
        while (!$this->stopping && ($maxJobs === 0 || $processed < $maxJobs)) {
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->processNext($queues)) {
                $processed++;
                $idleSince = microtime(true);
                continue;
            }
            if ($maxIdleSeconds !== null && microtime(true) - $idleSince >= $maxIdleSeconds) {
                break;
            }
            usleep((int) ($sleepSeconds * 1e6));
        }

        return $processed;
    }

    /**
     * Processes every job that is ready now (tests, `queue:work --once`).
     *
     * @param list<string> $queues
     */
    public function drain(array $queues = ['default'], int $limit = 1000): int
    {
        $processed = 0;
        while ($processed < $limit && $this->processNext($queues)) {
            $processed++;
        }

        return $processed;
    }

    /** @param list<string> $queues */
    public function processNext(array $queues = ['default']): bool
    {
        $job = $this->reserve($queues);
        if ($job === null) {
            return false;
        }
        $runner = $this->container->get(JobRunner::class);
        try {
            $runner->run($this->container, $job['type'], $job['payload'], $job['tenant_id'], $job['traceparent'], $job['attempts']);
            $this->pdo->prepare('DELETE FROM keel_jobs WHERE id = ? AND reservation = ?')->execute([$job['id'], $job['reservation']]);
        } catch (\Throwable $e) {
            $this->fail($job, $e);
        }

        return true;
    }

    /**
     * @param list<string> $queues
     * @return array{id: string, type: string, payload: string, tenant_id: ?string, traceparent: ?string, attempts: int, reservation: string}|null
     */
    private function reserve(array $queues): ?array
    {
        $now = $this->now();
        $reservation = bin2hex(random_bytes(16));
        $in = implode(', ', array_fill(0, \count($queues), '?'));
        $ready = "queue IN ($in) AND ((status = 'pending' AND available_at <= ?) OR (status = 'reserved' AND reserved_until < ?))";
        $params = [...$queues, $now, $now];

        if ($this->driver === 'pgsql' || $this->driver === 'mysql') {
            $this->pdo->beginTransaction();
            try {
                $select = $this->pdo->prepare("SELECT id FROM keel_jobs WHERE $ready ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED");
                $select->execute($params);
                $id = $select->fetchColumn();
                if (\is_string($id)) {
                    $this->pdo->prepare("UPDATE keel_jobs SET status = 'reserved', reservation = ?, reserved_until = ?, attempts = attempts + 1 WHERE id = ?")
                        ->execute([$reservation, $now + $this->visibilitySeconds, $id]);
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        } else {
            $this->pdo->prepare("UPDATE keel_jobs SET status = 'reserved', reservation = ?, reserved_until = ?, attempts = attempts + 1 WHERE id = (SELECT id FROM keel_jobs WHERE $ready ORDER BY available_at, id LIMIT 1)")
                ->execute([$reservation, $now + $this->visibilitySeconds, ...$params]);
        }

        $statement = $this->pdo->prepare('SELECT id, type, payload, tenant_id, traceparent, attempts FROM keel_jobs WHERE reservation = ?');
        $statement->execute([$reservation]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return null;
        }

        return [
            'id' => self::str($row['id'] ?? ''),
            'type' => self::str($row['type'] ?? ''),
            'payload' => self::str($row['payload'] ?? ''),
            'tenant_id' => isset($row['tenant_id']) ? self::str($row['tenant_id']) : null,
            'traceparent' => isset($row['traceparent']) ? self::str($row['traceparent']) : null,
            'attempts' => (int) self::str($row['attempts'] ?? '1'),
            'reservation' => $reservation,
        ];
    }

    /** @param array{id: string, type: string, attempts: int, reservation: string} $job */
    private function fail(array $job, \Throwable $error): void
    {
        $definition = $this->container->get(JobCodec::class)->definition($job['type']);
        $message = $error::class . ': ' . $error->getMessage();
        $now = $this->now();
        if ($job['attempts'] >= $definition['maxAttempts']) {
            $this->pdo->prepare("UPDATE keel_jobs SET status = 'failed', failed_at = ?, last_error = ?, reservation = NULL WHERE id = ? AND reservation = ?")
                ->execute([$now, $message, $job['id'], $job['reservation']]);
            $this->container->get(LoggerInterface::class)->error('Job {job_id} ({type}) failed permanently after {attempts} attempts: {error}', [
                'job_id' => $job['id'], 'type' => $job['type'], 'attempts' => $job['attempts'], 'error' => $message, 'exception' => $error,
            ]);

            return;
        }
        // Exponential backoff with full jitter, capped at an hour.
        $delay = min(3600, $definition['backoff'] << min(20, max(0, $job['attempts'] - 1)));
        $delay = random_int(intdiv($delay + 1, 2), max(1, $delay));
        $this->pdo->prepare("UPDATE keel_jobs SET status = 'pending', available_at = ?, last_error = ?, reservation = NULL, reserved_until = NULL WHERE id = ? AND reservation = ?")
            ->execute([$now + $delay, $message, $job['id'], $job['reservation']]);
        $this->container->get(LoggerInterface::class)->warning('Job {job_id} ({type}) failed on attempt {attempts}, retrying in {delay}s: {error}', [
            'job_id' => $job['id'], 'type' => $job['type'], 'attempts' => $job['attempts'], 'delay' => $delay, 'error' => $message,
        ]);
    }

    private function now(): int
    {
        return $this->container->get(ClockInterface::class)->now()->getTimestamp();
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
