<?php

declare(strict_types=1);

namespace Keel\Redis;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Container\Disposable;

/** One lazily opened, persistent phpredis connection per worker. */
#[Singleton]
final class RedisConnection implements Disposable
{
    private ?\Redis $redis = null;

    public function __construct(
        #[Config('redis.url')] private readonly string $url = 'redis://127.0.0.1:6379',
        #[Config('redis.timeout_seconds')] private readonly float $timeout = 1.0,
    ) {
    }

    public function client(): \Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        $parts = parse_url($this->url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('redis.url is not a valid URL.');
        }
        $redis = new \Redis();
        $redis->pconnect($parts['host'], $parts['port'] ?? 6379, $this->timeout);
        if (isset($parts['pass'])) {
            $redis->auth(isset($parts['user']) ? [$parts['user'], $parts['pass']] : $parts['pass']);
        }
        $db = (int) ltrim($parts['path'] ?? '', '/');
        if ($db > 0) {
            $redis->select($db);
        }

        return $this->redis = $redis;
    }

    public function dispose(): void
    {
        $this->redis = null;
    }
}
