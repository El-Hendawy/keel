<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Http\Toon\Toon;
use Keel\Http\Toon\ToonException;
use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;

/** TOON on the wire (negotiation, bodies, errors) and the codec itself. */
final class ToonTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../examples/shop';
    private const string TENANT = '11111111-1111-4111-8111-111111111111';
    private const string ORDER = <<<'TOON'
        merchantId: 22222222-2222-4222-8222-222222222222
        items[2]{sku,quantity,unitPriceCents}:
          TEA,2,300
          "CAKE, LEMON",1,450
        note: ring twice
        TOON;

    /** @param array<string, mixed> $config */
    private static function boot(array $config = []): TestApp
    {
        $app = TestApp::boot(self::ROOT . '/app.php', array_replace_recursive(['database' => ['dsn' => TestDatabase::dsn()], 'log' => ['stream' => 'php://memory']], $config));
        $app->freshDatabase(self::ROOT . '/migrations');

        return $app;
    }

    public function testTheApiSpeaksToonWhenAsked(): void
    {
        $app = self::boot();
        $user = $app->tokenFor('ada', self::TENANT);
        $created = $app->request('POST', '/orders', self::ORDER, $user + ['Content-Type' => 'text/toon', 'Accept' => 'text/toon'])
            ->assertStatus(201)
            ->assertHeader('Content-Type', 'text/toon; charset=utf-8')
            ->assertJsonPath('totalCents', 1050)
            ->assertJsonPath('items.1.sku', 'CAKE, LEMON');
        self::assertStringContainsString("items[2]{sku,quantity,unitPriceCents}:\n  TEA,2,300\n  \"CAKE, LEMON\",1,450", $created->body());

        // JSON stays the default for clients that do not ask.
        $app->get('/orders', $user)->assertHeader('Content-Type', 'application/json');
        $app->get('/orders', $user + ['Accept' => 'application/json;q=0.5, text/toon'])->assertHeader('Content-Type', 'text/toon; charset=utf-8');
    }

    public function testErrorsAndValidationAreToonToo(): void
    {
        $app = self::boot();
        $user = $app->tokenFor('ada', self::TENANT);
        $response = $app->request('POST', '/orders', "merchantId: nope\nitems[0]:", $user + ['Content-Type' => 'text/toon', 'Accept' => 'text/toon'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'text/toon; charset=utf-8; profile=problem')
            ->assertJsonPath('errors.0.path', 'merchantId');
        self::assertStringContainsString('errors[2]{path,code,message}:', $response->body());

        $app->request('POST', '/orders', "items[3]: 1,2", $user + ['Content-Type' => 'text/toon'])->assertStatus(400);
        $app->get('/orders', ['Accept' => 'text/toon'])->assertStatus(401)->assertJsonPath('status', 401);
    }

    public function testToonCanBeTheDefault(): void
    {
        $app = self::boot(['http' => ['default_format' => 'toon']]);
        $user = $app->tokenFor('ada', self::TENANT);
        $app->get('/me', $user)->assertHeader('Content-Type', 'text/toon; charset=utf-8')->assertJsonPath('id', 'ada');
        $app->get('/me', $user + ['Accept' => 'application/json'])->assertHeader('Content-Type', 'application/json');
    }

    public function testOpenApiListsBothFormats(): void
    {
        $app = self::boot();
        $doc = json_decode((string) file_get_contents($app->application->manifest->directory . '/openapi.json'), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($doc);
        self::assertSame(['application/json', 'text/toon'], array_keys($doc['paths']['/orders']['post']['requestBody']['content']));
    }

    public function testCodecRoundTripsTheJsonDataModel(): void
    {
        $data = [
            'users' => [['id' => 1, 'name' => 'Ada', 'active' => true], ['id' => 2, 'name' => 'Bob', 'active' => false]],
            'tags' => ['a', 'b c', '', 'true', '42', '-x', 'k: v'],
            'meta' => ['score' => 0.5, 'none' => null, 'big' => 1.0e21, 'nested' => ['deep' => ['x' => 1]]],
            'mixed' => [1, ['a' => 1, 'b' => [1, 2]], [3, 4], 'z', []],
            'empty' => [],
            'odd key' => 'v',
        ];
        $toon = Toon::encode($data);
        self::assertStringContainsString("users[2]{id,name,active}:\n  1,Ada,true\n  2,Bob,false", $toon);
        self::assertStringContainsString('tags[7]: a,b c,"","true","42","-x","k: v"', $toon);
        self::assertStringContainsString('big: 1000000000000000000000', $toon);
        self::assertEquals($data, Toon::decode($toon));
        self::assertSame("[2|]{a|b}:\n  1|x,y\n  2|z", Toon::encode([['a' => 1, 'b' => 'x,y'], ['a' => 2, 'b' => 'z']], '|'));
        self::assertSame('hello', Toon::decode('hello'));
        self::assertSame([], Toon::decode(''));
    }

    public function testDecodingIsStrict(): void
    {
        foreach (["a[2]: 1", "a[1]{x,y}:\n  1", "a:\n   b: 1", "a: \"open", "a: 1\na: 2", "a[1]:\n  - 1\n  - 2"] as $bad) {
            try {
                Toon::decode($bad);
                self::fail("Accepted: $bad");
            } catch (ToonException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
