<?php

declare(strict_types=1);

namespace Keel\Realtime;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Streaming\ClientDisconnected;
use Keel\Http\Streaming\EventSink;
use Keel\Redis\RedisConnection;

use function Keel\Async\delay;

/**
 * Publish/subscribe for real-time clients (WebSockets and server-sent events).
 *
 * Subscribers live in the process that holds the connection. With `realtime.redis = true`,
 * publish() also goes through Redis, so a message published anywhere (a web request, a
 * queue worker, another server process) reaches every subscriber on every node.
 */
#[Singleton]
final class Hub
{
    public const string PREFIX = 'keel:hub:';

    /** @var array<string, array<int, \Closure(string): void>> channel => subscriber id => callback */
    private array $subscribers = [];

    private int $next = 0;

    private readonly string $origin;

    public function __construct(
        private readonly ?RedisConnection $redis = null,
        #[Config('realtime.redis')] private readonly bool $useRedis = false,
    ) {
        $this->origin = bin2hex(random_bytes(6));
    }

    public function publish(string $channel, string $message): void
    {
        $this->deliver($channel, $message);
        if ($this->useRedis && $this->redis !== null) {
            $this->redis->client()->publish(self::PREFIX . $channel, $this->origin . "\n" . $message);
        }
    }

    /** @param \Closure(string): void $callback */
    public function subscribe(string $channel, \Closure $callback): int
    {
        $id = ++$this->next;
        $this->subscribers[$channel][$id] = $callback;

        return $id;
    }

    public function unsubscribe(int $id): void
    {
        foreach ($this->subscribers as $channel => $subscribers) {
            unset($this->subscribers[$channel][$id]);
            if ($this->subscribers[$channel] === []) {
                unset($this->subscribers[$channel]);
            }
        }
    }

    /**
     * Forwards a channel to a server-sent events client until it disconnects, with a
     * heartbeat every $heartbeatSeconds. Needs `keel run` (it waits on the event loop).
     */
    public function stream(string $channel, EventSink $events, ?string $event = null, float $heartbeatSeconds = 15.0): void
    {
        $id = $this->subscribe($channel, static function (string $message) use ($events, $event): void {
            try {
                $events->send($message, $event);
            } catch (ClientDisconnected) {
            }
        });
        try {
            while (!$events->isClosed()) {
                delay($heartbeatSeconds);
                $events->heartbeat();
            }
        } catch (ClientDisconnected) {
        } finally {
            $this->unsubscribe($id);
        }
    }

    /** @internal called by the server for messages that arrive through Redis */
    public function receive(string $redisChannel, string $payload): void
    {
        [$origin, $message] = array_pad(explode("\n", $payload, 2), 2, '');
        if ($origin !== $this->origin) {
            $this->deliver(substr($redisChannel, \strlen(self::PREFIX)), $message);
        }
    }

    public function usesRedis(): bool
    {
        return $this->useRedis;
    }

    private function deliver(string $channel, string $message): void
    {
        foreach ($this->subscribers[$channel] ?? [] as $callback) {
            $callback($message);
        }
    }
}
