# Services and lifetimes

A class is a service when it says how long it lives:

```php
#[Singleton] final class PriceList { ... }        // one per worker
#[Scoped]    final class CurrentTenant { ... }    // one per request, job or message
#[Transient] final class InvoiceNumber { ... }    // new on every injection
```

Dependencies arrive through the constructor. There is no `app()`, no facade and no service locator.

## Choosing a lifetime

Ask: *does this object know anything about the current request?* If yes, it is Scoped. If it holds a
connection, a client or immutable data, it is a Singleton. If it is a small helper with no state, Transient.

The compiler rejects a Singleton that depends on a Scoped service, including through a Transient in between,
because the singleton would keep the first request's object forever.

## Interfaces

Bind an interface to an implementation in `app.php`:

```php
->bind(PaymentGateway::class, StripeGateway::class)
->bindIn('test', PaymentGateway::class, FakeGateway::class)
```

## Configuration values

```php
/** @param list<string> $countries */
public function __construct(
    #[Config('stripe.secret')] private string $secret,
    #[Config('stripe.timeout_ms')] private int $timeoutMs = 3000,
    #[Config('shipping.countries')] private array $countries = ['BE', 'NL'],
) {}
```

Strings, ints, floats, bools, `list<string>` (comma-separated from an env var works) and arrays are
supported. Types are checked when the service is built; `keel inspect` lists every key.

## Many implementations

```php
public function __construct(#[All(HealthCheck::class)] private array $checks) {}
```

injects every registered service that is an instance of `HealthCheck`, resolved at build time.

## Classes you do not own

Use a factory method on a service:

```php
#[Singleton]
final readonly class SearchClients
{
    public function __construct(#[Config('search.url')] private string $url) {}

    #[Factory(Lifetime::Singleton)]
    public function client(): \OpenSearch\Client
    {
        return \OpenSearch\ClientBuilder::fromConfig(['hosts' => [$this->url]]);
    }
}
```

## Cleaning up

Implement `Keel\Container\Disposable`. Scoped services are disposed when their scope closes (in reverse
creation order); singletons when the worker shuts down.
