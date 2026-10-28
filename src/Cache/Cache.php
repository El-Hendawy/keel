<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * A key-value cache for JSON-compatible values (null, scalars, arrays). Objects are
 * refused: cache a DTO's data, not the object, so no cache entry can ever instantiate
 * a class when read.
 */
interface Cache
{
    public function get(string $key): mixed;

    /** @param int $ttlSeconds 0 keeps the value until evicted */
    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    /**
     * Returns the cached value or computes, stores and returns it. Protected against
     * stampedes: values are refreshed early with a probability that grows near expiry
     * (XFetch), and a shared cache lets only one worker recompute a missing key.
     *
     * @template T
     * @param \Closure(): T $compute
     * @return T
     */
    public function remember(string $key, int $ttlSeconds, \Closure $compute): mixed;
}
