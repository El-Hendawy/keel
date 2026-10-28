# Keel

**The typed, long-running, API-first PHP framework for SaaS backends.**

Keel boots once and serves many requests (FrankenPHP, RoadRunner, FPM as fallback). It compiles routes, the
container, validation and serialization into plain PHP before deploy, so the hot path has no reflection.
Types are the contract: one input class is the validation, the documentation and the API schema.

> Read [the manifesto](docs/manifesto.md) for the why. This README covers the what and the how.

```php
#[Post('/orders')]
#[Status(201)]
final class CreateOrder
{
    public function __invoke(CreateOrderInput $in, Orders $orders): OrderResource
    {
        return OrderResource::from($orders->create($in));
    }
}

final readonly class CreateOrderInput
{
    public function __construct(
        #[Uuid] public string $merchantId,
        /** @var list<LineItem> */
        #[Count(min: 1)] public array $items,
        public ?string $note = null,
    ) {}
}
```

Send `{"merchantID": "x", "items": []}` and you get the following, with no validation code written:

```json
{
  "type": "about:blank", "title": "Validation failed", "status": 422,
  "errors": [
    {"path": "merchantID", "code": "unknown_field", "message": "Unknown field."},
    {"path": "merchantId", "code": "required", "message": "This field is required."},
    {"path": "items", "code": "count", "message": "Must contain at least 1 item(s)."}
  ],
  "requestId": "01a18306-3f1c-7a86-9f61-44ac20443455"
}
```

## Status: v0.1 (developer preview)

v0.1 is the first roadmap phase: router, compiled container, typed I/O and worker runtime, plus the
production defaults the manifesto requires from `new project`. Everything below is implemented and tested;
items marked *planned* are on the [roadmap](docs/manifesto.md#roadmap).

| Area | What you get |
| --- | --- |
| Runtime | FrankenPHP worker mode, RoadRunner, FPM/CGI fallback; graceful shutdown (SIGTERM drains), max requests/memory recycling. Swoole: *planned (v0.2)* |
| Container | Compiled to plain PHP; `Singleton` / `Scoped` / `Transient` lifetimes; a Singleton depending on a Scoped service (directly or through Transients) is a **build error**; cycles and missing bindings reported with a fix |
| Routing | Attribute routes on invokable classes, static map plus combined regexes, typed path parameters (`int` only matches digits), 404/405 with `Allow`, named routes |
| Typed I/O | Generated hydrators: strict types, unknown fields rejected, every violation with its path (`items[0].sku`); query strings coerced; outputs serialized from public properties; arrays are rejected as contracts at build time |
| Validation | `Uuid`, `Email`, `Url`, `Length`, `Count`, `Range`, `Pattern`, `NotBlank`, backed enums, RFC 3339 dates; custom rules compile to inline PHP |
| OpenAPI | 3.1 document generated from the same types, on every build. Client SDK generation: *planned* |
| Modules | `#[Module(exports, dependsOn)]`; using a non-exported class, or a module you do not depend on, **fails the build**; dependency cycles rejected |
| Concurrency | Fibers with structured concurrency: `await_all()` with timeouts and cancellation; non-blocking HTTP client (curl_multi). Non-blocking database drivers: *planned* (PDO is blocking) |
| HTTP client | PSR-18, timeouts always set, per-host circuit breaker and bulkhead, trace propagation, RED metrics |
| Database | Parameterised SQL with `literal-string` enforcement, typed `Row`s, read/write splitting with read-your-writes, statement timeouts, result-size cap, savepoints, N+1 detection (fails tests), forward-only migrations. ORM/entity mapping: *planned* |
| Observability | OpenTelemetry-style traces (W3C `traceparent`, OTLP/HTTP export), Prometheus RED metrics per route, JSON logs with trace ids, `/health/live`, `/health/ready` |
| Safety defaults | Rate limiting, idempotency keys, load shedding, body limits, security headers, `trustProxy()` named as unsafe, problem+json errors, production boot checks |
| Testing | In-process `TestApp`; frozen clock, seeded randomness, sequential ids and a fake HTTP client are bound automatically in `test`. Parallel isolated databases: *planned tooling* |
| Tooling | `keel new`, `build`, `routes`, `inspect` (JSON for agents), `openapi`, `check`, `migrate`, `serve` |
| Queues, outbox, scheduler, cache | *planned (v0.2 to v0.4)* |

Quality bar, enforced in CI: PHPStan **level max** with zero errors on the framework, the example
application **and the generated code**.

## Quick start

Keel is not on Packagist yet. From a checkout of this repository:

```bash
composer install
bin/keel new ../acme        # scaffolds an app with a worker entry point, a typed endpoint, a test and AGENTS.md
```

Then, in the new project (once `keel/framework` resolves, e.g. through a Composer `path` repository):

```bash
vendor/bin/keel serve       # http://127.0.0.1:8000/hello/world
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

To try the example application in this repository:

```bash
cd examples/shop
../../bin/keel migrate && ../../bin/keel serve
curl -X POST localhost:8000/orders -H 'X-Tenant-Id: 11111111-1111-4111-8111-111111111111' \
  -H 'Content-Type: application/json' \
  -d '{"merchantId":"22222222-2222-4222-8222-222222222222","items":[{"sku":"TEA","quantity":1,"unitPriceCents":300}]}'
```

In production, build during deploy and run workers:

```bash
KEEL_ENV=prod vendor/bin/keel build --env=prod
KEEL_ENV=prod vendor/bin/keel check --env=prod   # refuses single-node or leaky settings
frankenphp run                                   # see the generated Caddyfile (worker mode)
```

## A tour in five files

| Concept | Example |
| --- | --- |
| Application shape (build time) | [examples/shop/app.php](examples/shop/app.php) |
| Runtime configuration (boot time) | [examples/shop/config.php](examples/shop/config.php) |
| Endpoint, input, output | [CreateOrder.php](examples/shop/src/Orders/CreateOrder.php), [CreateOrderInput.php](examples/shop/src/Orders/CreateOrderInput.php), [OrderResource.php](examples/shop/src/Orders/OrderResource.php) |
| Module boundary + events across modules | [BillingModule.php](examples/shop/src/Billing/BillingModule.php), [CreateInvoiceWhenOrderPlaced.php](examples/shop/src/Billing/CreateInvoiceWhenOrderPlaced.php) |
| Concurrent I/O | [GetOrderSummary.php](examples/shop/src/Orders/GetOrderSummary.php) |
| End-to-end tests | [tests/Http/ShopTest.php](tests/Http/ShopTest.php) |

## Documentation

- [Manifesto](docs/manifesto.md): principles, what we reject, scalability, promises, roadmap, governance
- [Architecture](docs/architecture.md): layers, the compiler, the request lifecycle, generated files
- Guides: [getting started](docs/guide/getting-started.md) · [endpoints and types](docs/guide/endpoints-and-types.md) ·
  [services and lifetimes](docs/guide/services-and-lifetimes.md) · [modules](docs/guide/modules.md) ·
  [concurrency](docs/guide/concurrency.md) · [database](docs/guide/database.md) ·
  [observability](docs/guide/observability.md) · [production](docs/guide/production.md) · [testing](docs/guide/testing.md)
- [Contributing](CONTRIBUTING.md) · [Security policy](SECURITY.md) · [RFC template](docs/rfcs/0000-template.md) · [Changelog](CHANGELOG.md)

## Performance

`php -d opcache.enable_cli=1 benchmarks/kernel.php`, one worker, in-process (no network), PHP 8.3, Apple silicon:

| Scenario | req/s | µs/req |
| --- | ---: | ---: |
| `GET /health/live` (full default middleware stack) | 55,370 | 18.1 |
| `GET /orders/{id}`, not found (dynamic route, SQL read) | 33,290 | 30.0 |
| `POST /orders`, 422 (JSON parse + validation) | 44,301 | 22.6 |
| `POST /orders`, 201 (validation, transaction, event, serialization) | 18,921 | 52.9 |

Peak memory after 13,000 requests: 4 MB. These numbers measure framework overhead only; publish your own
end-to-end numbers with a load tool against a FrankenPHP worker.

## Requirements

PHP 8.3+ (`ext-json`, `ext-mbstring`; `ext-curl` for the HTTP client, `ext-pcntl` for graceful shutdown,
`ext-pdo` for the database layer, `ext-redis` for shared stores). Following the support policy, v1.0 will
require the current and previous PHP release.

## License

MIT. The core stays MIT forever.
