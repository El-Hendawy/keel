<?php

declare(strict_types=1);

namespace Keel\Http\RateLimit;

interface RateLimitStore
{
    /**
     * Takes one token from the bucket identified by $key.
     *
     * @param int $capacity tokens in a full bucket
     * @param float $refillPerSecond tokens added per second
     */
    public function consume(string $key, int $capacity, float $refillPerSecond, float $now): RateLimitResult;
}
