<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Auth\Jwt\JwtTokens;
use Keel\Auth\Principal;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Nyholm\Psr7\Request;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Credentials for calls to other services, cached until shortly before they expire.
 *
 * - `service.auth = 'jwt'` (default): a short-lived JWT signed with this application's
 *   `auth.jwt` keys, subject `service:<service.name>`, audience the target service, and the
 *   current tenant. Receivers verify it with the shared public key (RS256/ES256) or JWKS.
 * - `service.auth = 'client_credentials'`: an OAuth 2.0 access token from your identity
 *   provider (`service.token_url`, `client_id`, `client_secret`), per audience.
 * - `service.auth = 'none'`: no credentials (a service mesh with mTLS authenticates instead).
 */
#[Singleton]
final class ServiceTokens
{
    /** @var array<string, array{0: string, 1: int}> cache key => [token, expires at] */
    private array $cache = [];

    public function __construct(
        private readonly JwtTokens $jwt,
        private readonly ClientInterface $http,
        private readonly ClockInterface $clock,
        #[Config('service.name')] private readonly string $name = 'app',
        #[Config('service.auth')] private readonly string $mode = 'jwt',
        #[Config('service.token_ttl_seconds')] private readonly int $ttl = 300,
        #[Config('service.token_url')] private readonly string $tokenUrl = '',
        #[Config('service.client_id')] private readonly string $clientId = '',
        #[Config('service.client_secret')] private readonly string $clientSecret = '',
    ) {
    }

    /**
     * @param list<string> $scopes
     * @return string|null an Authorization header value, or null when service.auth is none
     */
    public function authorization(string $audience, ?string $tenant, array $scopes = []): ?string
    {
        $now = $this->clock->now()->getTimestamp();
        $key = $this->mode . '|' . $audience . '|' . $tenant . '|' . implode(' ', $scopes);
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null && $cached[1] - 30 > $now) {
            return $cached[0];
        }
        $issued = match ($this->mode) {
            'none' => null,
            'jwt' => ['Bearer ' . $this->jwt->issue(new Principal('service:' . $this->name, $tenant, $scopes, Principal::SERVICE), ttlSeconds: $this->ttl, audience: $audience), $now + $this->ttl],
            'client_credentials' => $this->clientCredentials($audience, $scopes, $now),
            default => throw new \InvalidArgumentException("service.auth is \"{$this->mode}\": use jwt, client_credentials or none."),
        };
        if ($issued === null) {
            return null;
        }
        if (\count($this->cache) > 1000) {
            $this->cache = [];
        }
        $this->cache[$key] = $issued;

        return $issued[0];
    }

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: int}
     */
    private function clientCredentials(string $audience, array $scopes, int $now): array
    {
        if ($this->tokenUrl === '' || $this->clientId === '') {
            throw new \InvalidArgumentException('service.auth = client_credentials needs service.token_url, service.client_id and service.client_secret.');
        }
        $body = http_build_query(array_filter(['grant_type' => 'client_credentials', 'audience' => $audience, 'scope' => implode(' ', $scopes)], static fn (string $v): bool => $v !== ''));
        $response = $this->http->sendRequest(new Request('POST', $this->tokenUrl, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode(rawurlencode($this->clientId) . ':' . rawurlencode($this->clientSecret)),
        ], $body));
        $token = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !\is_array($token) || !\is_string($token['access_token'] ?? null)) {
            throw new ServiceCallFailed('token endpoint', $response->getStatusCode(), 'Could not obtain a service token from ' . $this->tokenUrl . '.');
        }
        return ['Bearer ' . $token['access_token'], $now + (\is_int($token['expires_in'] ?? null) ? $token['expires_in'] : 300)];
    }
}
