<?php

declare(strict_types=1);

namespace Keel\Auth\Token;

use Keel\Auth\Fingerprint\Fingerprint;

/** Where issued tokens live. Must be shared by every node in production. */
interface TokenStore
{
    public function save(StoredToken $token): void;

    public function findByHash(string $hash): ?StoredToken;

    /**
     * Binds an unbound token to its first client. Atomic: returns false if another
     * request bound it first, in which case the caller must re-read and compare.
     */
    public function bindOnFirstUse(string $id, Fingerprint $fingerprint): bool;

    public function revoke(string $id, int $at): void;

    /** Revokes every token of a principal, e.g. on password change. Returns how many. */
    public function revokeAllFor(string $principalId, int $at): int;
}
