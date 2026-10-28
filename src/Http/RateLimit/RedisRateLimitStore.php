<?php

declare(strict_types=1);

namespace Keel\Http\RateLimit;

use Keel\Container\Attribute\Singleton;
use Keel\Redis\RedisConnection;

/** Token buckets shared by every node, updated atomically with a Lua script. */
#[Singleton]
final readonly class RedisRateLimitStore implements RateLimitStore
{
    private const string SCRIPT = <<<'LUA'
        local capacity = tonumber(ARGV[1])
        local rate = tonumber(ARGV[2])
        local now = tonumber(ARGV[3])
        local bucket = redis.call('HMGET', KEYS[1], 'tokens', 'at')
        local tokens = tonumber(bucket[1]) or capacity
        local at = tonumber(bucket[2]) or now
        tokens = math.min(capacity, tokens + (now - at) * rate)
        local allowed = 0
        if tokens >= 1 then
            tokens = tokens - 1
            allowed = 1
        end
        redis.call('HSET', KEYS[1], 'tokens', tostring(tokens), 'at', tostring(now))
        redis.call('EXPIRE', KEYS[1], math.ceil(capacity / rate) + 1)
        return {allowed, tostring(tokens)}
        LUA;

    public function __construct(private RedisConnection $redis)
    {
    }

    public function consume(string $key, int $capacity, float $refillPerSecond, float $now): RateLimitResult
    {
        /** @var array{0: int, 1: string} $result */
        $result = $this->redis->client()->eval(self::SCRIPT, ['keel:rl:' . $key, (string) $capacity, (string) $refillPerSecond, (string) $now], 1);
        $tokens = (float) $result[1];
        if ($result[0] === 1) {
            return new RateLimitResult(true, (int) floor($tokens), 0);
        }

        return new RateLimitResult(false, 0, (int) ceil((1.0 - $tokens) / $refillPerSecond));
    }
}
