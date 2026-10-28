<?php

declare(strict_types=1);

namespace Keel\Runtime;

/**
 * Refuses to boot in production with settings that only work on one node or that
 * leak information. Each failure says what to change.
 */
final class ProductionChecks
{
    private const array LOCAL_ONLY = [
        \Keel\Http\RateLimit\InMemoryRateLimitStore::class => 'rate limits would apply per worker, not per client. Use ->withRedisStores() or bind RateLimitStore to a shared store',
        \Keel\Http\Idempotency\InMemoryIdempotencyStore::class => 'a retried request could hit another node and run twice. Use ->withRedisStores() or bind IdempotencyStore to a shared store',
    ];

    /** @return list<string> */
    public static function problems(Application $app): array
    {
        $problems = [];
        if ($app->environment->debug) {
            $problems[] = 'Debug is on in production: error responses would expose stack traces. Unset KEEL_DEBUG.';
        }
        foreach ($app->manifest->bindings() as $abstract => $concrete) {
            if (isset(self::LOCAL_ONLY[$concrete])) {
                $problems[] = "$abstract is bound to $concrete: " . self::LOCAL_ONLY[$concrete] . '.';
            }
        }
        $config = $app->container()->configuration();
        $proxies = $config['http.trusted_proxies'] ?? [];
        if (\is_array($proxies) && array_intersect(array_filter($proxies, \is_string(...)), ['0.0.0.0/0', '::/0', '*']) !== []) {
            $problems[] = 'trustProxy() trusts every address, so any client can spoof X-Forwarded-For. List your proxies explicitly.';
        }
        if (($config['metrics.enabled'] ?? true) !== false && ($config['metrics.token'] ?? '') === '') {
            $problems[] = 'The /metrics endpoint is public. Set metrics.token or metrics.enabled = false.';
        }

        return $problems;
    }

    public static function assert(Application $app): void
    {
        $problems = self::problems($app);
        if ($problems !== []) {
            throw new BootFailed("Refusing to boot in production:\n  - " . implode("\n  - ", $problems));
        }
    }
}
