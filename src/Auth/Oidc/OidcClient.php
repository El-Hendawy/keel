<?php

declare(strict_types=1);

namespace Keel\Auth\Oidc;

use Keel\Auth\Jwt\Jwt;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\Unauthorized;
use Nyholm\Psr7\Request;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Signing users in with the identity provider: the authorization code flow with PKCE
 * (RFC 7636), for a backend that serves its own web app (backend-for-frontend).
 *
 *     $login = $oidc->start();                         // redirect to $login->url
 *     $result = $oidc->finish($code, $state, $login);  // on the callback
 *     $token = $tokens->issue($result['claims']['sub'], ..., client: $request);
 *
 * The ID token is verified (signature from the provider's JWKS, issuer, audience, expiry,
 * nonce) before its claims are returned.
 */
#[Singleton]
final readonly class OidcClient
{
    public function __construct(
        private OidcProvider $provider,
        private ClientInterface $http,
        private ClockInterface $clock,
        #[Config('auth.oidc.client_id')] private string $clientId = '',
        #[Config('auth.oidc.client_secret')] private string $clientSecret = '',
        #[Config('auth.oidc.redirect_uri')] private string $redirectUri = '',
    ) {
    }

    /** @param list<string> $scopes */
    public function start(array $scopes = ['openid', 'profile', 'email']): LoginRequest
    {
        $endpoint = $this->endpoint('authorization_endpoint');
        $state = self::random();
        $nonce = self::random();
        $verifier = self::random() . self::random();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);

        return new LoginRequest($endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query, $state, $nonce, $verifier);
    }

    /**
     * Exchanges the code and verifies the ID token.
     *
     * @return array{claims: array<string, mixed>, access_token: ?string, refresh_token: ?string}
     * @throws Unauthorized when the state, the code or the ID token is not valid
     */
    public function finish(string $code, string $state, LoginRequest $started): array
    {
        if (!hash_equals($started->state, $state)) {
            throw new Unauthorized('The login state does not match. Start the login again.');
        }
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $started->verifier,
        ]);
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($this->clientSecret !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode($this->clientId) . ':' . rawurlencode($this->clientSecret));
        }
        $response = $this->http->sendRequest(new Request('POST', $this->endpoint('token_endpoint'), $headers, $body));
        $tokens = json_decode((string) $response->getBody(), true, 16);
        if ($response->getStatusCode() !== 200 || !\is_array($tokens) || !\is_string($tokens['id_token'] ?? null)) {
            throw new Unauthorized('The identity provider refused the login code.');
        }

        return [
            'claims' => $this->verifyIdToken($tokens['id_token'], $started->nonce),
            'access_token' => \is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null,
            'refresh_token' => \is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function verifyIdToken(string $idToken, string $nonce): array
    {
        [$header] = Jwt::parse($idToken);
        $kid = \is_string($header['kid'] ?? null) ? $header['kid'] : null;
        $key = $this->provider->key($kid) ?? throw new Unauthorized('The ID token was signed with an unknown key.');
        $claims = Jwt::verify($idToken, [$kid ?? 'idp' => $key]);
        $now = $this->clock->now()->getTimestamp();
        $aud = $claims['aud'] ?? null;
        $valid = ($claims['iss'] ?? null) === $this->provider->issuer()
            && ($aud === $this->clientId || (\is_array($aud) && \in_array($this->clientId, $aud, true)))
            && \is_int($claims['exp'] ?? null) && $claims['exp'] + 30 >= $now
            && \is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']);

        return $valid ? $claims : throw new Unauthorized('The ID token is not valid for this login.');
    }

    private function endpoint(string $name): string
    {
        $url = $this->provider->discovery()[$name] ?? null;

        return \is_string($url) ? $url : throw new \RuntimeException("The identity provider does not advertise $name.");
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
