<?php

declare(strict_types=1);

namespace Keel\Tests\Vector;

use Keel\Runtime\ProductionChecks;
use Keel\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/** IntVector and FloatVector as endpoint input and output types. */
final class VectorApiTest extends TestCase
{
    private static function app(): TestApp
    {
        return TestApp::boot(__DIR__ . '/../Fixtures/Vectors/app.php', ['log' => ['stream' => 'php://memory']]);
    }

    public function testHydratesAndSerializesVectors(): void
    {
        $response = self::app()->post('/readings/summary', ['values' => [3, 1.5, 9.25], 'threshold' => 2, 'weights' => [1, 2, 3]])
            ->assertStatus(200);
        self::assertSame([
            'mean' => 4.583333333333333,
            'above' => [0, 2],
            'sorted' => [1.5, 3.0, 9.25],
            'weightSum' => 6,
            'weights' => [1, 2, 3],
        ], $response->json());

        self::app()->post('/readings/summary', ['values' => [1], 'threshold' => 0])
            ->assertStatus(200)->assertJsonPath('weights', null)->assertJsonPath('weightSum', null);
    }

    public function testReportsEachInvalidItemByPath(): void
    {
        $errors = self::app()->post('/readings/summary', ['values' => [1, 'x', true], 'threshold' => 0, 'weights' => [1, 2.5]])
            ->assertStatus(422)->get('errors');
        self::assertIsArray($errors);
        $found = array_map(static fn (array $e): string => $e['path'] . ' ' . $e['code'], $errors);
        sort($found);
        self::assertSame(['values[1] type', 'values[2] type', 'weights[1] type'], $found);

        $errors = self::app()->post('/readings/summary', ['values' => ['a' => 1], 'threshold' => 0])->assertStatus(422)->get('errors');
        self::assertSame('values', $errors[0]['path'] ?? null);
        self::assertSame('type', $errors[0]['code'] ?? null);

        $errors = self::app()->post('/readings/summary', ['values' => [], 'threshold' => 0])->assertStatus(422)->get('errors');
        self::assertSame('count', $errors[0]['code'] ?? null, 'Count applies to vectors');
    }

    public function testProductionChecksCanRequireTheExtension(): void
    {
        $app = TestApp::boot(__DIR__ . '/../Fixtures/Vectors/app.php', ['vector' => ['require_native' => true], 'log' => ['stream' => 'php://memory']]);
        $problems = implode("\n", ProductionChecks::problems($app->application));
        if (\extension_loaded('keel_vector')) {
            self::assertStringNotContainsString('vector.require_native', $problems);
        } else {
            self::assertStringContainsString('vector.require_native is set but the keel_vector extension is not loaded', $problems);
        }
        self::assertStringNotContainsString('vector.require_native', implode("\n", ProductionChecks::problems(self::app()->application)));
    }

    public function testDocumentsVectorsInOpenApi(): void
    {
        $app = self::app();
        $document = json_decode((string) file_get_contents($app->application->manifest->directory . '/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $schemas = $document['components']['schemas'] ?? [];
        $input = json_encode($schemas, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('{"type":"array","items":{"type":"number","format":"double"},"minItems":1,"maxItems":1000}', $input);
        self::assertStringContainsString('{"type":["array","null"],"items":{"type":"integer","format":"int64"}}', $input);
    }
}
