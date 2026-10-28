<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

use Keel\Redis\RedisConnection;

/**
 * Redis Streams with a consumer group per queue (`keel:q:<queue>`):
 * - delayed jobs and retries wait in a sorted set and are moved into the stream when due;
 * - jobs a crashed worker held longer than `$visibilitySeconds` are claimed by another
 *   (XAUTOCLAIM);
 * - failed jobs go to the stream `keel:q:<queue>:failed`.
 */
final class RedisStreamsTransport implements Transport
{
    private const string GROUP = 'keel';
    private const string MOVE_DUE = <<<'LUA'
        local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 100)
        for _, job in ipairs(due) do
            redis.call('ZREM', KEYS[1], job)
            redis.call('XADD', KEYS[2], '*', 'job', job)
        end
        return #due
        LUA;

    /** @var array<string, true> */
    private array $groups = [];

    private readonly string $consumer;

    public function __construct(private readonly RedisConnection $redis, private readonly int $visibilitySeconds = 300)
    {
        $this->consumer = (gethostname() ?: 'host') . ':' . getmypid();
    }

    public function publish(Envelope $envelope, int $delaySeconds = 0): void
    {
        $client = $this->redis->client();
        if ($delaySeconds > 0) {
            $client->zAdd(self::key($envelope->queue) . ':delayed', microtime(true) + $delaySeconds, $envelope->encode());

            return;
        }
        $client->xAdd(self::key($envelope->queue), '*', ['job' => $envelope->encode()]);
    }

    public function receive(array $queues, float $waitSeconds): ?Delivery
    {
        $client = $this->redis->client();
        $streams = [];
        foreach ($queues as $queue) {
            $this->group($queue);
            $client->eval(self::MOVE_DUE, [self::key($queue) . ':delayed', self::key($queue), (string) microtime(true)], 2);
            $claimed = $client->xAutoClaim(self::key($queue), self::GROUP, $this->consumer, $this->visibilitySeconds * 1000, '0-0', 1);
            if (\is_array($claimed) && \is_array($claimed[1] ?? null) && $claimed[1] !== []) {
                return $this->delivery($queue, $claimed[1]);
            }
            $streams[self::key($queue)] = '>';
        }
        $read = $client->xReadGroup(self::GROUP, $this->consumer, $streams, 1, max(1, (int) ($waitSeconds * 1000)));
        if (!\is_array($read)) {
            return null;
        }
        foreach ($read as $stream => $entries) {
            if (\is_array($entries) && $entries !== []) {
                return $this->delivery(substr((string) $stream, \strlen('keel:q:')), $entries);
            }
        }

        return null;
    }

    public function ack(Delivery $delivery): void
    {
        [$queue, $id] = explode('|', $delivery->handle, 2);
        $client = $this->redis->client();
        $client->xAck(self::key($queue), self::GROUP, [$id]);
        $client->xDel(self::key($queue), [$id]);
    }

    public function retry(Delivery $delivery, int $delaySeconds, string $error): void
    {
        $this->publish($delivery->envelope->withAttempts($delivery->attempts), max(1, $delaySeconds));
        $this->ack($delivery);
    }

    public function fail(Delivery $delivery, string $error): void
    {
        $this->redis->client()->xAdd(self::key($delivery->envelope->queue) . ':failed', '*', [
            'job' => $delivery->envelope->withAttempts($delivery->attempts)->encode(),
            'error' => $error,
            'failed_at' => (string) time(),
        ]);
        $this->ack($delivery);
    }

    /** @param array<mixed> $entries */
    private function delivery(string $queue, array $entries): ?Delivery
    {
        foreach ($entries as $id => $fields) {
            $job = \is_array($fields) ? ($fields['job'] ?? null) : null;
            if (!\is_string($job)) {
                $this->redis->client()->xAck(self::key($queue), self::GROUP, [(string) $id]);
                continue;
            }
            $envelope = Envelope::decode($job);

            return new Delivery($envelope, $queue . '|' . $id, $envelope->attempts + 1);
        }

        return null;
    }

    private function group(string $queue): void
    {
        if (isset($this->groups[$queue])) {
            return;
        }
        try {
            $this->redis->client()->xGroup('CREATE', self::key($queue), self::GROUP, '0', true);
        } catch (\RedisException) {
            // BUSYGROUP: it exists
        }
        $this->groups[$queue] = true;
    }

    private static function key(string $queue): string
    {
        return 'keel:q:' . $queue;
    }
}
