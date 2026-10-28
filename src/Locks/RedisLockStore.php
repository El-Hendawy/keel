<?php

declare(strict_types=1);

namespace Keel\Locks;

use Keel\Container\Attribute\Singleton;
use Keel\Redis\RedisConnection;

/** SET NX PX locks, released with a compare-and-delete script so a lock is only freed by its owner. */
#[Singleton]
final readonly class RedisLockStore implements LockStore
{
    private const string RELEASE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

    public function __construct(private RedisConnection $redis)
    {
    }

    public function acquire(string $name, int $ttlSeconds): ?string
    {
        $token = bin2hex(random_bytes(16));

        return $this->redis->client()->set('keel:lock:' . $name, $token, ['nx', 'px' => max(1, $ttlSeconds) * 1000]) === true ? $token : null;
    }

    public function release(string $name, string $token): void
    {
        $this->redis->client()->eval(self::RELEASE, ['keel:lock:' . $name, $token], 1);
    }
}
