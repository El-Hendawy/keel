---
hide:
  - navigation
---

<div class="keel-hero" markdown>

# Keel

<p class="tagline">The typed, long-running, API-first PHP framework for SaaS backends.</p>

[Start the tutorial](tutorial.md){ .md-button .md-button--primary }
[Read the manifesto](manifesto.md){ .md-button }
[GitHub](https://github.com/El-Hendawy/keel){ .md-button }

</div>

Keel boots once and serves many requests (FrankenPHP, RoadRunner, FPM as a fallback). It compiles routes,
the container, validation and serialization into plain PHP before deploy, so nothing on the hot path uses
reflection. Types are the contract: one input class is the validation, the documentation and the API schema.

```php
#[Post('/orders')]
#[Status(201)]
#[Authorize(scopes: ['orders:write'])]
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

Send `{"merchantID": "x", "items": []}` and the client gets every problem, with its path, without a line of
validation code:

```json
{
  "type": "about:blank", "title": "Validation failed", "status": 422,
  "errors": [
    {"path": "merchantID", "code": "unknown_field", "message": "Unknown field."},
    {"path": "merchantId", "code": "required", "message": "This field is required."},
    {"path": "items", "code": "count", "message": "Must contain at least 1 item(s)."}
  ]
}
```

## What you get

<div class="grid cards" markdown>

-   :material-lightning-bolt: **Compiled, concurrent**

    ---

    Container, routes, hydrators and serializers are generated PHP. `keel run` serves many requests per
    process on an event loop with non-blocking PostgreSQL, Redis and HTTP; FrankenPHP and RoadRunner work too.

-   :material-shield-lock: **Secure by default**

    ---

    Every endpoint needs a token unless marked `#[Anonymous]`. Tokens are bound to the client that holds
    them. Rate limits, idempotency keys, security headers and production boot checks are on from day one.

-   :material-domain: **Built for SaaS**

    ---

    Tenants come from the token, never from input. SQL on tenant tables must filter by the current tenant,
    and the ORM does it for you.

-   :material-database: **A compiled ORM**

    ---

    Plain typed entities, no lazy loading, an identity map, optimistic locking and keyset pagination on
    SQLite, PostgreSQL, MySQL, SQL Server, Oracle and standard SQL.

-   :material-tray-full: **Background work**

    ---

    Typed jobs with a transactional outbox, async listeners, a cluster-safe scheduler, signed webhooks and
    a cache with stampede protection.

-   :material-robot: **Agent-friendly**

    ---

    `keel inspect` prints the whole application as JSON, OpenAPI is generated from the types, `keel sdk`
    writes a TypeScript client, and TOON answers cost language models fewer tokens.

-   :material-chart-line: **Observable**

    ---

    Traces with W3C propagation and OTLP export, Prometheus RED metrics per route, JSON logs with trace
    ids, liveness and readiness endpoints.

-   :material-access-point: **Real time**

    ---

    WebSockets and server-sent events on the same endpoints and auth as the rest of the API, with a
    pub/sub hub across processes and nodes.

-   :material-swap-horizontal: **FIX protocol**

    ---

    A native FIX engine: sessions with sequence recovery and heartbeats, typed messages compiled at build
    time, handlers per message type.

</div>

## Five minutes to a running API

```bash
composer global require el-hendawy/keel   # the `keel` command
keel new acme && cd acme && composer install
keel serve                                # http://127.0.0.1:8000/hello/world
keel run                                  # the Keel server: many requests per process, WebSockets, SSE
```

Then follow the [tutorial](tutorial.md): a multi-tenant notes API with a database, authentication,
validation, pagination, tests and a production build, in about 150 lines.

## Performance

With a 10 ms database query per request, `keel run` with the non-blocking PostgreSQL driver served
**17,027 req/s** in 244 MB, where FrankenPHP with 64 workers served 5,734 req/s; without I/O, 100,478 vs
51,985 req/s. Method and caveats: [the Keel server](guide/server.md#benchmark).

In-process, one worker, PHP 8.3 on Apple silicon (`benchmarks/kernel.php`), every request but the health
check authenticated with a bound token:

| Scenario | req/s | µs/req |
| --- | ---: | ---: |
| `GET /health/live` (full default middleware) | 51,600 | 19.4 |
| `GET /orders/{id}` (auth, dynamic route, SQL read) | 23,400 | 42.7 |
| `POST /orders` 422 (auth, JSON parse, validation) | 29,700 | 33.6 |
| `POST /orders` 201 (auth, validation, transaction, outbox) | 13,350 | 74.9 |

End to end against Laravel Octane on the same FrankenPHP binary (v0.1, without auth on either side), Keel
served 2× to 6.3× more requests per second on PostgreSQL. See the
[benchmark](https://github.com/El-Hendawy/keel/tree/main/benchmarks/vs-laravel) for the method and caveats.

## Status

Keel is a **developer preview (0.3)**. Everything documented here is implemented and tested; until 1.0, minor
versions may break compatibility, with upgrade notes in the
[changelog](https://github.com/El-Hendawy/keel/blob/main/CHANGELOG.md). PHP 8.3 or later.
