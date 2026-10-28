<?php

declare(strict_types=1);

namespace Keel\Auth\Token;

use Keel\Auth\Fingerprint\Fingerprint;
use Keel\Container\Attribute\Singleton;
use Keel\Database\ConnectionManager;
use Keel\Database\Driver\Connection;

/**
 * Tokens in the `keel_tokens` table (created by `keel migrate` when ->withAuth() is on).
 * Reads and writes the primary, so a revocation is visible to the next request; with the
 * async PostgreSQL driver the lookup does not block the process.
 */
#[Singleton]
final readonly class DatabaseTokenStore implements TokenStore
{
    public function __construct(private ConnectionManager $connections)
    {
    }

    public function save(StoredToken $token): void
    {
        $this->run(
            'INSERT INTO keel_tokens (id, hash, principal_id, tenant_id, scopes, kind, fingerprint, bind_on_first_use, name, created_at, expires_at, revoked_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
            $token->id,
            $token->hash,
            $token->principalId,
            $token->tenantId,
            json_encode($token->scopes, \JSON_THROW_ON_ERROR),
            $token->kind,
            $token->fingerprint->encode(),
            $token->bindOnFirstUse ? 1 : 0,
            $token->name,
            $token->createdAt,
            $token->expiresAt,
            $token->revokedAt,
        ]);
    }

    public function findByHash(string $hash): ?StoredToken
    {
        $row = $this->connections->with(static fn (Connection $c) => $c->query('SELECT * FROM keel_tokens WHERE hash = ?', [$hash])->rows[0] ?? null);
        if ($row === null) {
            return null;
        }
        $scopes = json_decode(self::string($row, 'scopes'), true, 4, \JSON_THROW_ON_ERROR);

        return new StoredToken(
            self::string($row, 'id'),
            self::string($row, 'hash'),
            self::string($row, 'principal_id'),
            isset($row['tenant_id']) ? self::string($row, 'tenant_id') : null,
            \is_array($scopes) ? array_values(array_filter($scopes, \is_string(...))) : [],
            self::string($row, 'kind'),
            Fingerprint::decode(self::string($row, 'fingerprint')),
            (int) self::string($row, 'bind_on_first_use') === 1,
            (int) self::string($row, 'created_at'),
            isset($row['expires_at']) ? (int) self::string($row, 'expires_at') : null,
            isset($row['revoked_at']) ? (int) self::string($row, 'revoked_at') : null,
            self::string($row, 'name'),
        );
    }

    public function bindOnFirstUse(string $id, Fingerprint $fingerprint): bool
    {
        return $this->run('UPDATE keel_tokens SET fingerprint = ?, bind_on_first_use = 0 WHERE id = ? AND bind_on_first_use = 1', [$fingerprint->encode(), $id]) === 1;
    }

    public function revoke(string $id, int $at): void
    {
        $this->run('UPDATE keel_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [$at, $id]);
    }

    public function revokeAllFor(string $principalId, int $at): int
    {
        return $this->run('UPDATE keel_tokens SET revoked_at = ? WHERE principal_id = ? AND revoked_at IS NULL', [$at, $principalId]);
    }

    /** @param list<scalar|null> $params */
    private function run(string $sql, array $params): int
    {
        return $this->connections->with(static fn (Connection $c): int => $c->query($sql, $params)->affected);
    }

    /** @param array<mixed> $row */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
