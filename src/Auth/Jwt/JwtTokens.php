<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

use Keel\Auth\Fingerprint\Fingerprint;
use Keel\Auth\Principal;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\Unauthorized;
use Keel\Security\Keys;
use Keel\Support\Random;
use Psr\Clock\ClockInterface;

/**
 * Verifies JWTs from your identity provider and issues short-lived, fingerprint-bound
 * JWTs of your own.
 *
 *     'auth' => ['jwt' => [
 *         'keys' => ['idp-2026' => ['alg' => 'RS256', 'key' => file_get_contents('idp.pem')]],
 *         'issuer' => 'https://login.example.com/', 'audience' => 'api',
 *     ]],
 *
 * `exp` is required; `nbf`, `iss` and `aud` are checked when configured. The tenant comes
 * from `auth.jwt.tenant_claim` and scopes from `scope` (space separated) or `scp`.
 * A token carrying `cnf.kfp` is bound: it is only accepted from the client it was issued to.
 */
#[Singleton]
final readonly class JwtTokens
{
    /** @param array<mixed> $keys */
    public function __construct(
        private ClockInterface $clock,
        private Keys $secrets,
        private Random $random,
        #[Config('auth.jwt.keys')] private array $keys = [],
        #[Config('auth.jwt.issuer')] private string $issuer = '',
        #[Config('auth.jwt.audience')] private string $audience = '',
        #[Config('auth.jwt.leeway_seconds')] private int $leeway = 30,
        #[Config('auth.jwt.tenant_claim')] private string $tenantClaim = 'tenant_id',
        #[Config('auth.jwt.ttl_seconds')] private int $ttl = 900,
    ) {
    }

    /**
     * @return array{principal: Principal, binding: ?string} the principal and the bound fingerprint digest, if any
     */
    public function verify(string $token): array
    {
        $claims = Jwt::verify($token, $this->keyring());
        $now = $this->clock->now()->getTimestamp();
        $exp = $claims['exp'] ?? null;
        if (!\is_int($exp) || $exp + $this->leeway < $now) {
            throw new Unauthorized('The token has expired.', headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        $nbf = $claims['nbf'] ?? null;
        if (\is_int($nbf) && $nbf - $this->leeway > $now) {
            throw new Unauthorized('The token is not valid yet.', headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        if ($this->issuer !== '' && ($claims['iss'] ?? null) !== $this->issuer) {
            throw new Unauthorized('The token was issued by an unexpected issuer.');
        }
        if ($this->audience !== '') {
            $aud = $claims['aud'] ?? null;
            if (!($aud === $this->audience || (\is_array($aud) && \in_array($this->audience, $aud, true)))) {
                throw new Unauthorized('The token is not meant for this API.');
            }
        }
        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || $sub === '') {
            throw new Unauthorized('The token has no subject.');
        }
        $tenant = $claims[$this->tenantClaim] ?? null;
        $scopes = match (true) {
            \is_string($claims['scope'] ?? null) => array_values(array_filter(explode(' ', $claims['scope']))),
            \is_array($claims['scp'] ?? null) => array_values(array_filter($claims['scp'], \is_string(...))),
            default => [],
        };
        $cnf = $claims['cnf'] ?? null;
        $binding = \is_array($cnf) && \is_string($cnf['kfp'] ?? null) ? $cnf['kfp'] : null;
        $jti = $claims['jti'] ?? null;

        return [
            'principal' => new Principal($sub, \is_string($tenant) ? $tenant : null, $scopes, Principal::USER, \is_string($jti) ? $jti : null, $claims),
            'binding' => $binding,
        ];
    }

    /**
     * Issues a short-lived JWT signed with `auth.jwt.signing_key` (a kid in auth.jwt.keys),
     * or with a key derived from app.key when none is configured.
     */
    public function issue(Principal $principal, ?Fingerprint $boundTo = null, ?int $ttlSeconds = null): string
    {
        $now = $this->clock->now()->getTimestamp();
        $claims = [
            'sub' => $principal->id,
            'iat' => $now,
            'exp' => $now + ($ttlSeconds ?? $this->ttl),
            'jti' => bin2hex($this->random->bytes(16)),
            'scope' => implode(' ', $principal->scopes),
        ];
        if ($principal->tenantId !== null) {
            $claims[$this->tenantClaim] = $principal->tenantId;
        }
        if ($this->issuer !== '') {
            $claims['iss'] = $this->issuer;
        }
        if ($this->audience !== '') {
            $claims['aud'] = $this->audience;
        }
        if ($boundTo !== null && !$boundTo->isEmpty()) {
            $claims['cnf'] = ['kfp' => $boundTo->digest()];
        }
        [$kid, $key] = $this->signingKey();

        return Jwt::encode($claims, $key['alg'], $key['private_key'] ?? $key['key'], $kid);
    }

    /** @return array<string, array{alg: string, key: string}> */
    private function keyring(): array
    {
        $ring = [];
        foreach ($this->keys as $kid => $key) {
            if (\is_string($kid) && \is_array($key) && \is_string($key['alg'] ?? null) && \is_string($key['key'] ?? null)) {
                $ring[$kid] = ['alg' => $key['alg'], 'key' => $key['key']];
            }
        }
        // Keel-issued tokens: the algorithm is pinned to this kid, and forging one needs app.key.
        $ring['keel'] ??= ['alg' => 'HS256', 'key' => $this->secrets->derive('jwt')];

        return $ring;
    }

    /** @return array{0: string, 1: array{alg: string, key: string, private_key?: string}} */
    private function signingKey(): array
    {
        foreach ($this->keys as $kid => $key) {
            if (\is_string($kid) && \is_array($key) && \is_string($key['alg'] ?? null) && \is_string($key['key'] ?? null)) {
                $private = $key['private_key'] ?? null;
                if (\is_string($private) || str_starts_with($key['alg'], 'HS')) {
                    return [$kid, ['alg' => $key['alg'], 'key' => $key['key']] + (\is_string($private) ? ['private_key' => $private] : [])];
                }
            }
        }

        return ['keel', ['alg' => 'HS256', 'key' => $this->secrets->derive('jwt')]];
    }
}
