<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Scope;
use Keel\Database\Database;
use Keel\Database\NPlusOneDetected;
use Keel\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/** End-to-end tests against the example application in examples/shop. */
final class ShopTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../examples/shop';
    private const string TENANT_ID = '11111111-1111-4111-8111-111111111111';
    private const string MERCHANT = '22222222-2222-4222-8222-222222222222';

    private TestApp $app;

    /** @var array<string, string> Authorization header of a tenant user with every orders/invoices scope */
    private array $user;

    protected function setUp(): void
    {
        $this->app = self::boot();
        $this->user = $this->app->tokenFor('user-1', self::TENANT_ID, ['orders:*', 'invoices:read']);
    }

    /** @param array<string, mixed> $config */
    private static function boot(array $config = []): TestApp
    {
        $app = TestApp::boot(self::ROOT . '/app.php', array_replace_recursive(['database' => ['dsn' => \Keel\Testing\TestDatabase::dsn()]], $config));
        $app->freshDatabase(self::ROOT . '/migrations');

        return $app;
    }

    /** @return array<string, mixed> */
    private static function order(int $quantity = 2): array
    {
        return ['merchantId' => self::MERCHANT, 'items' => [['sku' => 'TEA-1', 'quantity' => $quantity, 'unitPriceCents' => 450]], 'note' => 'leave at door'];
    }

    public function testPlacingAnOrder(): void
    {
        $response = $this->app->post('/orders', self::order(), $this->user)->assertStatus(201);

        $response
            ->assertJsonPath('status', 'placed')
            ->assertJsonPath('totalCents', 900)
            ->assertJsonPath('items.0.sku', 'TEA-1')
            ->assertJsonPath('createdAt', '2026-01-01T00:00:00.000+00:00');

        $id = $response->get('id');
        self::assertIsString($id);
        $this->app->get("/orders/$id", $this->user)->assertStatus(200)->assertJsonPath('id', $id);
    }

    public function testAnEventListenerInAnotherModuleCreatesTheInvoice(): void
    {
        $id = $this->app->post('/orders', self::order(3), $this->user)->get('id');
        self::assertIsString($id);

        $this->app->get("/orders/$id/invoice", $this->user)
            ->assertStatus(200)
            ->assertJsonPath('orderId', $id)
            ->assertJsonPath('amountCents', 1350);
    }

    public function testValidationReportsEveryProblemWithItsPath(): void
    {
        $response = $this->app->post('/orders', ['merchantID' => 'nope', 'items' => [['sku' => '', 'quantity' => 0, 'unitPriceCents' => 'free']]], $this->user)
            ->assertStatus(422);

        $errors = array_map(static fn (array $e): string => $e['path'] . ':' . $e['code'], $response->json()['errors']);
        self::assertSame([
            'merchantID:unknown_field',
            'merchantId:required',
            'items[0].sku:length',
            'items[0].quantity:range',
            'items[0].unitPriceCents:type',
        ], $errors);
    }

    public function testEmptyItemsAndBadJson(): void
    {
        $this->app->post('/orders', ['merchantId' => self::MERCHANT, 'items' => []], $this->user)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'count');

        $this->app->request('POST', '/orders', '{"merchantId": ', $this->user + ['Content-Type' => 'application/json'])
            ->assertStatus(400);
    }

    public function testQueryInputsAreCoercedAndValidated(): void
    {
        $this->app->post('/orders', self::order(), $this->user);
        $this->app->post('/orders', self::order(), $this->user);

        $this->app->get('/orders?limit=1&status=placed', $this->user)->assertStatus(200)->assertJsonPath('data.0.status', 'placed');
        self::assertCount(1, $this->app->get('/orders?limit=1', $this->user)->get('data'));
        $this->app->get('/orders?limit=0', $this->user)->assertStatus(422)->assertJsonPath('errors.0.path', 'limit');
        $this->app->get('/orders?status=shipped', $this->user)->assertStatus(422)->assertJsonPath('errors.0.code', 'enum');
    }

    public function testTenantsAreIsolated(): void
    {
        $id = $this->app->post('/orders', self::order(), $this->user)->get('id');
        self::assertIsString($id);
        $otherTenant = $this->app->tokenFor('user-2', '99999999-9999-4999-8999-999999999999', ['orders:read']);
        $this->app->get("/orders/$id", $otherTenant)->assertStatus(404);
        $this->app->get("/orders/$id")->assertStatus(401);
    }

    public function testConcurrentLookupWithAFakedExternalApi(): void
    {
        $id = $this->app->post('/orders', self::order(), $this->user)->get('id');
        $this->app->http()->stub('GET', 'https://api.frankfurter.app/latest?from=EUR&to=USD', ['rates' => ['USD' => 1.1]]);

        $this->app->get("/orders/$id/summary?currency=USD", $this->user)
            ->assertStatus(200)
            ->assertJsonPath('rate', 1.1)
            ->assertJsonPath('convertedTotalCents', 990);
        self::assertCount(1, $this->app->http()->sent());

        $this->app->get("/orders/$id/summary?currency=usd", $this->user)->assertStatus(422);
    }

    public function testUnexpectedOutboundCallsFailLoudly(): void
    {
        $app = self::boot(['log' => ['stream' => 'php://memory']]); // the 500 is expected; keep the output clean
        $user = $app->tokenFor('user-1', self::TENANT_ID);
        $id = $app->post('/orders', self::order(), $user)->get('id');
        $response = $app->get("/orders/$id/summary?currency=GBP", $user)->assertStatus(500);
        self::assertStringContainsString('Unexpected outbound request in a test', (string) $response->get('exception.message'));
    }

    public function testIdempotencyKeysReplayTheFirstResponse(): void
    {
        $headers = $this->user + ['Idempotency-Key' => 'order-123'];
        $first = $this->app->post('/orders', self::order(), $headers)->assertStatus(201);
        $second = $this->app->post('/orders', self::order(), $headers)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

        self::assertSame($first->get('id'), $second->get('id'));
        self::assertCount(1, $this->app->get('/orders', $this->user)->get('data'));

        $this->app->post('/orders', self::order(5), $headers)->assertStatus(422);
    }

    public function testRateLimitingIsOnByDefault(): void
    {
        $app = self::boot(['rate_limit' => ['capacity' => 2, 'per_seconds' => 60]]);
        $user = $app->tokenFor('user-1', self::TENANT_ID);
        $app->get('/orders', $user)->assertStatus(200)->assertHeader('RateLimit-Remaining', '1');
        $app->get('/orders', $user)->assertStatus(200);
        $app->get('/orders', $user)->assertStatus(429)->assertHeader('Retry-After');
        $app->get('/health/live')->assertStatus(200);
    }

    public function testEveryResponseCarriesSafeHeadersARequestIdAndATrace(): void
    {
        $response = $this->app->get('/health/live');
        $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store')
            ->assertHeader('X-Request-Id')
            ->assertHeader('traceparent');

        $this->app->get('/health/live', ['X-Request-Id' => 'abc123-from-the-edge'])->assertHeader('X-Request-Id', 'abc123-from-the-edge');
        $this->app->get('/health/live', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'])
            ->assertStatus(200);
        self::assertStringStartsWith('00-0af7651916cd43dd8448eb211c80319c-', $this->app->get('/health/live', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'])->header('traceparent'));
    }

    public function testRedMetricsAreRecordedPerRoute(): void
    {
        $this->app->post('/orders', self::order(), $this->user);
        $metrics = $this->app->get('/metrics')->assertStatus(200)->body();

        self::assertStringContainsString('http_requests_total{method="POST",route="/orders",status="201",worker=', $metrics);
        self::assertStringContainsString('http_request_duration_seconds_bucket{method="POST",route="/orders",worker=', $metrics);
    }

    public function testMetricsCanBeProtectedWithAToken(): void
    {
        $app = self::boot(['metrics' => ['token' => 's3cret']]);
        $app->get('/metrics')->assertStatus(401);
        $app->get('/metrics', ['Authorization' => 'Bearer s3cret'])->assertStatus(200);
    }

    public function testNPlusOneQueriesFailTests(): void
    {
        $this->expectException(NPlusOneDetected::class);
        $this->app->inScope(static function (Scope $scope): void {
            $db = $scope->get(Database::class);
            for ($i = 0; $i < 11; $i++) {
                $db->select('SELECT * FROM order_items WHERE order_id = ?', ["order-$i"]);
            }
        });
    }

    public function testOpenApiIsGeneratedFromTheTypes(): void
    {
        $file = $this->app->application->manifest->directory . '/openapi.json';
        /** @var array<string, mixed> $doc */
        $doc = json_decode((string) file_get_contents($file), true, 64, \JSON_THROW_ON_ERROR);

        $input = $doc['components']['schemas']['CreateOrderInput'];
        self::assertSame(['merchantId', 'items'], $input['required']);
        self::assertSame('uuid', $input['properties']['merchantId']['format']);
        self::assertSame(1, $input['properties']['items']['minItems']);
        self::assertSame('#/components/schemas/LineItem', $input['properties']['items']['items']['$ref']);
        self::assertSame(['placed', 'paid', 'cancelled'], $doc['components']['schemas']['OrderResource']['properties']['status']['enum']);
        self::assertArrayHasKey('201', $doc['paths']['/orders']['post']['responses']);
        self::assertSame(['orders'], $doc['paths']['/orders']['post']['tags']);
    }
}
