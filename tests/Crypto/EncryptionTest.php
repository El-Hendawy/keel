<?php

declare(strict_types=1);

namespace Keel\Tests\Crypto;

use Keel\Container\Scope;
use Keel\Crypto\DecryptionFailed;
use Keel\Crypto\Encrypter;
use Keel\Crypto\Jwe;
use Keel\Crypto\MessageEncryption;
use Keel\Crypto\MessageEncryptionMiddleware;
use Keel\Database\ConnectionManager;
use Keel\Orm\Dialect\Dialects;
use Keel\Orm\EntityMap;
use Keel\Orm\Orm;
use Keel\Orm\Schema;
use Keel\Security\Keys;
use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use Keel\Tests\Fixtures\OrmBlog\Wallet;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    private const string KEY_A = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
    private const string KEY_B = 'base64:ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8=';

    public function testEncrypterRoundTripsAndBindsKeyAndContext(): void
    {
        $crypto = new Encrypter(new Keys(), ['a' => self::KEY_A]);
        $sealed = $crypto->encrypt('NL91ABNA0417164300', 'wallets.iban');

        self::assertStringStartsWith('kv1.a.', $sealed);
        self::assertNotSame($sealed, $crypto->encrypt('NL91ABNA0417164300', 'wallets.iban'), 'a fresh nonce every time');
        self::assertSame('NL91ABNA0417164300', $crypto->decrypt($sealed, 'wallets.iban'));
        self::assertTrue($crypto->isEncrypted($sealed));
        self::assertFalse($crypto->isEncrypted('NL91ABNA0417164300'));

        $this->assertFails(static fn () => $crypto->decrypt($sealed, 'wallets.notes'), 'another context');
        $tampered = substr($sealed, 0, -2) . (str_ends_with($sealed, 'A') ? 'BB' : 'AA');
        $this->assertFails(static fn () => $crypto->decrypt($tampered, 'wallets.iban'), 'altered');
        $this->assertFails(static fn () => $crypto->decrypt(str_replace('kv1.a.', 'kv1.b.', $sealed), 'wallets.iban'), 'unknown key id');
        $this->assertFails(static fn () => $crypto->decrypt('plaintext'), 'not a ciphertext');
    }

    public function testKeyRotation(): void
    {
        $old = new Encrypter(new Keys(), ['a' => self::KEY_A]);
        $sealed = $old->encrypt('secret', 'ctx');
        $rotated = new Encrypter(new Keys(), ['a' => self::KEY_A, 'b' => self::KEY_B], 'b');

        self::assertSame('secret', $rotated->decrypt($sealed, 'ctx'), 'old keys still decrypt');
        self::assertTrue($rotated->needsReencryption($sealed));
        $fresh = $rotated->reencrypt($sealed, 'ctx');
        self::assertStringStartsWith('kv1.b.', $fresh);
        self::assertFalse($rotated->needsReencryption($fresh));
        self::assertSame('secret', $rotated->decrypt($fresh, 'ctx'));
    }

    public function testWithoutConfiguredKeysAKeyIsDerivedFromTheAppKey(): void
    {
        $one = new Encrypter(new Keys('base64:' . base64_encode(str_repeat('k', 32))));
        $two = new Encrypter(new Keys('base64:' . base64_encode(str_repeat('j', 32))));

        self::assertSame('app', $one->currentKeyId());
        $this->assertFails(static fn () => $two->decrypt($one->encrypt('x')), 'another app key');
    }

    public function testBadKeysAreRefusedAtBoot(): void
    {
        $this->expectExceptionMessage('crypto.keys.short must be 32 bytes');
        new Encrypter(new Keys(), ['short' => 'base64:' . base64_encode('too short')]);
    }

    public function testJweRoundTripWithKidSelection(): void
    {
        $one = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $two = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        self::assertNotFalse($one);
        self::assertNotFalse($two);
        $public = \Keel\Auth\Jwt\Jwk::fromOpenSsl($two) + ['kid' => 'two'];

        $jwe = Jwe::encrypt('{"amount":"10.00"}', $public, ['cty' => 'application/json']);
        [$header, $plain] = Jwe::decrypt($jwe, ['one' => $one, 'two' => $two]);

        self::assertSame('{"amount":"10.00"}', $plain);
        self::assertSame(['ECDH-ES', 'A256GCM', 'two', 'application/json'], [$header['alg'], $header['enc'], $header['kid'], $header['cty']]);
        self::assertSame('', explode('.', $jwe)[1], 'direct key agreement: no encrypted key');
        $this->assertFails(static fn () => Jwe::decrypt($jwe, ['one' => $one]), 'kid not configured');

        $parts = explode('.', $jwe);
        $parts[3] = strtr(base64_encode(random_bytes(\strlen((string) base64_decode(strtr($parts[3], '-_', '+/'))))), ['+' => '-', '/' => '_', '=' => '']);
        $this->assertFails(static fn () => Jwe::decrypt(implode('.', $parts), ['two' => $two]), 'altered ciphertext');
    }

    public function testJweRefusesPointsOffTheCurve(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $jwk = \Keel\Auth\Jwt\Jwk::fromOpenSsl($key);
        $y = (string) base64_decode(strtr($jwk['y'], '-_', '+/'));
        $y[31] = \chr(\ord($y[31]) ^ 1);
        $jwk['y'] = rtrim(strtr(base64_encode($y), '+/', '-_'), '=');

        $this->expectException(\InvalidArgumentException::class);
        Jwe::publicKey($jwk);
    }

    public function testInteroperatesWithJwcrypto(): void
    {
        $python = getenv('KEEL_TEST_PYTHON') ?: 'python3';
        exec(escapeshellarg($python) . ' -c "import jwcrypto" 2>/dev/null', $out, $status);
        if ($status !== 0) {
            self::markTestSkipped('Python jwcrypto is not installed (set KEEL_TEST_PYTHON to a Python that has it).');
        }
        $server = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($server);
        openssl_pkey_export($server, $pem);
        $script = <<<'PY'
            import sys, json
            from jwcrypto import jwk, jwe
            key = jwk.JWK.from_pem(sys.argv[1].encode())
            token = jwe.JWE()
            token.deserialize(sys.argv[2], key=key)
            out = jwe.JWE(b'from python', protected=json.dumps({"alg": "ECDH-ES", "enc": "A256GCM"}))
            out.add_recipient(jwk.JWK.from_json(sys.argv[3]))
            print(json.dumps({"got": token.payload.decode(), "jwe": out.serialize(compact=True)}))
            PY;
        $public = \Keel\Auth\Jwt\Jwk::fromOpenSsl($server);
        $client = MessageEncryption::client();
        $command = implode(' ', array_map('escapeshellarg', [$python, '-c', $script, $pem, Jwe::encrypt('from php', $public), json_encode(\Keel\Auth\Jwt\Jwk::fromOpenSsl($client), \JSON_THROW_ON_ERROR)]));
        $result = json_decode((string) shell_exec($command), true, 4, \JSON_THROW_ON_ERROR);
        \assert(\is_array($result) && \is_string($result['jwe']));

        self::assertSame('from php', $result['got']);
        self::assertSame('from python', Jwe::decrypt($result['jwe'], ['c' => $client])[1]);
    }

    public function testEndToEndEncryptedRequestsAndResponses(): void
    {
        $app = TestApp::boot(__DIR__ . '/../Fixtures/Fintech/app.php', ['log' => ['stream' => 'php://memory']]);
        $jwks = $app->get('/.well-known/keel-e2e-keys')->assertStatus(200)->json();
        \assert(\is_array($jwks) && \is_array($jwks['keys']) && \is_array($jwks['keys'][0]));
        $server = $jwks['keys'][0];
        self::assertSame(['EC', 'P-256', 'test-2026', 'enc', 'ECDH-ES'], [$server['kty'], $server['crv'], $server['kid'], $server['use'], $server['alg']]);
        self::assertArrayNotHasKey('d', $server);

        $client = MessageEncryption::client();
        $request = MessageEncryption::seal(new ServerRequest('POST', 'http://localhost/secure/echo', ['Content-Type' => 'application/json', 'User-Agent' => 'test'], '{"message":"pay 10 EUR"}'), $server, $client);
        self::assertStringNotContainsString('pay 10 EUR', (string) $request->getBody());
        $response = $app->send($request);

        $response->assertStatus(200);
        self::assertSame(Jwe::MEDIA_TYPE, $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        $psr = new Response(200, ['Content-Type' => $response->header('Content-Type')], $response->body());
        self::assertSame(['message' => 'pay 10 EUR', 'encrypted' => true], json_decode(MessageEncryption::open($psr, $client), true));

        // Required paths refuse plaintext, and responses without a client key.
        $app->post('/secure/echo', ['message' => 'plain'])->assertStatus(400)->assertJsonPath('code', 'encryption_required');
        $app->request('POST', '/secure/echo', '{"message":"x"}', ['Content-Type' => 'application/json', MessageEncryptionMiddleware::KEY_HEADER => MessageEncryption::publicKeyHeader($client)])
            ->assertStatus(400)->assertJsonPath('code', 'encryption_required');
        $app->request('POST', '/secure/echo', 'eyJub3QiOiJhIGp3ZSJ9..x.y.z', ['Content-Type' => Jwe::MEDIA_TYPE, MessageEncryptionMiddleware::KEY_HEADER => MessageEncryption::publicKeyHeader($client)])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_encryption');

        // Elsewhere encryption is optional, in either direction.
        $app->post('/open/echo', ['message' => 'plain'])->assertStatus(200)->assertJsonPath('encrypted', false);
        $sealedOnly = MessageEncryption::seal(new ServerRequest('POST', 'http://localhost/open/echo', ['Content-Type' => 'application/json', 'User-Agent' => 'test'], '{"message":"hi"}'), $server);
        $app->send($sealedOnly)->assertStatus(200)->assertJsonPath('encrypted', true);
        $app->request('POST', '/open/echo', '{"message":"x"}', ['Content-Type' => 'application/json', MessageEncryptionMiddleware::KEY_HEADER => 'not-a-key'])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_encryption_key');
    }

    public function testEncryptedEntityColumns(): void
    {
        $file = sys_get_temp_dir() . '/keel-encrypted-' . getmypid() . '.sqlite';
        @unlink($file);
        $dsn = getenv('KEEL_TEST_DATABASE_DSN') ? TestDatabase::dsn() : 'sqlite:' . $file;
        $boot = static fn (array $crypto): TestApp => TestApp::boot(__DIR__ . '/../Fixtures/OrmBlog/app.php', [
            'database' => ['dsn' => $dsn, 'tenant_tables' => ['authors', 'posts', 'comments']],
            'crypto' => $crypto,
            'log' => ['stream' => 'php://memory'],
        ]);
        $app = $boot(['keys' => ['a' => self::KEY_A]]);
        $pdo = $app->container()->get(ConnectionManager::class)->writer();
        TestDatabase::reset($pdo);
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        foreach ((new Schema($app->container()->get(EntityMap::class), Dialects::for($driver)))->create() as $statement) {
            $pdo->exec($statement);
        }

        $id = $app->inScope(static function (Scope $scope): string {
            $orm = $scope->get(Orm::class);
            $wallet = new Wallet('ana', 'NL91ABNA0417164300', ['level' => 2], 'vip');
            $orm->save($wallet);
            $orm->save($wallet);   // unchanged: no write, version stays
            self::assertSame(1, $wallet->version);

            return $wallet->id;
        });

        $raw = $pdo->query("SELECT iban, kyc, notes FROM wallets WHERE id = '$id'")?->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($raw));
        foreach ($raw as $value) {
            self::assertIsString($value);
            self::assertStringStartsWith('kv1.a.', $value);
        }
        self::assertStringNotContainsString('NL91', implode('', $raw));

        $app->inScope(static function (Scope $scope) use ($id): void {
            $orm = $scope->get(Orm::class);
            $wallet = $orm->get(Wallet::class, $id);
            self::assertSame(['NL91ABNA0417164300', ['level' => 2], 'vip'], [$wallet->iban, $wallet->kyc, $wallet->notes]);
            $wallet->notes = null;
            $orm->save($wallet);
            self::assertSame(2, $wallet->version);
            self::assertSame(1, $orm->query(Wallet::class)->where('owner', 'ana')->update(['iban' => 'BE71096123456769']));
            try {
                $orm->query(Wallet::class)->where('iban', 'BE71096123456769')->get();
                self::fail('Filtering on an encrypted column must be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('#[Encrypted]', $e->getMessage());
            }
        });
        self::assertNull($pdo->query("SELECT notes FROM wallets WHERE id = '$id'")?->fetchColumn());

        // Rotate: a new current key, the old one kept for reading, then re-encrypt.
        $rotated = $boot(['keys' => ['a' => self::KEY_A, 'b' => self::KEY_B], 'current' => 'b']);
        self::assertSame(1, $rotated->inScope(static fn (Scope $scope): int => $scope->get(Orm::class)->reencrypt(Wallet::class)));
        $iban = $pdo->query("SELECT iban FROM wallets WHERE id = '$id'")?->fetchColumn();
        self::assertIsString($iban);
        self::assertStringStartsWith('kv1.b.', $iban);
        $rotated->inScope(static function (Scope $scope) use ($id): void {
            self::assertSame('BE71096123456769', $scope->get(Orm::class)->get(Wallet::class, $id)->iban);
        });
        @unlink($file);
    }

    /** @param \Closure(): mixed $work */
    private function assertFails(\Closure $work, string $why): void
    {
        try {
            $work();
            self::fail("Expected DecryptionFailed: $why.");
        } catch (DecryptionFailed) {
            self::addToAssertionCount(1);
        }
    }
}
