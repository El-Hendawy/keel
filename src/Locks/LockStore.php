<?php

declare(strict_types=1);

namespace Keel\Locks;

/** Short-lived named locks shared by every node: scheduled tasks, cache refills. */
interface LockStore
{
    /** @return string|null a token proving ownership, or null when someone else holds the lock */
    public function acquire(string $name, int $ttlSeconds): ?string;

    /** Releases the lock if $token still owns it. */
    public function release(string $name, string $token): void;
}
