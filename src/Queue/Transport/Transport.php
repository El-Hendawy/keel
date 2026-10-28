<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

/**
 * A message broker the queue can use instead of polling the database: Redis Streams, Amazon
 * SQS or RabbitMQ. Jobs are still written to `keel_jobs` inside your transaction (the
 * outbox); `keel queue:work` relays committed jobs to the transport and consumes from it.
 * Delivery is at least once.
 */
interface Transport
{
    public function publish(Envelope $envelope, int $delaySeconds = 0): void;

    /**
     * Waits up to $waitSeconds for a job from any of $queues.
     *
     * @param list<string> $queues
     */
    public function receive(array $queues, float $waitSeconds): ?Delivery;

    public function ack(Delivery $delivery): void;

    /** Makes the job available again after $delaySeconds. */
    public function retry(Delivery $delivery, int $delaySeconds, string $error): void;

    /** Moves the job to the dead-letter queue. */
    public function fail(Delivery $delivery, string $error): void;
}
