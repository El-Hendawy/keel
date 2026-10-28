<?php

declare(strict_types=1);

namespace Keel\Http\RateLimit;

final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfterSeconds,
    ) {
    }
}
