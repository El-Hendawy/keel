<?php

declare(strict_types=1);

namespace Keel\Auth\Oidc;

use Keel\Auth\Jwt\Jwk;
use Keel\Cache\Cache;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Your OpenID Connect identity provider (Auth0, Okta, Entra ID, Keycloak, Google, ...).
 *
 *     'auth' => ['oidc' => ['issuer' => 'https://login.example.com/', 'audience' => 'api']],
 *
 * The discovery document gives the JWKS URL; signing keys are cached (`auth.oidc.jwks_ttl_seconds`)
 * and refetched when a token names a key id the cache does not have, at most once every
 * `auth.oidc.refresh_cooldown_seconds`, so key rotation needs no deploy and a flood of
 * forged key ids cannot hammer the provider.
 */
#[Singleton]
final class OidcProvider
{
    private float $lastRefresh = 0.0;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly Cache $cache,
        private readonly LoggerInterface $logger,
        #[Config('auth.oidc.issuer')] private readonly string $issuer = '',
        #[Config('auth.oidc.jwks_uri')] private readonly string $jwksUri = '',
        #[Config('auth.oidc.jwks_ttl_seconds')] private readonly int $ttl = 3600,
        #[Config('auth.oidc.refresh_cooldown_seconds')] private readonly int $cooldown = 60,
    ) {
    }

    public function enabled(): bool
    {
        return $this->issuer !== '';
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    /**
     * The verification key for a key id, refreshing the JWKS once if it is unknown.
     *
     * @return array{alg: string, key: string}|null
     */
    public function key(?string $kid): ?array
    {
        $keys = $this->keys(false);
        $key = $kid === null ? (\count($keys) === 1 ? reset($keys) : null) : ($keys[$kid] ?? null);
        if ($key === null && microtime(true) - $this->lastRefresh >= $this->cooldown) {
            $keys = $this->keys(true);
            $key = $kid === null ? (\count($keys) === 1 ? reset($keys) : null) : ($keys[$kid] ?? null);
        }

        return $key ?: null;
    }

    /** @return array<string, mixed> the discovery document */
    public function discovery(): array
    {
        /** @var array<string, mixed> */
        return $this->cache->remember('keel:oidc:discovery:' . $this->issuer, $this->ttl, fn (): array => $this->fetch(rtrim($this->issuer, '/') . '/.well-known/openid-configuration'));
    }

    /** @return array<string, array{alg: string, key: string}> */
    private function keys(bool $refresh): array
    {
        $cacheKey = 'keel:oidc:jwks:' . $this->issuer;
        if ($refresh) {
            $this->lastRefresh = microtime(true);
            $this->cache->delete($cacheKey);
        }
        $keys = $this->cache->remember($cacheKey, $this->ttl, function (): array {
            $discovered = $this->discovery()['jwks_uri'] ?? null;
            $uri = $this->jwksUri !== '' ? $this->jwksUri : (\is_string($discovered) ? $discovered : '');
            $set = $this->fetch($uri);
            $keys = [];
            foreach (\is_array($set['keys'] ?? null) ? $set['keys'] : [] as $jwk) {
                if (!\is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig') {
                    continue;
                }
                try {
                    $key = Jwk::toKey($jwk);
                } catch (\InvalidArgumentException) {
                    continue; // a key type we do not use
                }
                $keys[\is_string($jwk['kid'] ?? null) ? $jwk['kid'] : Jwk::thumbprint($jwk)] = $key;
            }

            return $keys;
        });
        return self::valid($keys);
    }

    /**
     * Cached keys may come back from a shared cache: check their shape before use.
     *
     * @return array<string, array{alg: string, key: string}>
     */
    private static function valid(mixed $keys): array
    {
        $valid = [];
        foreach (\is_array($keys) ? $keys : [] as $kid => $key) {
            if (\is_array($key) && \is_string($key['alg'] ?? null) && \is_string($key['key'] ?? null)) {
                $valid[(string) $kid] = ['alg' => $key['alg'], 'key' => $key['key']];
            }
        }

        return $valid;
    }

    /** @return array<mixed> */
    private function fetch(string $url): array
    {
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://localhost') && !str_starts_with($url, 'http://127.0.0.1')) {
            throw new \RuntimeException("OIDC URL $url must use https.");
        }
        $response = $this->http->sendRequest(new Request('GET', $url, ['Accept' => 'application/json']));
        if ($response->getStatusCode() !== 200) {
            $this->logger->error('OIDC: {url} answered {status}.', ['url' => $url, 'status' => $response->getStatusCode()]);
            throw new \RuntimeException("OIDC: $url answered {$response->getStatusCode()}.");
        }
        $data = json_decode((string) $response->getBody(), true, 32, \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : [];
    }
}
