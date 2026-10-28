<?php

declare(strict_types=1);

namespace Keel\Http\Client;

/**
 * Per-host circuit breaker, kept in worker memory.
 *
 * Closed: calls pass. After `threshold` consecutive failures it opens and calls fail
 * immediately for `cooldown` seconds; then one trial call is let through (half-open).
 */
final class CircuitBreaker
{
    /** @var array<string, array{failures: int, openedAt: ?float, trial: bool}> */
    private array $hosts = [];

    public function __construct(private readonly int $threshold = 5, private readonly float $cooldown = 30.0)
    {
    }

    public function allow(string $host, float $now): bool
    {
        $state = $this->hosts[$host] ?? null;
        if ($state === null || $state['openedAt'] === null) {
            return true;
        }
        if ($now - $state['openedAt'] >= $this->cooldown && !$state['trial']) {
            $this->hosts[$host]['trial'] = true;

            return true;
        }

        return false;
    }

    public function success(string $host): void
    {
        unset($this->hosts[$host]);
    }

    public function failure(string $host, float $now): void
    {
        $state = $this->hosts[$host] ?? ['failures' => 0, 'openedAt' => null, 'trial' => false];
        $state['failures']++;
        if ($state['trial'] || $state['failures'] >= $this->threshold) {
            $state['openedAt'] = $now;
            $state['trial'] = false;
        }
        $this->hosts[$host] = $state;
    }
}
