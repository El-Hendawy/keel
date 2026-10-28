<?php

declare(strict_types=1);

/**
 * In-process kernel throughput: what one worker can do once booted, without network or
 * server overhead. Run with opcache on to approximate a worker:
 *
 *     php -d opcache.enable_cli=1 benchmarks/kernel.php
 *
 * Use it to compare releases on the same machine (a regression over 5% blocks a release).
 * For end-to-end numbers, load-test a FrankenPHP worker with k6 or wrk.
 */

$vendor = getenv('COMPOSER_VENDOR_DIR') ?: __DIR__ . '/../vendor';
require $vendor . '/autoload.php';

use Keel\Testing\TestApp;
use Nyholm\Psr7\ServerRequest;

$root = __DIR__ . '/../examples/shop';
$app = TestApp::boot($root . '/app.php', [
    'database' => ['dsn' => 'sqlite::memory:'],
    'rate_limit' => ['enabled' => false],
    'log' => ['level' => 'error'],
]);
$app->migrate($root . '/migrations');
$kernel = $app->application->http();
// A real bearer token: authentication and fingerprint binding are part of every measured request.
$tenant = $app->tokenFor('bench', '11111111-1111-4111-8111-111111111111') + ['User-Agent' => 'bench/1.0'];
$order = json_encode(['merchantId' => '22222222-2222-4222-8222-222222222222', 'items' => [['sku' => 'TEA', 'quantity' => 2, 'unitPriceCents' => 300]]], JSON_THROW_ON_ERROR);

$scenarios = [
    'GET /health/live (static route, full middleware)' => static fn () => new ServerRequest('GET', 'http://localhost/health/live', [], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']),
    'GET /orders/{id} 404 (auth, dynamic route, DB read)' => static fn () => new ServerRequest('GET', 'http://localhost/orders/missing', $tenant, null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']),
    'POST /orders 422 (auth, body parse + validation)' => static fn () => new ServerRequest('POST', 'http://localhost/orders', $tenant + ['Content-Type' => 'application/json'], '{"items":[]}', '1.1', ['REMOTE_ADDR' => '127.0.0.1']),
    'POST /orders 201 (auth, validate, transaction, outbox, serialize)' => static fn () => new ServerRequest('POST', 'http://localhost/orders', $tenant + ['Content-Type' => 'application/json'], $order, '1.1', ['REMOTE_ADDR' => '127.0.0.1']),
];

printf("Keel %s, PHP %s, opcache %s\n\n", Keel\Keel::VERSION, PHP_VERSION, function_exists('opcache_get_status') && @opcache_get_status() !== false ? 'on' : 'off');
foreach ($scenarios as $name => $make) {
    for ($i = 0; $i < 200; $i++) {
        $kernel->handle($make());
    }
    $iterations = 3000;
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $kernel->handle($make());
        $app->application->afterRequest();
    }
    $seconds = (hrtime(true) - $start) / 1e9;
    printf("%-60s %8.0f req/s  %6.1f µs/req\n", $name, $iterations / $seconds, $seconds / $iterations * 1e6);
}
printf("\nPeak memory: %.1f MB\n", memory_get_peak_usage(true) / 1048576);
