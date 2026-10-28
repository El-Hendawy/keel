# Testing

```php
use Keel\Testing\TestApp;

$app = TestApp::boot(__DIR__ . '/../app.php', ['database' => ['dsn' => 'sqlite::memory:']]);

$app->post('/orders', ['merchantId' => $merchant, 'items' => [...]], ['X-Tenant-Id' => $tenant])
    ->assertStatus(201)
    ->assertJsonPath('items.0.sku', 'TEA-1');
```

Requests go straight into the kernel: no server, no sockets, a few microseconds each. Assertions report
through PHPUnit when it is running.

## Determinism by contract

In the `test` environment these bindings are automatic:

| Contract | Test implementation | Control it with |
| --- | --- | --- |
| `Psr\Clock\ClockInterface` | `FrozenClock` (2026-01-01T00:00:00Z) | `$app->clock()->set(...)`, `->advance('+1 day')` |
| `Keel\Support\IdGenerator` | `SequentialIdGenerator` | ids are `00000000-0000-7000-8000-000000000001`, `…2`, … |
| `Keel\Support\Random` | `SeededRandom` (seed 42) | `testing.seed` |
| `Psr\Http\Client\ClientInterface` | `FakeHttpClient` | `$app->http()->stub('GET', 'https://api.example.com/*', ['ok' => true])` |

An outbound request without a stub fails the test, so nothing leaves the machine by accident.

## Replacing a service

```php
$app->container()->override(PaymentGateway::class, new FakeGateway());
```

or bind per environment in `app.php`: `->bindIn('test', PaymentGateway::class, FakeGateway::class)`.

## Jobs and commands

```php
$app->inScope(function (Scope $scope) {
    $scope->get(Orders::class)->expireAbandoned();
});
```

## Databases

`sqlite::memory:` gives each booted app its own database. For a real engine in parallel runs, derive the
database name from the worker token (`getenv('TEST_TOKEN')` under paratest) in `config.php`. N+1 queries throw
in tests.
