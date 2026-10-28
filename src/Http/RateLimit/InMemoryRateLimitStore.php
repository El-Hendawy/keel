<?php

declare(strict_types=1);

namespace Keel\Http\RateLimit;

use Keel\Container\Attribute\Singleton;

/**
 * Token buckets in worker memory. For development and single-node setups only:
 * a production boot check rejects it, because limits would multiply with every node.
 */
#[Singleton]
final class InMemoryRateLimitStore implements RateLimitStore
{
    private const int MAX_KEYS = 10_000;

    /** @var array<string, array{tokens: float, at: float}> */
    private array $buckets = [];

    public function consume(string $key, int $capacity, float $refillPerSecond, float $now): RateLimitResult
    {
        $bucket = $this->buckets[$key] ?? ['tokens' => (float) $capacity, 'at' => $now];
        $tokens = min((float) $capacity, $bucket['tokens'] + ($now - $bucket['at']) * $refillPerSecond);

        if (\count($this->buckets) >= self::MAX_KEYS && !isset($this->buckets[$key])) {
            array_shift($this->buckets); // bounded memory in a long-running worker
        }

        if ($tokens < 1.0) {
            $this->buckets[$key] = ['tokens' => $tokens, 'at' => $now];

            return new RateLimitResult(false, 0, (int) ceil((1.0 - $tokens) / $refillPerSecond));
        }

        $this->buckets[$key] = ['tokens' => $tokens - 1.0, 'at' => $now];

        return new RateLimitResult(true, (int) floor($tokens - 1.0), 0);
    }
}
