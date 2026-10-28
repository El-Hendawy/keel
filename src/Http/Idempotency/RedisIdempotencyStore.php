<?php

declare(strict_types=1);

namespace Keel\Http\Idempotency;

use Keel\Container\Attribute\Singleton;
use Keel\Redis\RedisConnection;

#[Singleton]
final readonly class RedisIdempotencyStore implements IdempotencyStore
{
    public function __construct(private RedisConnection $redis)
    {
    }

    public function claim(string $key, string $fingerprint, int $ttlSeconds): ?IdempotencyRecord
    {
        $redis = $this->redis->client();
        $claimed = $redis->set('keel:idem:' . $key, json_encode(['f' => $fingerprint, 'r' => null], \JSON_THROW_ON_ERROR), ['nx', 'ex' => $ttlSeconds]);
        if ($claimed === true) {
            return null;
        }
        $raw = $redis->get('keel:idem:' . $key);
        if (!\is_string($raw)) {
            return $this->claim($key, $fingerprint, $ttlSeconds); // expired between SET and GET
        }
        /** @var array{f: string, r: string|null} $data */
        $data = json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);

        return new IdempotencyRecord($data['f'], $data['r'] === null ? null : StoredResponse::decode($data['r']));
    }

    public function complete(string $key, string $fingerprint, StoredResponse $response, int $ttlSeconds): void
    {
        $this->redis->client()->set('keel:idem:' . $key, json_encode(['f' => $fingerprint, 'r' => $response->encode()], \JSON_THROW_ON_ERROR), ['ex' => $ttlSeconds]);
    }

    public function release(string $key): void
    {
        $this->redis->client()->del('keel:idem:' . $key);
    }
}
