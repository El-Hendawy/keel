<?php

declare(strict_types=1);

use Keel\Http\Idempotency\IdempotencyStore;
use Keel\Http\Idempotency\RedisIdempotencyStore;
use Keel\Http\RateLimit\RateLimitStore;
use Keel\Http\RateLimit\RedisRateLimitStore;
use Keel\Runtime\AppDefinition;

// The shape of the application, read at build time. Runtime values live in config.php.
return AppDefinition::create(__DIR__, 'shop')
    ->scan('src')
    ->config('config.php')
    ->withDatabase()
    // Nodes are stateless in production: limits and idempotency keys live in Redis.
    ->bindIn('prod', RateLimitStore::class, RedisRateLimitStore::class)
    ->bindIn('prod', IdempotencyStore::class, RedisIdempotencyStore::class)
    ->version('0.1.0');
