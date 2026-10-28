<?php

declare(strict_types=1);

namespace Keel\Tests\Auth;

use Keel\Auth\Jwt\JwtTokens;
use Keel\Auth\Principal;
use Keel\Auth\Token\Tokens;
use Keel\Testing\TestApp;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/** Authentication, authorization and token binding, end to end against the shop example. */
final class AuthTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../examples/shop';
    private const string TENANT = '11111111-1111-4111-8111-111111111111';

    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = self::boot();
    }

    /** @param array<string, mixed> $config */
    private static function boot(array $config = []): TestApp
    {
        $app = TestApp::boot(self::ROOT . '/app.php', array_replace_recursive(['database' => ['dsn' => \Keel\Testing\TestDatabase::dsn()], 'log' => ['stream' => 'php://memory']], $config));
        $app->freshDatabase(self::ROOT . '/migrations');

        return $app;
    }

    public function testEndpointsAreDeniedByDefault(): void
    {
        $this->app->get('/orders')->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer realm="api"');
        $this->app->get('/me')->assertStatus(401);
        $this->app->get('/health/live')->assertStatus(200); // #[Anonymous]
    }

    public function testScopesAreEnforced(): void
    {
        $reader = $this->app->tokenFor('reader', self::TENANT, ['orders:read']);
        $this->app->get('/orders', $reader)->assertStatus(200);
        $this->app->post('/orders', ['merchantId' => '22222222-2222-4222-8222-222222222222', 'items' => [['sku' => 'A', 'quantity' => 1, 'unitPriceCents' => 1]]], $reader)
            ->assertStatus(403)
            ->assertJsonPath('requiredScopes.0', 'orders:write');
    }

    public function testThePrincipalIsInjected(): void
    {
        $this->app->get('/me', $this->app->tokenFor('ada', self::TENANT, ['orders:read']))
            ->assertStatus(200)
            ->assertJsonPath('id', 'ada')
            ->assertJsonPath('tenantId', self::TENANT)
            ->assertJsonPath('scopes.0', 'orders:read');
    }

    public function testInvalidAndRevokedTokensAreRejected(): void
    {
        $this->app->get('/me', ['Authorization' => 'Bearer keel_nope'])->assertStatus(401);
        $this->app->get('/me', ['Authorization' => 'Basic abc'])->assertStatus(401);

        $user = $this->app->tokenFor('ada', self::TENANT);
        $this->app->get('/me', $user)->assertStatus(200);
        $this->app->delete('/tokens/current', $user)->assertStatus(204);
        $this->app->get('/me', $user)->assertStatus(401);
    }

    public function testExpiredTokensAreRejected(): void
    {
        $issued = $this->app->container()->get(Tokens::class)->issue('ada', self::TENANT, ['*'], ttlSeconds: 60);
        $headers = ['Authorization' => 'Bearer ' . $issued->token];
        $this->app->get('/me', $headers)->assertStatus(200);
        $this->app->clock()->advance('+61 seconds');
        $this->app->get('/me', $headers)->assertStatus(401);
    }

    public function testATokenIsBoundToTheFirstClientAndRevokedWhenStolen(): void
    {
        $user = $this->app->tokenFor('ada', self::TENANT);

        // First use binds the token to this client.
        $this->app->get('/me', $user + ['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/131.0.1', 'Accept-Language' => 'nl-BE,nl;q=0.9'])->assertStatus(200);
        // A browser update keeps working: versions are not part of the fingerprint.
        $this->app->get('/me', $user + ['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/132.0.4', 'Accept-Language' => 'nl'])->assertStatus(200);

        // The same token from another client is refused, and revoked.
        $this->app->get('/me', $user + ['User-Agent' => 'curl/8.4.0'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'token_binding_mismatch');
        $this->app->get('/me', $user + ['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/132.0.4', 'Accept-Language' => 'nl'])->assertStatus(401);

        self::assertStringContainsString('auth_fingerprint_mismatches_total{mode="enforce"', $this->app->get('/metrics')->body());
    }

    public function testReportModeAllowsButRecordsMismatches(): void
    {
        $app = self::boot(['auth' => ['fingerprint' => ['mode' => 'report']], 'log' => ['stream' => 'php://memory']]);
        $user = $app->tokenFor('ada', self::TENANT);
        $app->get('/me', $user)->assertStatus(200);
        $app->get('/me', $user + ['User-Agent' => 'python-requests/2.31'])->assertStatus(200);
        self::assertStringContainsString('auth_fingerprint_mismatches_total{mode="report"', $app->get('/metrics')->body());
    }

    public function testTokensIssuedDuringARequestAreBoundImmediately(): void
    {
        $login = new ServerRequest('POST', '/login', ['User-Agent' => 'MyApp/2.0 (iOS)']);
        $issued = $this->app->container()->get(Tokens::class)->issue('ada', self::TENANT, ['*'], client: $login);
        $headers = ['Authorization' => 'Bearer ' . $issued->token];

        $this->app->get('/me', $headers + ['User-Agent' => 'MyApp/2.1 (iOS)'])->assertStatus(200);
        $this->app->get('/me', $headers)->assertStatus(401); // the test client's own user agent
    }

    public function testFingerprintsOnlyTrustTheTlsHeaderFromProxies(): void
    {
        $issued = $this->app->container()->get(Tokens::class)->issue('ada', self::TENANT, ['*']);
        $headers = ['Authorization' => 'Bearer ' . $issued->token];
        // REMOTE_ADDR is 127.0.0.1 and no proxy is trusted, so a client-sent JA4 header is ignored.
        $this->app->get('/me', $headers + ['X-JA4-Fingerprint' => 't13d1516h2_8daaf6152771_02713d6af862'])->assertStatus(200);
        $this->app->get('/me', $headers + ['X-JA4-Fingerprint' => 'forged'])->assertStatus(200);
    }

    public function testJwtsAreVerifiedAndCanBeBound(): void
    {
        $jwt = $this->app->container()->get(JwtTokens::class);
        $principal = new Principal('ada', self::TENANT, ['orders:read']);

        $unbound = $jwt->issue($principal);
        $this->app->get('/me', ['Authorization' => "Bearer $unbound"])->assertStatus(200)->assertJsonPath('id', 'ada');

        $client = new ServerRequest('GET', '/', ['User-Agent' => \Keel\Testing\TestApp::USER_AGENT]);
        $bound = $jwt->issue($principal, $this->app->container()->get(\Keel\Auth\Fingerprint\ClientFingerprint::class)->of($client));
        $this->app->get('/me', ['Authorization' => "Bearer $bound"])->assertStatus(200);
        $this->app->get('/me', ['Authorization' => "Bearer $bound", 'User-Agent' => 'evil/1.0'])->assertStatus(401);

        // Tampering with the payload breaks the signature.
        [$h, , $s] = explode('.', $unbound);
        $forged = $h . '.' . rtrim(strtr(base64_encode('{"sub":"admin","exp":9999999999,"scope":"*"}'), '+/', '-_'), '=') . '.' . $s;
        $this->app->get('/me', ['Authorization' => "Bearer $forged"])->assertStatus(401);

        $this->app->clock()->advance('+1 hour');
        $this->app->get('/me', ['Authorization' => "Bearer $unbound"])->assertStatus(401);
    }

    public function testIdentityProviderAndKeelTokensWorkSideBySide(): void
    {
        $idpKey = 'idp-shared-secret-at-least-32-bytes!!';
        $app = self::boot(['auth' => ['jwt' => ['keys' => ['idp' => ['alg' => 'HS256', 'key' => $idpKey]], 'tenant_claim' => 'org']]]);
        $fromIdp = \Keel\Auth\Jwt\Jwt::encode(['sub' => 'grace', 'org' => self::TENANT, 'exp' => 1_900_000_000, 'scp' => ['orders:read']], 'HS256', $idpKey, 'idp');
        $app->get('/me', ['Authorization' => "Bearer $fromIdp"])->assertStatus(200)->assertJsonPath('tenantId', self::TENANT)->assertJsonPath('scopes.0', 'orders:read');

        $own = $app->container()->get(JwtTokens::class)->issue(new Principal('ada', self::TENANT));
        $app->get('/me', ['Authorization' => "Bearer $own"])->assertStatus(200)->assertJsonPath('id', 'ada');
    }

    public function testOpenApiDescribesSecurity(): void
    {
        $doc = json_decode((string) file_get_contents($this->app->application->manifest->directory . '/openapi.json'), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($doc);
        self::assertSame('bearer', $doc['components']['securitySchemes']['bearer']['scheme']);
        self::assertSame([['bearer' => ['orders:write']]], $doc['paths']['/orders']['post']['security']);
        self::assertArrayHasKey('403', $doc['paths']['/orders']['post']['responses']);
        self::assertSame([], $doc['paths']['/health/live']['get']['security']);
    }
}
