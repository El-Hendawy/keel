# Concurrency

```php
use function Keel\Async\await_all;

[$order, $rate] = await_all(
    fn () => $orders->find($id),
    fn () => $fx->rate('USD'),
    timeout: 2.0,
);

['user' => $user, 'plan' => $plan] = await_all(
    user: fn () => $users->find($id),
    plan: fn () => $billing->planFor($id),
);
```

## Guarantees

- Results come back in order (or under their names).
- The first exception cancels the remaining operations and is rethrown once they have stopped.
- `timeout:` cancels everything and throws `Keel\Async\TimeoutException`.
- No operation outlives the `await_all` call, so nothing leaks into the next request.

## What actually runs concurrently

| Operation | Behaviour inside `await_all` |
| --- | --- |
| HTTP through the framework client (`ClientInterface`, `ResilientHttpClient`) | Concurrent (curl_multi) |
| `Keel\Async\delay()` | Concurrent |
| PostgreSQL through the [`pgsql-async:` driver](database.md#non-blocking-postgresql) | Does not block: other tasks keep running, and under [`keel run`](server.md) so do other requests. One request's queries share its connection, so they run one after another |
| Redis pub/sub, the [non-relational drivers](nosql.md) | Non-blocking under `keel run` |
| PDO queries (`pgsql:`, MySQL, SQLite and the rest) | Sequential: PDO blocks the process. Non-blocking MySQL is planned |
| Any other blocking call (`sleep`, `file_get_contents` on a URL) | Blocks the worker; avoid it |

The same code works under FPM: outside a fiber, the calls run the loop until done.

## Writing your own non-blocking operation

```php
use Keel\Async\{Loop, Suspension};

$suspension = Suspension::create();
$timer = Loop::get()->delay(0.5, fn () => $suspension->resume('done'));
$suspension->onCancel(fn () => Loop::get()->cancelTimer($timer));
$value = $suspension->suspend();
```
