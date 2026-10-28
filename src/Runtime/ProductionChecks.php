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
        \Keel\Auth\Token\InMemoryTokenStore::class => 'tokens issued on one worker would be unknown to the others and lost on restart. Enable ->withDatabase() or bind TokenStore to a shared store',
    ];

    /** Local-only stores that only matter when the application uses the feature. */
    private const array LOCAL_IF_USED = [
        \Keel\Queue\SyncQueue::class => ['jobs', 'jobs would run inside the request that pushed them, without retries or an outbox. Use ->withQueue()'],
        \Keel\Locks\InMemoryLockStore::class => ['schedule', 'scheduled tasks would run once per node instead of once. Use ->withRedisStores() or ->withQueue()'],
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
            [$feature, $why] = self::LOCAL_IF_USED[$concrete] ?? [null, ''];
            $used = match ($feature) {
                'jobs' => $app->manifest->jobs() !== [],
                'schedule' => $app->manifest->schedule() !== [],
                default => false,
            };
            if ($used) {
                $problems[] = "$abstract is bound to $concrete: $why.";
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
        $key = $config['app.key'] ?? '';
        if (!\is_string($key) || \strlen(\Keel\Security\Keys::decode($key)) < 32) {
            $problems[] = 'app.key is missing or shorter than 32 bytes: token fingerprints, signed cursors and Keel-issued JWTs would use a public development key. Generate one with `keel key:generate` and set it from a secret (e.g. APP_KEY).';
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
