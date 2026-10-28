# Testing

```php
use Keel\Testing\TestApp;

$app = TestApp::boot(__DIR__ . '/../app.php', ['database' => ['dsn' => TestDatabase::dsn()]]);
$app->freshDatabase(__DIR__ . '/../migrations');

$user = $app->tokenFor('ada', $tenantId, ['orders:*']);   // a real token, bound on first use
$app->post('/orders', ['merchantId' => $merchant, 'items' => [...]], $user)
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

## Authentication

`tokenFor($principalId, $tenantId, $scopes)` issues a real token through the application's token store and
returns the `Authorization` header. Test requests send `User-Agent: KeelTestApp/1.0`, so the token binds to
the test client on first use, as in production; send another `User-Agent` to test a stolen token.

## Jobs, scheduled tasks and commands

```php
$app->runJobs();                                                   // everything ready in the queue
$app->runSchedule(new DateTimeImmutable('2026-01-02T03:00:00Z'));  // tasks due at that minute
$app->inScope(function (Scope $scope) {
    $scope->get(Orders::class)->expireAbandoned();
});
```

Jobs see the frozen clock: `$app->clock()->advance('+1 hour')` moves retries and delays along.

## Databases

`TestDatabase::dsn()` is an in-memory SQLite database unless `KEEL_TEST_DATABASE_DSN` is set, so the same
tests run on PostgreSQL and MySQL in CI:

```bash
KEEL_TEST_DATABASE_DSN='pgsql:host=127.0.0.1;dbname=app_test;user=app' vendor/bin/phpunit
```

`freshDatabase()` drops every table and migrates (framework migrations first), so each test starts clean.
For parallel runs, put `{token}` in the DSN (`dbname=app_test_{token}`); it is replaced by paratest's
`TEST_TOKEN`. N+1 queries and tenant violations throw in tests.
