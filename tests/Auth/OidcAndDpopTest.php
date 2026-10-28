<?php

declare(strict_types=1);

namespace Keel\Tests\Auth;

use Keel\Auth\Dpop\DpopProof;
use Keel\Auth\Dpop\DpopVerifier;
use Keel\Auth\Jwt\Jwk;
use Keel\Auth\Jwt\Jwt;
use Keel\Auth\Oidc\OidcClient;
use Keel\Auth\Token\Tokens;
use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Tokens from an OpenID Connect provider (JWKS, rotation, login flow) and DPoP proof of possession. */
final class OidcAndDpopTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../examples/shop';
    private const string ISSUER = 'https://login.example.com/';
    private const string TENANT = '11111111-1111-4111-8111-111111111111';

    /** @param array<string, mixed> $config */
    private static function boot(array $config = []): TestApp
    {
        $app = TestApp::boot(self::ROOT . '/app.php', array_replace_recursive([
            'database' => ['dsn' => TestDatabase::dsn()],
            'log' => ['stream' => 'php://memory'],
            'auth' => ['oidc' => ['issuer' => self::ISSUER, 'audience' => 'shop-api', 'client_id' => 'shop-web', 'redirect_uri' => 'https://shop.example/callback']],
        ], $config));
        $app->freshDatabase(self::ROOT . '/migrations');

        return $app;
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string, 2: array<string, string>} key, PEM, public JWK */
    private static function key(string $type = 'EC'): array
    {
        $key = openssl_pkey_new($type === 'EC' ? ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] : ['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        openssl_pkey_export($key, $pem);
        self::assertIsString($pem);

        return [$key, $pem, Jwk::fromOpenSsl($key)];
    }

    /** @param list<array<string, string>> $jwks */
    private static function provider(TestApp $app, array &$jwks, int &$fetches): void
    {
        $app->http()
            ->stub('GET', self::ISSUER . '.well-known/openid-configuration', [
                'issuer' => self::ISSUER,
                'jwks_uri' => self::ISSUER . 'jwks',
                'authorization_endpoint' => self::ISSUER . 'authorize',
                'token_endpoint' => self::ISSUER . 'token',
            ])
            ->stub('GET', self::ISSUER . 'jwks', static function () use (&$jwks, &$fetches): Response {
                $fetches++;

                return new Response(200, [], json_encode(['keys' => $jwks], \JSON_THROW_ON_ERROR));
            });
    }

    public function testIdentityProviderTokensAreVerifiedWithRotatingKeys(): void
    {
        $app = self::boot();
        [, $pem1, $jwk1] = self::key('RSA');
        [, $pem2, $jwk2] = self::key('EC');
        $jwks = [$jwk1 + ['kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256']];
        $fetches = 0;
        self::provider($app, $jwks, $fetches);
        $now = $app->clock()->now()->getTimestamp();
        $claims = ['iss' => self::ISSUER, 'aud' => 'shop-api', 'sub' => 'auth0|ada', 'tenant_id' => self::TENANT, 'scope' => 'orders:read', 'exp' => $now + 300];

        $token = Jwt::encode($claims, 'RS256', $pem1, 'k1');
        $app->get('/me', ['Authorization' => "Bearer $token"])->assertStatus(200)->assertJsonPath('id', 'auth0|ada');
        $app->get('/me', ['Authorization' => "Bearer $token"])->assertStatus(200);
        self::assertSame(1, $fetches, 'Keys are cached.');

        // The provider rotates to a new (EC) key: the unknown kid triggers one refetch.
        $jwks[] = $jwk2 + ['kid' => 'k2', 'use' => 'sig', 'alg' => 'ES256'];
        $app->get('/me', ['Authorization' => 'Bearer ' . Jwt::encode($claims, 'ES256', $pem2, 'k2')])->assertStatus(200);
        self::assertSame(2, $fetches);

        // Forged key ids do not hammer the provider (cool-down).
        [, $pem3] = self::key('EC');
        $app->get('/me', ['Authorization' => 'Bearer ' . Jwt::encode($claims, 'ES256', $pem3, 'k-forged')])->assertStatus(401);
        self::assertSame(2, $fetches);

        $app->get('/me', ['Authorization' => 'Bearer ' . Jwt::encode(['aud' => 'other-api'] + $claims, 'RS256', $pem1, 'k1')])->assertStatus(401);
        $app->get('/me', ['Authorization' => 'Bearer ' . Jwt::encode(['iss' => 'https://evil.example/'] + $claims, 'RS256', $pem1, 'k1')])->assertStatus(401);
    }

    public function testTheLoginFlowVerifiesTheIdToken(): void
    {
        $app = self::boot();
        [, $pem, $jwk] = self::key('RSA');
        $jwks = [$jwk + ['kid' => 'k1', 'use' => 'sig']];
        $fetches = 0;
        self::provider($app, $jwks, $fetches);
        $client = $app->container()->get(OidcClient::class);
        $login = $client->start();
        parse_str((string) parse_url($login->url, \PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('shop-web', $query['client_id']);

        $exchanged = null;
        $app->http()->stub('POST', self::ISSUER . 'token', static function (RequestInterface $request) use ($pem, $login, $app, &$exchanged): Response {
            parse_str((string) $request->getBody(), $form);
            $exchanged = $form;

            return new Response(200, [], json_encode(['access_token' => 'at', 'id_token' => Jwt::encode([
                'iss' => self::ISSUER, 'aud' => 'shop-web', 'sub' => 'ada', 'nonce' => $login->nonce, 'exp' => $app->clock()->now()->getTimestamp() + 60,
            ], 'RS256', $pem, 'k1')], \JSON_THROW_ON_ERROR));
        });
        $result = $client->finish('the-code', $login->state, $login);
        self::assertSame('ada', $result['claims']['sub']);
        self::assertIsArray($exchanged);
        self::assertSame($login->verifier, $exchanged['code_verifier']);

        $this->expectException(\Keel\Http\Exception\Unauthorized::class);
        $client->finish('the-code', 'tampered-state', $login);
    }

    public function testDpopBoundTokensNeedAProofFromTheirKey(): void
    {
        $app = self::boot(['auth' => ['oidc' => ['issuer' => '']]]);
        [$key] = self::key('EC');
        $verifier = $app->container()->get(DpopVerifier::class);
        $url = 'http://localhost/me';

        // A login endpoint would verify the client's proof and bind the token to its key.
        $login = (new ServerRequest('POST', 'http://localhost/login', ['DPoP' => DpopProof::create($key, 'POST', 'http://localhost/login', now: $app->clock()->now()->getTimestamp()), 'User-Agent' => TestApp::USER_AGENT]));
        $jkt = $verifier->verify($login);
        $issued = $app->container()->get(Tokens::class)->issue('ada', self::TENANT, ['*'], client: $login, dpopKey: $jkt);

        $proof = static fn (string $method = 'GET', string $u = 'http://localhost/me', ?string $token = null) => DpopProof::create($key, $method, $u, $token ?? $issued->token, $app->clock()->now()->getTimestamp());
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => $proof()])->assertStatus(200)->assertJsonPath('id', 'ada');

        $app->get('/me', ['Authorization' => "Bearer {$issued->token}"])->assertStatus(401)->assertJsonPath('code', 'invalid_dpop_proof');
        $replayed = $proof();
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => $replayed])->assertStatus(200);
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => $replayed])->assertStatus(401);
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => $proof('POST')])->assertStatus(401);
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => $proof('GET', 'http://localhost/orders')])->assertStatus(401);
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => $proof('GET', $url, 'other-token')])->assertStatus(401);

        // Another key, even with a valid proof of its own, cannot use the token.
        [$thief] = self::key('EC');
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => DpopProof::create($thief, 'GET', $url, $issued->token, $app->clock()->now()->getTimestamp())])->assertStatus(401);

        $app->clock()->advance('+5 minutes');
        $app->get('/me', ['Authorization' => "DPoP {$issued->token}", 'DPoP' => DpopProof::create($key, 'GET', $url, $issued->token, $app->clock()->now()->getTimestamp() - 3600)])->assertStatus(401);
    }

    public function testJwksConversionAndThumbprints(): void
    {
        // RFC 7638 section 3.1 example key and thumbprint.
        $jwk = ['kty' => 'RSA', 'e' => 'AQAB', 'n' => '0vx7agoebGcQSuuPiLJXZptN9nndrQmbXEps2aiAFbWhM78LhWx4cbbfAAtVT86zwu1RK7aPFFxuhDR1L6tSoc_BJECPebWKRXjBZCiFV4n3oknjhMstn64tZ_2W-5JsGY4Hc5n9yBXArwl93lqt7_RN5w6Cf0h4QyQ5v-65YGjQR0_FDW2QvzqY368QQMicAtaSqzs8KJZgnYb9c7d0zgdAZHzu6qMQvRL5hajrn1n91CbOpbISD08qNLyrdkt-bFTWhAI4vMQFh6WeZu0fM4lFd2NcRwr3XPksINHaQ-G_xBniIqbw0Ls1jF44-csFCur-kEgU8awapJzKnqDKgw'];
        self::assertSame('NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs', Jwk::thumbprint($jwk));
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', Jwk::toKey($jwk)['key']);
        self::assertNotFalse(openssl_pkey_get_public(Jwk::toKey($jwk)['key']));

        $this->expectException(\InvalidArgumentException::class);
        Jwk::toKey($jwk + ['d' => 'secret']);
    }
}
