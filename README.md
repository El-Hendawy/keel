# Keel

**The typed, long-running, API-first PHP framework for SaaS backends.**

Keel boots once and serves many requests (FrankenPHP, RoadRunner, FPM as fallback). It compiles routes, the
container, validation and serialization into plain PHP before deploy, so the hot path has no reflection.
Types are the contract: one input class is the validation, the documentation and the API schema.

> **Documentation: <https://el-hendawy.github.io/keel/>**, with a [tutorial](https://el-hendawy.github.io/keel/tutorial/)
> that builds a multi-tenant API from scratch. Read [the manifesto](docs/manifesto.md) for the why.

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

## Status: v0.2 (developer preview)

v0.1 delivered the core: router, compiled container, typed I/O, worker runtime and production defaults.
v0.2 adds what a SaaS backend needs next: authentication with client-bound tokens, enforced tenancy,
background jobs with a transactional outbox, a scheduler, caching, webhooks, pagination and a TypeScript
SDK, plus a compiled ORM for every PDO database, TOON as a wire format, and a native FIX engine. Everything below is implemented and tested; items marked *planned* are on the
[roadmap](docs/manifesto.md#roadmap).

| Area | What you get |
| --- | --- |
| Runtime | FrankenPHP worker mode, RoadRunner, FPM/CGI fallback; graceful shutdown (SIGTERM drains), max requests/memory recycling. Swoole: *planned (v0.2)* |
| Container | Compiled to plain PHP; `Singleton` / `Scoped` / `Transient` lifetimes; a Singleton depending on a Scoped service (directly or through Transients) is a **build error**; cycles and missing bindings reported with a fix |
| Routing | Attribute routes on invokable classes, static map plus combined regexes, typed path parameters (`int` only matches digits), 404/405 with `Allow`, named routes |
| Typed I/O | Generated hydrators: strict types, unknown fields rejected, every violation with its path (`items[0].sku`); query strings coerced; outputs serialized from public properties; arrays are rejected as contracts at build time |
| Validation | `Uuid`, `Email`, `Url`, `Length`, `Count`, `Range`, `Pattern`, `NotBlank`, backed enums, RFC 3339 dates; custom rules compile to inline PHP |
| Wire formats | JSON, and [TOON](docs/guide/toon.md) (Token-Oriented Object Notation) with `->withToon()`: negotiated by `Accept`, accepted as request bodies, listed in OpenAPI |
| FIX | Native [FIX](docs/guide/fix.md) engine (`keel fix:run`): acceptor and initiator sessions, logon, sequence recovery with resend and gap fill, heartbeats, rejects; typed messages and repeating groups compiled at build time; handlers per message type |
| OpenAPI and SDK | OpenAPI 3.1 generated from the same types, including security; `keel sdk` writes a typed, dependency-free TypeScript client |
| Modules | `#[Module(exports, dependsOn)]`; using a non-exported class, or a module you do not depend on, **fails the build**; dependency cycles rejected |
| Concurrency | Fibers with structured concurrency: `await_all()` with timeouts and cancellation; non-blocking HTTP client (curl_multi). Non-blocking database drivers: *planned* (PDO is blocking) |
| HTTP client | PSR-18, timeouts always set, per-host circuit breaker and bulkhead, trace propagation, RED metrics |
| ORM | Compiled data mapper ([guide](docs/guide/orm.md)): plain typed entities, no lazy loading (unloaded relations throw), identity map with dirty-column updates, optimistic locking, automatic tenant scoping, soft deletes, timestamps, `whereHas`, eager loading in one query per relation, keyset `cursorPaginate()`, `lazy()` streaming, upserts, pivots, schema generation and forward-only diff. Dialects for SQLite, PostgreSQL, MySQL/MariaDB, SQL Server, Oracle and standard SQL for other PDO drivers |
| Database | Parameterised SQL with `literal-string` enforcement, typed `Row`s, read/write splitting with read-your-writes, statement timeouts, result-size cap, savepoints, prepared-statement cache, N+1 detection (fails tests), forward-only migrations. Tested on SQLite, PostgreSQL and MySQL. ORM/entity mapping: *planned* |
| Auth | `->withAuth()`: **deny by default** (`#[Anonymous]` opts out), `#[Authorize(scopes, policy)]` compiled into endpoints, `Principal` injection, hashed opaque tokens (`keel_...`) and JWTs (HS/RS, no algorithm confusion). **Tokens are bound to the client's fingerprint by default**: a stolen token used from another client is refused and revoked ([guide](docs/guide/auth.md)) |
| Tenancy | Tenant from the principal, never from client input. The tenant guard refuses SQL on tenant tables that does not filter by the current tenant; `acrossTenants()` is the named exception; PostgreSQL row-level security support |
| Background work | `->withQueue()`: typed jobs with retries, backoff and failed-job tooling; **transactional outbox** (jobs commit with your data); async listeners; cron scheduler that runs each task once across nodes; distributed locks |
| Webhooks | Signed (Standard Webhooks), queued with retries, SSRF-protected against private networks |
| Cache and pagination | In-memory LRU or Redis cache with stampede protection; signed keyset cursors |
| Observability | OpenTelemetry-style traces (W3C `traceparent`, OTLP/HTTP export), Prometheus RED metrics per route, JSON logs with trace ids, `/health/live`, `/health/ready` |
| Safety defaults | Rate limiting, idempotency keys, load shedding, body limits, security headers, `trustProxy()` named as unsafe, problem+json errors, production boot checks (debug, local-only stores, public metrics, missing `app.key`) |
| Testing | In-process `TestApp`; frozen clock, seeded randomness, sequential ids and a fake HTTP client are bound automatically in `test`; `tokenFor()`, `runJobs()`, `runSchedule()`; the same tests run on SQLite, PostgreSQL or MySQL (`KEEL_TEST_DATABASE_DSN`) |
| Tooling | `keel new`, `build`, `routes`, `inspect` (JSON for agents), `openapi`, `sdk`, `check`, `migrate`, `serve`, `make:endpoint`, `make:migration`, `token:*`, `key:generate`, `queue:*`, `schedule:*` ([reference](docs/guide/tooling.md)) |
| Planned | Non-blocking database drivers, Swoole, typed JSON columns, tenant connection routing, PHPStan extension, FIX over TLS (v0.3+) |

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
../../bin/keel migrate
../../bin/keel token:issue --principal=ada --tenant=11111111-1111-4111-8111-111111111111 --scopes='orders:*,invoices:read'
../../bin/keel serve
```

Then, in another terminal, with the token it printed:

```bash
TOKEN=keel_...
curl -X POST localhost:8000/orders -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"merchantId":"22222222-2222-4222-8222-222222222222","items":[{"sku":"TEA","quantity":1,"unitPriceCents":300}]}'
curl localhost:8000/orders -H "Authorization: Bearer $TOKEN"
../../bin/keel queue:work --once   # runs the async listener that notifies the merchant
```

The token is bound to the first client that uses it (here, curl). Send it with a different `User-Agent` and
it is refused and revoked.

In production, build during deploy and run workers:

```bash
KEEL_ENV=prod vendor/bin/keel build --env=prod
KEEL_ENV=prod vendor/bin/keel check --env=prod   # refuses single-node or leaky settings
KEEL_ENV=prod vendor/bin/keel migrate
frankenphp run                                   # see the generated Caddyfile (worker mode)
KEEL_ENV=prod vendor/bin/keel queue:work         # and schedule:work, as separate processes
```

## A tour in five files

| Concept | Example |
| --- | --- |
| Application shape (build time) | [examples/shop/app.php](examples/shop/app.php) |
| Runtime configuration (boot time) | [examples/shop/config.php](examples/shop/config.php) |
| Endpoint, input, output | [CreateOrder.php](examples/shop/src/Orders/CreateOrder.php), [CreateOrderInput.php](examples/shop/src/Orders/CreateOrderInput.php), [OrderResource.php](examples/shop/src/Orders/OrderResource.php) |
| Module boundary + events across modules | [BillingModule.php](examples/shop/src/Billing/BillingModule.php), [CreateInvoiceWhenOrderPlaced.php](examples/shop/src/Billing/CreateInvoiceWhenOrderPlaced.php) |
| Concurrent I/O | [GetOrderSummary.php](examples/shop/src/Orders/GetOrderSummary.php) |
| Auth, the principal, sign-out | [Me.php](examples/shop/src/Account/Me.php), [RevokeCurrentToken.php](examples/shop/src/Account/RevokeCurrentToken.php) |
| Async listener + webhook (outbox) | [NotifyMerchantOfOrder.php](examples/shop/src/Orders/NotifyMerchantOfOrder.php) |
| Cursor pagination | [ListOrders.php](examples/shop/src/Orders/ListOrders.php) |
| Scheduled, deliberately cross-tenant task | [ReportDailyOrders.php](examples/shop/src/Orders/ReportDailyOrders.php) |
| ORM entity, cursor pages, optimistic locking | [Product.php](examples/shop/src/Catalog/Product.php), [ListProducts.php](examples/shop/src/Catalog/ListProducts.php), [UpdateProductPrice.php](examples/shop/src/Catalog/UpdateProductPrice.php) |
| FIX venue: typed messages and a handler | [examples/fix-gateway](examples/fix-gateway/src/AcceptOrder.php) |
| End-to-end tests | [tests/Http/ShopTest.php](tests/Http/ShopTest.php) |

## Documentation

The site at <https://el-hendawy.github.io/keel/> is built from `docs/` on every push to `main`
(`mkdocs build --strict`; preview locally with `pip install -r requirements-docs.txt && mkdocs serve`).

- [Manifesto](docs/manifesto.md): principles, what we reject, scalability, promises, roadmap, governance
- [Architecture](docs/architecture.md): layers, the compiler, the request lifecycle, generated files
- Guides: [getting started](docs/guide/getting-started.md) · [endpoints and types](docs/guide/endpoints-and-types.md) ·
  [services and lifetimes](docs/guide/services-and-lifetimes.md) · [modules](docs/guide/modules.md) ·
  [auth and token binding](docs/guide/auth.md) · [tenancy](docs/guide/tenancy.md) ·
  [background work](docs/guide/background-work.md) · [cache and pagination](docs/guide/cache-and-pagination.md) ·
  [ORM](docs/guide/orm.md) · [TOON](docs/guide/toon.md) · [FIX](docs/guide/fix.md) ·
  [concurrency](docs/guide/concurrency.md) · [database](docs/guide/database.md) ·
  [observability](docs/guide/observability.md) · [production](docs/guide/production.md) · [testing](docs/guide/testing.md) ·
  [tooling](docs/guide/tooling.md)
- [Contributing](CONTRIBUTING.md) · [Security policy](SECURITY.md) · [RFC template](docs/rfcs/0000-template.md) · [Changelog](CHANGELOG.md)

## Performance

`php -d opcache.enable_cli=1 benchmarks/kernel.php`, one worker, in-process (no network), PHP 8.3, Apple silicon.
Since v0.2 the shop requires authentication, so every request but the health check carries a bearer token
that is looked up and checked against the client's fingerprint:

| Scenario | v0.2 req/s | µs/req | v0.1 req/s (no auth) |
| --- | ---: | ---: | ---: |
| `GET /health/live` (full default middleware stack) | 51,600 | 19.4 | 55,370 |
| `GET /orders/{id}`, not found (auth, dynamic route, SQL read) | 23,400 | 42.7 | 33,290 |
| `POST /orders`, 422 (auth, JSON parse + validation) | 29,700 | 33.6 | 44,301 |
| `POST /orders`, 201 (auth, validation, transaction, event, outbox job, serialization) | 13,350 | 74.9 | 18,921 |

Authentication with token binding costs about 11 µs per request (a token lookup by hash plus three HMACs);
the created order now also writes an outbox job. Peak memory after 13,000 requests: 4 MB. These numbers
measure framework overhead only. For end-to-end numbers against Laravel Octane, see
[benchmarks/vs-laravel](benchmarks/vs-laravel/README.md) (measured on v0.1).

## Requirements

PHP 8.3+ (`ext-json`, `ext-mbstring`; `ext-curl` for the HTTP client, `ext-pcntl` for graceful shutdown,
`ext-pdo` plus the driver for your database, `ext-redis` for shared stores). Following the support policy, v1.0 will
require the current and previous PHP release.

## License

MIT. The core stays MIT forever.
