<?php

declare(strict_types=1);

namespace Keel\Tests\Unit;

use Keel\Auth\Fingerprint\ClientFingerprint;
use Keel\Auth\Fingerprint\Fingerprint;
use Keel\Auth\Jwt\Jwt;
use Keel\Auth\Principal;
use Keel\Build\BuildFailed;
use Keel\Build\Builder;
use Keel\Build\Sdk\TypeScriptGenerator;
use Keel\Cache\InMemoryCache;
use Keel\Http\Exception\BadRequest;
use Keel\Http\Exception\Unauthorized;
use Keel\Pagination\Cursors;
use Keel\Runtime\AppDefinition;
use Keel\Scheduling\CronExpression;
use Keel\Security\Keys;
use PHPUnit\Framework\TestCase;

/** Unit tests for the building blocks added in 0.2: scopes, fingerprints, JWT, cron, cache, cursors, SDK, build errors. */
final class FeaturesTest extends TestCase
{
    public function testScopesSupportWildcards(): void
    {
        $principal = new Principal('u', null, ['orders:*', 'invoices:read']);
        self::assertTrue($principal->hasScope('orders:write'));
        self::assertTrue($principal->hasScope('invoices:read'));
        self::assertFalse($principal->hasScope('invoices:write'));
        self::assertSame(['admin'], $principal->missingScopes(['orders:read', 'admin']));
        self::assertTrue((new Principal('root', null, ['*']))->hasScope('anything'));
    }

    public function testFingerprintNormalisationIgnoresVersionsButNotClients(): void
    {
        self::assertSame(
            ClientFingerprint::userAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/131.0.6778.86 Safari/537.36'),
            ClientFingerprint::userAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_8) Chrome/132.0.1 Safari/537.36'),
        );
        self::assertNotSame(ClientFingerprint::userAgent('curl/8.4.0'), ClientFingerprint::userAgent('Wget/1.21'));
        self::assertSame('nl', ClientFingerprint::language('nl-BE,nl;q=0.9,en;q=0.8'));
        self::assertSame('c0a801/24', ClientFingerprint::ipPrefix('192.168.1.77'));

        $bound = new Fingerprint(['user_agent' => 'a', 'tls' => 'b']);
        self::assertSame([], $bound->mismatches(new Fingerprint(['user_agent' => 'a', 'tls' => 'b', 'accept_language' => 'x'])));
        self::assertSame(['tls'], $bound->mismatches(new Fingerprint(['user_agent' => 'a'])), 'A bound trait that disappears is a mismatch.');
        self::assertSame($bound->components, Fingerprint::decode($bound->encode())->components);
    }

    public function testJwtRefusesAlgorithmConfusion(): void
    {
        $keys = ['k1' => ['alg' => 'HS256', 'key' => 'secret-secret-secret-secret-1234']];
        $token = Jwt::encode(['sub' => 'ada'], 'HS256', 'secret-secret-secret-secret-1234', 'k1');
        self::assertSame(['sub' => 'ada'], Jwt::verify($token, $keys));

        $none = rtrim(strtr(base64_encode('{"alg":"none","kid":"k1"}'), '+/', '-_'), '=') . '.' . explode('.', $token)[1] . '.';
        $this->expectException(Unauthorized::class);
        Jwt::verify($none, $keys);
    }

    public function testJwtRsaSignatures(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($private);
        $token = Jwt::encode(['sub' => 'svc'], 'RS256', $private, 'rsa');
        self::assertSame(['sub' => 'svc'], Jwt::verify($token, ['rsa' => ['alg' => 'RS256', 'key' => $details['key']]]));
    }

    public function testCronExpressions(): void
    {
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($time);
        self::assertTrue(CronExpression::parse('*/15 * * * *')->isDue($at('2026-03-04T10:30:00Z')));
        self::assertFalse(CronExpression::parse('*/15 * * * *')->isDue($at('2026-03-04T10:31:00Z')));
        self::assertTrue(CronExpression::parse('0 9-17 * * 1-5')->isDue($at('2026-03-04T12:00:00Z'))); // a Wednesday
        self::assertFalse(CronExpression::parse('0 9-17 * * 1-5')->isDue($at('2026-03-07T12:00:00Z'))); // a Saturday
        self::assertTrue(CronExpression::parse('@daily')->isDue($at('2026-03-04T00:00:00Z')));
        self::assertTrue(CronExpression::parse('0 0 1 * 0')->isDue($at('2026-03-01T00:00:00Z')), 'Either day field matches (a Sunday the 1st).');
        self::assertTrue(CronExpression::parse('0 0 * * 7')->isDue($at('2026-03-08T00:00:00Z')), '7 is Sunday.');
        self::assertTrue(CronExpression::parse('5/20 * * * *')->isDue($at('2026-03-04T10:45:00Z')));

        $this->expectException(\InvalidArgumentException::class);
        CronExpression::parse('60 * * * *');
    }

    public function testCacheIsBoundedAndRefusesObjects(): void
    {
        $cache = new InMemoryCache(2);
        $calls = 0;
        $compute = static function () use (&$calls): array {
            $calls++;

            return ['n' => $calls];
        };
        self::assertSame(['n' => 1], $cache->remember('a', 60, $compute));
        self::assertSame(['n' => 1], $cache->remember('a', 60, $compute));
        $cache->set('b', 2, 60);
        $cache->get('a'); // a is now the most recently used
        $cache->set('c', 3, 60);
        self::assertNull($cache->get('b'), 'The least recently used entry is evicted.');
        self::assertSame(['n' => 1], $cache->get('a'));

        $this->expectException(\InvalidArgumentException::class);
        $cache->set('o', ['nested' => new \stdClass()], 60);
    }

    public function testCursorsAreSignedAndScopedToAPurpose(): void
    {
        $cursors = new Cursors(new Keys('base64:' . base64_encode(str_repeat('k', 32))));
        $cursor = $cursors->encode(['c' => '2026-01-01', 'i' => 'abc'], 'orders');
        self::assertSame(['c' => '2026-01-01', 'i' => 'abc'], $cursors->decode($cursor, 'orders'));

        $this->expectException(BadRequest::class);
        $cursors->decode($cursor, 'invoices');
    }

    public function testTheSdkIsGeneratedFromOpenApi(): void
    {
        $doc = [
            'paths' => ['/orders/{id}' => ['get' => [
                'operationId' => 'orders.show',
                'summary' => 'Fetch one order.',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/OrderResource']]]]],
            ]]],
            'components' => ['schemas' => ['OrderResource' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string'], 'note' => ['type' => ['string', 'null']], 'status' => ['type' => 'string', 'enum' => ['placed', 'paid']]],
                'required' => ['id', 'status'],
            ]]],
        ];
        $ts = (new TypeScriptGenerator())->generate($doc);
        self::assertStringContainsString("export interface OrderResource {\n  id: string;\n  note?: string | null;\n  status: \"placed\" | \"paid\";\n}", $ts);
        self::assertStringContainsString('ordersShow: (id: string, init: RequestInit = {}): Promise<OrderResource>', $ts);
        self::assertStringContainsString('`/orders/${encodeURIComponent(String(id))}`', $ts);
    }

    public function testTheCompilerRejectsMisconfiguration(): void
    {
        $definition = require __DIR__ . '/../Fixtures/Misconfigured/app.php';
        self::assertInstanceOf(AppDefinition::class, $definition);
        try {
            (new Builder($definition, 'test'))->build();
            self::fail('The build should fail.');
        } catch (BuildFailed $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('->withQueue() stores jobs in the database', $message);
            self::assertStringContainsString('uses #[Authorize], but authentication is not enabled', $message);
            self::assertStringContainsString('can only receive a Principal when authentication is enabled', $message);
            self::assertStringContainsString('#[Schedule] on Keel\Tests\Fixtures\Misconfigured\BadlyScheduled', $message);
            self::assertStringContainsString('must be a concrete, typed class to be queued', $message);
        }
    }
}
