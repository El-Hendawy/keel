<?php

declare(strict_types=1);

namespace Keel\Http\Idempotency;

use Keel\Container\Attribute\Singleton;

/** Development and test store. Rejected by the production boot check: keys must be shared between nodes. */
#[Singleton]
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    private const int MAX_KEYS = 10_000;

    /** @var array<string, array{record: IdempotencyRecord, expires: float}> */
    private array $records = [];

    public function claim(string $key, string $fingerprint, int $ttlSeconds): ?IdempotencyRecord
    {
        $now = microtime(true);
        $existing = $this->records[$key] ?? null;
        if ($existing !== null && $existing['expires'] > $now) {
            return $existing['record'];
        }
        if (\count($this->records) >= self::MAX_KEYS) {
            array_shift($this->records);
        }
        $this->records[$key] = ['record' => new IdempotencyRecord($fingerprint, null), 'expires' => $now + $ttlSeconds];

        return null;
    }

    public function complete(string $key, string $fingerprint, StoredResponse $response, int $ttlSeconds): void
    {
        $this->records[$key] = ['record' => new IdempotencyRecord($fingerprint, $response), 'expires' => microtime(true) + $ttlSeconds];
    }

    public function release(string $key): void
    {
        unset($this->records[$key]);
    }
}
