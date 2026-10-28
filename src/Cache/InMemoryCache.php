<?php

declare(strict_types=1);

namespace Keel\Cache;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;

/**
 * A per-worker LRU cache, bounded by `cache.max_items`. Each worker has its own copy:
 * use it for data that may be briefly stale per node, and RedisCache for shared data.
 */
#[Singleton]
final class InMemoryCache implements Cache
{
    /** @var array<string, Entry> */
    private array $entries = [];

    public function __construct(#[Config('cache.max_items')] private readonly int $maxItems = 10_000)
    {
    }

    public function get(string $key): mixed
    {
        $entry = $this->entry($key);

        return $entry?->value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->put($key, $value, $ttlSeconds, 0.0);
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function remember(string $key, int $ttlSeconds, \Closure $compute): mixed
    {
        $entry = $this->entry($key);
        if ($entry !== null && !$entry->shouldRefresh(microtime(true))) {
            return $entry->value;
        }
        $started = microtime(true);
        $value = $compute();
        $this->put($key, $value, $ttlSeconds, microtime(true) - $started);

        return $value;
    }

    private function entry(string $key): ?Entry
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry->expiresAt !== 0.0 && $entry->expiresAt <= microtime(true)) {
            unset($this->entries[$key]);

            return null;
        }
        // Move to the end: the array's order is the recency order.
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;

        return $entry;
    }

    private function put(string $key, mixed $value, int $ttlSeconds, float $computeSeconds): void
    {
        Entry::check($value);
        unset($this->entries[$key]);
        $this->entries[$key] = new Entry($value, $ttlSeconds > 0 ? microtime(true) + $ttlSeconds : 0.0, $computeSeconds);
        while (\count($this->entries) > $this->maxItems && ($oldest = array_key_first($this->entries)) !== null) {
            unset($this->entries[$oldest]);
        }
    }
}
