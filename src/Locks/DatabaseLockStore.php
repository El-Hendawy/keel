<?php

declare(strict_types=1);

namespace Keel\Locks;

use Keel\Container\Attribute\Singleton;
use Keel\Database\ConnectionManager;

/** Locks as rows in `keel_locks`, relying on the primary key for mutual exclusion. */
#[Singleton]
final readonly class DatabaseLockStore implements LockStore
{
    public function __construct(private ConnectionManager $connections)
    {
    }

    public function acquire(string $name, int $ttlSeconds): ?string
    {
        $pdo = $this->connections->writer();
        $now = time();
        $pdo->prepare('DELETE FROM keel_locks WHERE name = ? AND expires_at < ?')->execute([$name, $now]);
        $token = bin2hex(random_bytes(16));
        try {
            $pdo->prepare('INSERT INTO keel_locks (name, token, expires_at) VALUES (?, ?, ?)')->execute([$name, $token, $now + max(1, $ttlSeconds)]);
        } catch (\PDOException) {
            return null; // primary key taken: someone holds it
        }

        return $token;
    }

    public function release(string $name, string $token): void
    {
        $this->connections->writer()->prepare('DELETE FROM keel_locks WHERE name = ? AND token = ?')->execute([$name, $token]);
    }
}
