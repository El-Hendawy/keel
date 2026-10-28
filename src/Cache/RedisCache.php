<?php

declare(strict_types=1);

namespace Keel\Cache;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Redis\RedisConnection;

use function Keel\Async\delay;

/**
 * A cache shared by every node. On a miss, one worker recomputes the value while the
 * others wait briefly for it (up to `cache.lock_wait_seconds`) instead of all hitting
 * the database at once.
 */
#[Singleton]
final readonly class RedisCache implements Cache
{
    public function __construct(
        private RedisConnection $redis,
        #[Config('app.name')] private string $prefix = 'app',
        #[Config('cache.lock_wait_seconds')] private float $lockWait = 2.0,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->entry($key)?->value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->put($key, $value, $ttlSeconds, 0.0);
    }

    public function delete(string $key): void
    {
        $this->redis->client()->del($this->key($key));
    }

    public function remember(string $key, int $ttlSeconds, \Closure $compute): mixed
    {
        $entry = $this->entry($key);
        if ($entry !== null && !$entry->shouldRefresh(microtime(true))) {
            return $entry->value;
        }
        $client = $this->redis->client();
        $lock = $this->key($key) . ':lock';
        $token = bin2hex(random_bytes(8));
        $owner = $client->set($lock, $token, ['nx', 'px' => (int) max(1000, $this->lockWait * 2000)]) === true;
        if (!$owner) {
            if ($entry !== null) {
                return $entry->value; // someone is refreshing; serve the current value
            }
            $deadline = microtime(true) + $this->lockWait;
            while (microtime(true) < $deadline) {
                delay(0.05);
                $entry = $this->entry($key);
                if ($entry !== null) {
                    return $entry->value;
                }
            }
        }
        try {
            $started = microtime(true);
            $value = $compute();
            $this->put($key, $value, $ttlSeconds, microtime(true) - $started);

            return $value;
        } finally {
            if ($owner) {
                $client->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end", [$lock, $token], 1);
            }
        }
    }

    private function entry(string $key): ?Entry
    {
        $raw = $this->redis->client()->get($this->key($key));
        if (!\is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !\array_key_exists('v', $data)) {
            return null;
        }
        $expires = $data['e'] ?? 0;
        $compute = $data['d'] ?? 0;

        return new Entry($data['v'], \is_int($expires) || \is_float($expires) ? (float) $expires : 0.0, \is_int($compute) || \is_float($compute) ? (float) $compute : 0.0);
    }

    private function put(string $key, mixed $value, int $ttlSeconds, float $computeSeconds): void
    {
        Entry::check($value);
        $payload = json_encode(['v' => $value, 'e' => $ttlSeconds > 0 ? microtime(true) + $ttlSeconds : 0, 'd' => $computeSeconds], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        $ttlSeconds > 0
            ? $this->redis->client()->setex($this->key($key), $ttlSeconds, $payload)
            : $this->redis->client()->set($this->key($key), $payload);
    }

    private function key(string $key): string
    {
        return 'keel:cache:' . $this->prefix . ':' . $key;
    }
}
