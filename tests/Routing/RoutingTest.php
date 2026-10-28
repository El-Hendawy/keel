<?php

declare(strict_types=1);

namespace Keel\Tests\Routing;

use Keel\Testing\TestApp;
use PHPUnit\Framework\TestCase;

final class RoutingTest extends TestCase
{
    private static TestApp $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = TestApp::boot(__DIR__ . '/../Fixtures/Contracts/app.php', ['db' => ['dsn' => 'x']]);
    }

    public function testIntParametersOnlyMatchDigits(): void
    {
        self::$app->get('/items/42')->assertStatus(200)->assertJsonPath('message', 'item 42');
        self::$app->get('/items/abc')->assertStatus(404);
    }

    public function testMethodNotAllowedListsAllowedMethods(): void
    {
        self::$app->request('PUT', '/items/1')->assertStatus(405)->assertHeader('Allow', 'GET, DELETE');
    }

    public function testVoidEndpointsReturnNoContent(): void
    {
        self::$app->delete('/items/1')->assertStatus(204);
    }

    public function testHeadFallsBackToGet(): void
    {
        self::$app->request('HEAD', '/items/7')->assertStatus(200);
    }

    public function testUnknownRoutesAreProblemDocuments(): void
    {
        $response = self::$app->get('/nope')->assertStatus(404);
        self::assertSame('application/problem+json', $response->header('Content-Type'));
        $response->assertJsonPath('title', 'Not Found');
    }

    public function testRoutesBuildPathsByName(): void
    {
        $router = self::$app->application->http()->router();
        self::assertSame('/health/live', $router->path('keel.health.live'));
    }
}
