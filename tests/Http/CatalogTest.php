<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;

/** The ORM behind real endpoints: the shop's catalog module. */
final class CatalogTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../examples/shop';

    public function testProductsThroughTheApi(): void
    {
        $app = TestApp::boot(self::ROOT . '/app.php', ['database' => ['dsn' => TestDatabase::dsn()], 'log' => ['stream' => 'php://memory']]);
        $app->freshDatabase(self::ROOT . '/migrations');
        $alice = $app->tokenFor('alice', 'tenant-a', ['catalog:*']);
        $bob = $app->tokenFor('bob', 'tenant-b', ['catalog:*']);

        foreach (['Tea', 'Cake', 'Scone'] as $i => $name) {
            $app->post('/products', ['sku' => "SKU-$i", 'name' => $name, 'priceCents' => 100 * ($i + 1)], $alice)->assertStatus(201);
        }
        $app->post('/products', ['sku' => 'SKU-0', 'name' => 'Duplicate', 'priceCents' => 1], $bob)->assertStatus(201); // SKUs are unique per tenant

        $first = $app->get('/products?limit=2', $alice)->assertStatus(200);
        self::assertSame(['Cake', 'Scone'], array_column($first->json()['data'], 'name'));
        $cursor = $first->get('page.nextCursor');
        self::assertIsString($cursor);
        self::assertSame(['Tea'], array_column($app->get('/products?limit=2&cursor=' . urlencode($cursor), $alice)->json()['data'], 'name'));
        self::assertSame(['Duplicate'], array_column($app->get('/products', $bob)->json()['data'], 'name'));
        self::assertSame(['Scone'], array_column($app->get('/products?search=con', $alice)->json()['data'], 'name'));

        $id = $first->get('data.0.id');
        self::assertIsString($id);
        $app->patch("/products/$id/price", ['priceCents' => 250, 'version' => 1], $alice)->assertStatus(200)->assertJsonPath('version', 2);
        $app->patch("/products/$id/price", ['priceCents' => 300, 'version' => 1], $alice)->assertStatus(409);
        $app->patch("/products/$id/price", ['priceCents' => 300, 'version' => 2], $bob)->assertStatus(404); // another tenant's product
    }
}
