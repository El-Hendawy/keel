<?php

declare(strict_types=1);

namespace Keel\Auth\Token;

use Keel\Auth\Fingerprint\Fingerprint;
use Keel\Container\Attribute\Singleton;

/** Per-worker token store for development and tests. Refused in production. */
#[Singleton]
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, StoredToken> hash => token */
    private array $tokens = [];

    public function save(StoredToken $token): void
    {
        $this->tokens[$token->hash] = $token;
    }

    public function findByHash(string $hash): ?StoredToken
    {
        return $this->tokens[$hash] ?? null;
    }

    public function bindOnFirstUse(string $id, Fingerprint $fingerprint): bool
    {
        foreach ($this->tokens as $hash => $token) {
            if ($token->id === $id && $token->bindOnFirstUse) {
                $this->tokens[$hash] = $token->withFingerprint($fingerprint);

                return true;
            }
        }

        return false;
    }

    public function revoke(string $id, int $at): void
    {
        foreach ($this->tokens as $hash => $token) {
            if ($token->id === $id && $token->revokedAt === null) {
                $this->tokens[$hash] = $token->withRevokedAt($at);
            }
        }
    }

    public function revokeAllFor(string $principalId, int $at): int
    {
        $count = 0;
        foreach ($this->tokens as $hash => $token) {
            if ($token->principalId === $principalId && $token->revokedAt === null) {
                $this->tokens[$hash] = $token->withRevokedAt($at);
                $count++;
            }
        }

        return $count;
    }
}
