<?php

declare(strict_types=1);

namespace Keel\Auth\Token;

use Keel\Auth\Fingerprint\Fingerprint;
use Keel\Auth\Principal;

/** A token as the store keeps it: only the SHA-256 of the secret, never the secret itself. */
final readonly class StoredToken
{
    /**
     * @param list<string> $scopes
     * @param bool $bindOnFirstUse true for tokens issued without a client (CLI, admin UI): the
     *                             first client to use the token becomes the one it is bound to
     */
    public function __construct(
        public string $id,
        public string $hash,
        public string $principalId,
        public ?string $tenantId,
        public array $scopes,
        public string $kind,
        public Fingerprint $fingerprint,
        public bool $bindOnFirstUse,
        public int $createdAt,
        public ?int $expiresAt,
        public ?int $revokedAt = null,
        public string $name = '',
    ) {
    }

    public function isActive(int $now): bool
    {
        return $this->revokedAt === null && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    public function principal(): Principal
    {
        return new Principal($this->principalId, $this->tenantId, $this->scopes, $this->kind, $this->id, ['name' => $this->name]);
    }

    public function withFingerprint(Fingerprint $fingerprint): self
    {
        return new self($this->id, $this->hash, $this->principalId, $this->tenantId, $this->scopes, $this->kind, $fingerprint, false, $this->createdAt, $this->expiresAt, $this->revokedAt, $this->name);
    }

    public function withRevokedAt(int $at): self
    {
        return new self($this->id, $this->hash, $this->principalId, $this->tenantId, $this->scopes, $this->kind, $this->fingerprint, $this->bindOnFirstUse, $this->createdAt, $this->expiresAt, $at, $this->name);
    }
}
