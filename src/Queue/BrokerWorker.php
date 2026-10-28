<?php

declare(strict_types=1);

namespace Keel\Queue;

use Keel\Container\Container;
use Keel\Database\ConnectionManager;
use Keel\Queue\Transport\Delivery;
use Keel\Queue\Transport\Envelope;
use Keel\Queue\Transport\Transport;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes jobs through a broker (`queue.transport`). Each turn it relays committed jobs
 * from the `keel_jobs` outbox to the broker, then consumes one job: run, acknowledge, or
 * retry with exponential backoff, or dead-letter after the handler's maxAttempts.
 */
final class BrokerWorker
{
    private bool $stopping = false;

    public function __construct(private readonly Container $container, private readonly Transport $transport)
    {
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * @param list<string> $queues
     * @return int jobs processed
     */
    public function work(array $queues, int $maxJobs = 0, float $waitSeconds = 1.0, ?float $maxIdleSeconds = null): int
    {
        $processed = 0;
        $idleSince = microtime(true);
        while (!$this->stopping && ($maxJobs === 0 || $processed < $maxJobs)) {
            $this->relay();
            if ($this->processNext($queues, $waitSeconds)) {
                $processed++;
                $idleSince = microtime(true);
            } elseif ($maxIdleSeconds !== null && microtime(true) - $idleSince >= $maxIdleSeconds) {
                break;
            }
        }

        return $processed;
    }

    /** @param list<string> $queues */
    public function processNext(array $queues, float $waitSeconds = 0.0): bool
    {
        $delivery = $this->transport->receive($queues, $waitSeconds);
        if ($delivery === null) {
            return false;
        }
        $envelope = $delivery->envelope;
        try {
            $this->container->get(JobRunner::class)->run($this->container, $envelope->type, $envelope->payload, $envelope->tenantId, $envelope->traceparent, $delivery->attempts);
            $this->transport->ack($delivery);
        } catch (\Throwable $e) {
            $this->failed($delivery, $e);
        }

        return true;
    }

    /**
     * Moves committed, due jobs from the `keel_jobs` outbox to the broker. Rows are deleted
     * only after they were published, so a crash publishes them again (at least once).
     */
    public function relay(int $batch = 100): int
    {
        if (!$this->container->has(ConnectionManager::class)) {
            return 0;
        }
        $pdo = $this->container->get(ConnectionManager::class)->writer();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $lock = $driver === 'pgsql' || $driver === 'mysql' ? ' FOR UPDATE SKIP LOCKED' : '';
        $now = $this->container->get(ClockInterface::class)->now()->getTimestamp();
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare("SELECT id, queue, type, payload, tenant_id, traceparent FROM keel_jobs WHERE status = 'pending' AND available_at <= ? ORDER BY available_at, id LIMIT " . max(1, $batch) . $lock);
            $select->execute([$now]);
            $ids = [];
            foreach ($select->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $value = static fn (string $k): ?string => \is_scalar($row[$k] ?? null) ? (string) $row[$k] : null;
                $this->transport->publish(new Envelope((string) $value('id'), (string) $value('queue'), (string) $value('type'), (string) $value('payload'), $value('tenant_id'), $value('traceparent')));
                $ids[] = (string) $value('id');
            }
            if ($ids !== []) {
                $pdo->prepare('DELETE FROM keel_jobs WHERE id IN (' . implode(', ', array_fill(0, \count($ids), '?')) . ')')->execute($ids);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return \count($ids);
    }

    private function failed(Delivery $delivery, \Throwable $error): void
    {
        $definition = $this->container->get(JobCodec::class)->definition($delivery->envelope->type);
        $message = $error::class . ': ' . $error->getMessage();
        $logger = $this->container->get(LoggerInterface::class);
        if ($delivery->attempts >= $definition['maxAttempts']) {
            $this->transport->fail($delivery, $message);
            $logger->error('Job {job_id} ({type}) failed permanently after {attempts} attempts: {error}', ['job_id' => $delivery->envelope->id, 'type' => $delivery->envelope->type, 'attempts' => $delivery->attempts, 'error' => $message, 'exception' => $error]);

            return;
        }
        $delay = min(3600, $definition['backoff'] << min(20, max(0, $delivery->attempts - 1)));
        $delay = random_int(intdiv($delay + 1, 2), max(1, $delay));
        $this->transport->retry($delivery, $delay, $message);
        $logger->warning('Job {job_id} ({type}) failed on attempt {attempts}, retrying in {delay}s: {error}', ['job_id' => $delivery->envelope->id, 'type' => $delivery->envelope->type, 'attempts' => $delivery->attempts, 'delay' => $delay, 'error' => $message]);
    }
}
