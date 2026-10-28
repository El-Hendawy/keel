<?php

declare(strict_types=1);

namespace Keel\Auth\Token;

use Keel\Auth\Fingerprint\ClientFingerprint;
use Keel\Auth\Fingerprint\Fingerprint;
use Keel\Auth\Principal;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Support\IdGenerator;
use Keel\Support\Random;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Issues and revokes opaque bearer tokens (`keel_...`).
 *
 * A token issued while handling a request (a login endpoint) is bound to that client's
 * fingerprint. A token issued without one (`keel token:issue`, an admin screen) is bound
 * to the first client that uses it. Binding is on by default; see ClientFingerprint. With
 * $dpopKey (a JWK thumbprint from DpopVerifier) the token also requires DPoP proofs signed
 * with that key, which is cryptographic proof of possession.
 * The `keel_` prefix lets secret scanners recognise leaked tokens.
 */
#[Singleton]
final readonly class Tokens
{
    public const string PREFIX = 'keel_';

    public function __construct(
        private TokenStore $store,
        private ClientFingerprint $fingerprints,
        private IdGenerator $ids,
        private Random $random,
        private ClockInterface $clock,
        #[Config('auth.binding')] private bool $binding = true,
        #[Config('auth.tokens.ttl_seconds')] private int $defaultTtl = 86_400,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @param int|null $ttlSeconds null uses auth.tokens.ttl_seconds; 0 never expires (API keys)
     * @param ServerRequestInterface|null $client the request of the client that will hold the token
     */
    public function issue(
        string $principalId,
        ?string $tenantId = null,
        array $scopes = [],
        string $kind = Principal::USER,
        ?int $ttlSeconds = null,
        ?ServerRequestInterface $client = null,
        string $name = '',
        ?string $dpopKey = null,
    ): IssuedToken {
        $secret = self::PREFIX . rtrim(strtr(base64_encode($this->random->bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now()->getTimestamp();
        $ttl = $ttlSeconds ?? $this->defaultTtl;
        $fingerprint = $this->binding && $client !== null ? $this->fingerprints->of($client) : new Fingerprint([]);
        if ($dpopKey !== null) {
            $fingerprint = $fingerprint->with('dpop', $dpopKey);
        }
        $token = new StoredToken(
            $this->ids->generate(),
            self::hash($secret),
            $principalId,
            $tenantId,
            $scopes,
            $kind,
            $fingerprint,
            $this->binding && $client === null,
            $now,
            $ttl > 0 ? $now + $ttl : null,
            null,
            $name,
        );
        $this->store->save($token);

        return new IssuedToken($token->id, $secret, $token->expiresAt);
    }

    public function revoke(string $tokenId): void
    {
        $this->store->revoke($tokenId, $this->clock->now()->getTimestamp());
    }

    public function revokeAllFor(string $principalId): int
    {
        return $this->store->revokeAllFor($principalId, $this->clock->now()->getTimestamp());
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
