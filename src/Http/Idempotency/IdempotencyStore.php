<?php

declare(strict_types=1);

namespace Keel\Http\Idempotency;

interface IdempotencyStore
{
    /** Claims $key atomically. Returns null if this call now owns it, or the existing record. */
    public function claim(string $key, string $fingerprint, int $ttlSeconds): ?IdempotencyRecord;

    public function complete(string $key, string $fingerprint, StoredResponse $response, int $ttlSeconds): void;

    public function release(string $key): void;
}
