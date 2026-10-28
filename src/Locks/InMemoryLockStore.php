<?php

declare(strict_types=1);

namespace Keel\Locks;

use Keel\Container\Attribute\Singleton;

/** Locks within one worker. For development and tests. */
#[Singleton]
final class InMemoryLockStore implements LockStore
{
    /** @var array<string, array{token: string, expires: float}> */
    private array $locks = [];

    public function acquire(string $name, int $ttlSeconds): ?string
    {
        $now = microtime(true);
        if (isset($this->locks[$name]) && $this->locks[$name]['expires'] > $now) {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $this->locks[$name] = ['token' => $token, 'expires' => $now + $ttlSeconds];

        return $token;
    }

    public function release(string $name, string $token): void
    {
        if (($this->locks[$name]['token'] ?? null) === $token) {
            unset($this->locks[$name]);
        }
    }
}
