# The Keel Manifesto

Oct 28, 2026 · Omar Hendawy

> Sections and paragraphs marked **[added]** fill in details the first draft left open: the name, the
> architecture and roadmap figures, and the contracts (errors, configuration, async semantics, deprecation)
> that the principles imply but did not spell out. Everything marked has been implemented in v0.1 or is
> stated as a roadmap item.

## Preamble

PHP is no longer a language that dies after every request, but its frameworks still behave as if it were. We are building the framework for how PHP actually runs today: long-lived workers, strict types, static analysis, concurrent I/O and code written alongside AI agents.

The two leaders earned their place. Laravel proved that developer happiness wins adoption. Symfony proved that clean components and stability win trust. Both were designed in the request-per-process era, and both now retrofit the present: worker mode as an add-on, types as an afterthought, magic that static analysers and agents cannot follow.

We are not here to clone them with fewer features. We are here to start from 2026 assumptions and make the right way the easy way.

**[added] The name.** The framework is called **Keel**: the structural spine of a ship, laid first, that keeps it steady and on course for decades. Package `el-hendawy/keel`, namespace `Keel\`, command `keel`.

## Positioning

**The typed, long-running, API-first PHP framework for SaaS backends.**

We are for:

- Teams building multi-tenant SaaS, marketplaces, fintech and platform backends that live for years.
- Engineers who want PHPStan at max level to pass on day one, not after a cleanup sprint.
- Organisations that need throughput, observability and predictable latency without leaving PHP.
- Teams that write code with AI agents and need a codebase those agents can reason about.

We are not for:

- Brochure sites, blogs and quick CRUD prototypes. Laravel and WordPress serve those better.
- Teams who prefer magic to explicitness. We will always choose the explicit option.
- Shared hosting with no ability to run a worker process.

**[added] Non-goals.** Server-rendered templating, session-based web UIs, admin panels and asset pipelines are out of scope for the core. Keel serves JSON APIs; put a frontend framework or a BFF in front of it.

## The twelve principles

1. **Long-running is the default.** The application boots once and serves many requests under FrankenPHP, RoadRunner or Swoole. FPM is supported as a fallback, never as the design centre.
2. **State has a declared lifetime.** Every service is `Singleton`, `Scoped` (one per request, job or message) or `Transient`. A scoped service injected into a singleton is a boot error, not a production memory leak.
3. **Compile, don't reflect.** Routes, the container graph, listeners, validators and serializers are resolved at build time into plain PHP. The hot path contains no reflection and no `__call`.
4. **Types are the contract.** A typed input object is the validation, the documentation and the client SDK. One source of truth, generated everywhere else.
5. **If the analyser can't see it, it doesn't exist.** No facades, no magic properties, no string-based service lookup. The framework's own code and every generated file pass PHPStan at max level.
6. **Explicit beats clever.** We will accept three more characters to remove one surprise. Conventions are allowed; hidden behaviour is not.
7. **Concurrency is built in.** Fibers with structured concurrency are part of the core. Database, HTTP, cache and queue drivers are non-blocking under a worker and transparently synchronous under FPM.
8. **Boundaries are enforced.** An application is a set of modules with declared public APIs. Crossing a boundary without going through that API fails the build.
9. **Production-ready from `new project`.** Tracing, metrics, structured logs, health checks, graceful shutdown, rate limiting and idempotency are on by default, not packages to discover later.
10. **Secure by default, unsafe by name.** Every dangerous operation is opt-in and named as such (`rawQuery`, `unescaped`, `trustProxy`). Safe is the short path.
11. **Tests are first-class citizens.** Clock, randomness, IDs and outbound HTTP are injected and fakeable by contract. The test suite runs in-process, in parallel, with isolated databases.
12. **Built for humans and agents.** Predictable structure, introspectable metadata and one obvious way to do each thing, so that both a new hire and an AI coding agent can be productive in an hour.

**[added] How each principle is enforced in v0.1.**

| # | Mechanism |
| --- | --- |
| 1 | `Runtime::run()` detects FrankenPHP worker mode or RoadRunner; the classic runtime is the fallback. Workers recycle after `KEEL_MAX_REQUESTS` or `KEEL_MAX_MEMORY_MB` and drain on SIGTERM. |
| 2 | The compiler computes, for every service, whether it needs a scope (Scoped, or Transient depending on Scoped). A Singleton that does is a build error naming the full path. |
| 3 | `keel build` writes `Container.php`, `Endpoints.php`, `Hydrators.php`, `Serializers.php` and `manifest.php` (route table, listener map, middleware). Runtime resolution is a constant-array lookup plus a `match`. |
| 4 | Input classes generate hydrators and the OpenAPI schema. Array properties without an item type, untyped parameters and `array` return types are build errors. |
| 5 | No facades or global helpers exist. CI runs PHPStan max on the framework, the example and a generated build. |
| 6 | Endpoints and listeners default to Transient; everything else declares a lifetime or is not a service. Removing a default middleware is a named call, `withoutMiddleware()`. |
| 7 | `await_all()` (structured: siblings are cancelled on failure or timeout, and the call returns only when all have settled). The HTTP client and timers are non-blocking today; database, cache and queue drivers follow (see roadmap). |
| 8 | `#[Module]` plus a build-time check of imports, fully qualified names and constructor types. |
| 9 | The default middleware stack and the `/health/*` and `/metrics` endpoints exist in every app; production boot checks refuse single-node or leaky settings. |
| 10 | `Database::rawQuery()`, `AppDefinition::trustProxy()`, `withoutMiddleware()`. SQL passed to `select()`/`execute()` must be a `literal-string`. |
| 11 | In the `test` environment, `ClockInterface`, `Random`, `IdGenerator` and `ClientInterface` are bound to `FrozenClock`, `SeededRandom`, `SequentialIdGenerator` and `FakeHttpClient`, which fails on any unstubbed call. |
| 12 | `keel inspect` emits routes, services with lifetimes and dependencies, modules, listeners, middleware and every config key with its type and default, as JSON. New projects ship an `AGENTS.md`. |

## What we reject

Each of these is a deliberate refusal, with what we offer instead.

| We reject | Why | Instead |
| --- | --- | --- |
| Facades and global helpers | Hide dependencies from readers, analysers and agents | Constructor injection, compiled |
| Magic attributes on models | Untyped, invisible to static analysis | Typed entities and readonly DTOs |
| Lazy loading by default | Silent N+1 queries in production | Explicit eager loading; N+1 fails tests |
| Runtime reflection in the hot path | Slow and unpredictable | Build-time compilation into plain PHP |
| Static and global state | Leaks between requests in workers | Lifetimes enforced by the container |
| Arrays as data contracts | Typos become runtime bugs | Typed input and output objects |
| "Install a package for that" for basics | Fragmented, inconsistent production setups | Observability, queues and scheduling in core |
| Breaking changes without a path | Erodes trust and strands teams | Automated upgrade rectors with every major |
| Docs that only show the happy path | Teams learn production lessons the hard way | Recipes for failure, scale and migration |

**[added] The one deliberate global.** The fiber event loop (`Keel\Async\Loop`) is process-wide, like the PHP engine. It holds no application state, and structured concurrency guarantees that every task finishes before the scope that started it closes.

## Architecture at a glance

The compiler turns attributes and types into plain PHP before deploy; the runtime boots that output once and serves many requests from it.

**[added] The five layers and the compiler:**

```
                       BUILD TIME (keel build)                                   RUN TIME (per worker)
  ┌──────────────────────────────────────────────┐
  │ Compiler                                     │    ┌───────────────────────────────────────────────────────┐
  │  scan → validate graph → generate PHP        │    │ 1. Runtime adapter   FrankenPHP · RoadRunner · FPM      │
  │                                              │    │    boot once, loop, recycle, drain on SIGTERM           │
  │  services & lifetimes ──► Container.php      │    ├───────────────────────────────────────────────────────┤
  │  routes & params      ──► manifest.php,      │    │ 2. HTTP kernel       opens a Scope per request,         │
  │                           Endpoints.php      │──► │    PSR-15 middleware: context, telemetry, shedding,     │
  │  input types          ──► Hydrators.php      │    │    security headers, rate limit, body limit, idempotency│
  │  output types         ──► Serializers.php    │    ├───────────────────────────────────────────────────────┤
  │  listeners, modules   ──► manifest.php       │    │ 3. Endpoints         route match → hydrate input →      │
  │  everything           ──► openapi.json,      │    │    invoke → serialize output (all generated code)       │
  │                           metadata.json      │    ├───────────────────────────────────────────────────────┤
  │                                              │    │ 4. Modules           your code: services, events,       │
  │  fails on: lifetime violations, cycles,      │    │    declared exports and dependencies                    │
  │  missing bindings, untyped contracts,        │    ├───────────────────────────────────────────────────────┤
  │  route conflicts, boundary violations        │    │ 5. Platform          container, async loop, HTTP client,│
  └──────────────────────────────────────────────┘    │    database, tracing, metrics, logs, health             │
                                                      └───────────────────────────────────────────────────────┘
```

Only the compiled container is shared across requests; everything request-specific lives in a scope the kernel opens and closes.

See [architecture.md](architecture.md) for the request lifecycle and the generated files.

## Scalability

A service built on the framework scales by adding identical nodes, with no code changes. Every default assumes there will be more than one instance, more than one worker and eventually more than one database.

We scale at five levels, and each has a built-in answer rather than a package to find later.

| Level | The bottleneck | What the framework does |
| --- | --- | --- |
| Inside one process | Waiting on I/O, re-booting per request | Worker mode boots once; fibers run independent I/O concurrently; connection pools are per worker |
| Across nodes | Local state that breaks behind a load balancer | Nodes are stateless by contract: sessions, cache, locks and uploads go to shared drivers (Redis, object storage). Local disk or memory state fails a production boot check |
| Data | One primary database doing everything | Read/write splitting with read-your-writes consistency; tenant-aware connection routing for database-per-tenant or sharded setups; query timeouts and result-size limits by default |
| Background work | Slow work blocking requests, lost or duplicated jobs | Queues with retries, backoff, dead-letter queues and idempotency keys; the transactional outbox for events; consumers scale independently of the web tier |
| Traffic and failure | Spikes and slow dependencies cascading | Rate limiting, backpressure (bounded queues and concurrency limits), timeouts, circuit breakers and bulkheads on every outbound client; load shedding returns 503 before the node falls over |

Three rules hold everything together:

1. **Measure before scaling.** OpenTelemetry traces and RED metrics (rate, errors, duration) per endpoint, job and query are on by default, so the bottleneck is visible before anyone guesses.
2. **Cache with explicit ownership.** Every cache entry has a declared key, TTL and invalidation event; tagged invalidation and stampede protection are built in.
3. **Grow without rewrites.** A module can move from in-process calls to a queue, and later to a separate service, by changing its transport configuration, not its code.

We will ship a load-testing kit with the framework: reference scenarios, a profile mode for workers and a capacity report that shows requests per second, p95 latency and memory per worker on the team's own hardware.

**[added] Where each level stands in v0.2.** Inside one process: done. Across nodes: the production check rejects in-memory rate-limit, idempotency, token and lock stores; Redis and database stores ship. Data: read/write splitting, read-your-writes, statement timeouts, the result-size cap and the tenant guard (with PostgreSQL row-level security) are done; tenant connection routing is roadmap. Background work: database queue with transactional outbox, retries and failed-job tooling, async listeners and a cluster-safe scheduler are done (v0.2); broker transports are roadmap. Traffic and failure: rate limiting, queue-time load shedding, timeouts, circuit breakers and bulkheads are done. The benchmark in `benchmarks/kernel.php` is the first piece of the load-testing kit.

## What the code looks like

An endpoint is a class. Its typed input is parsed, validated and documented with no extra code.

```php
#[Post('/orders')]
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

Lifetimes are declared, and wrong wiring fails at boot.

```php
#[Scoped]
final class CurrentTenant { /* resolved per request */ }

#[Singleton]
final class PriceCache
{
    // Boot error: Singleton PriceCache cannot depend on Scoped CurrentTenant.
    public function __construct(private CurrentTenant $tenant) {}
}
```

Concurrent I/O is one call, with cancellation and timeouts handled for you.

```php
[$user, $orders, $rates] = await_all(
    fn() => $users->find($id),
    fn() => $orders->forUser($id),
    fn() => $http->get('/fx/rates'),
    timeout: 2.0,
);
```

Modules declare what they expose. Everything else is private to the module.

```php
#[Module(
    name: 'billing',
    exports: [InvoiceService::class, InvoicePaid::class],
    dependsOn: ['tenancy'],
)]
final class BillingModule {}
```

**[added] Contracts the examples imply.**

- **Errors** are RFC 9457 `application/problem+json`, always with `type`, `title` and `status`, plus a `requestId`. Validation failures are 422 with `errors: [{path, code, message}]`. The `code` values (`required`, `type`, `unknown_field`, `null`, `enum`, `length`, `count`, `range`, `uuid`, `email`, `url`, `pattern`, `not_blank`) are stable API: changing one is a breaking change.
- **Unknown fields are rejected.** A misspelled field is a 422, never silently ignored.
- **Inputs are strict, query strings are lenient.** JSON `"5"` is not an `int`; `?limit=5` is.
- **Outputs** are the public properties of the returned object. Enums serialize to their value, dates to RFC 3339 with milliseconds. Returning `null` is not allowed: throw `NotFound`.
- **Status codes** are declared: `#[Status(201)]`; `void` endpoints return 204.

## Configuration and secrets [added]

There are two moments, and they never mix:

| | Build time: `app.php` | Boot time: `config.php` |
| --- | --- | --- |
| Holds | Shape: scanned directories, bindings per environment, middleware, trusted proxies | Values: DSNs, URLs, tokens, limits |
| Read | Once, by `keel build` | Once per worker, at boot |
| Secrets | Never | From environment variables only |

A service asks for a value with `#[Config('database.dsn')] string $dsn`. The build lists every key, with its type and default, in `keel inspect`; a missing required key fails when the worker boots, not on the first request that needs it.

## Our promises

These are commitments, written so that users can hold us to them.

| Area | Promise |
| --- | --- |
| Release cadence | One major per year, minors every quarter, security patches as needed |
| Backward compatibility | No breaking change in a minor. Every major ships with an automated upgrade (Rector rules) and a written guide |
| Support window | Each major gets 2 years of bug fixes and 3 years of security fixes |
| PHP versions | We support the current and previous PHP release, and adopt new language features within one minor |
| Performance | Published, reproducible benchmarks on every release. A regression over 5% blocks the release |
| Static analysis | The framework and all generated code pass PHPStan at max level. A regression is a bug |
| Security | A public disclosure policy, a response within 72 hours, and fixes backported to every supported major |
| Standards | PSR-7, 11, 14, 15, 17 and 18 compatible, so existing libraries work without adapters |
| Openness | MIT licence for the core, forever. Paid tools may exist; core features will never move behind them |

**[added] What counts as the public API.** Everything not marked `@internal`: attributes, interfaces, the `AppDefinition` builder, the `Testing` namespace, config keys, error codes, metric and span names, CLI commands and their output formats with `--json`. Generated code is not public API: rebuild after every upgrade.

**[added] Deprecation policy.** A feature is deprecated in a minor (documented, logged once per worker at boot when used), keeps working until the next major, and is removed there with a Rector rule that rewrites call sites. No deprecation lands without its replacement.

**[added] 0.x exception.** Until 1.0, minors may break, always with an entry in the changelog and an upgrade note. 0.x supports PHP 8.3+ to widen early testing; 1.0 applies the PHP policy above.

## Roadmap

We ship small and prove each layer in production before building the next. No phase starts until the previous gate is passed.

**[added] Four phases, three gates:**

```
 Phase 1: Core (v0.1)  ──Gate A──►  Phase 2: Work (v0.2–0.4)  ──Gate B──►  Phase 3: Scale (v0.5–0.9)  ──Gate C──►  Phase 4: Stable (v1.0)
```

| Phase | Scope |
| --- | --- |
| **1. Core**, v0.1 *(this release)* | Router, compiled container with lifetimes, typed I/O and OpenAPI, worker runtime (FrankenPHP, RoadRunner, FPM). Production defaults: tracing, metrics, logs, health, graceful shutdown, rate limiting, idempotency, load shedding. Fibers and `await_all`, non-blocking HTTP client, modules, SQL layer with N+1 detection, testing fakes, CLI. |
| **2. Work**, v0.2 to v0.4 | **Done in v0.2:** queues (retries, backoff, failed jobs), transactional outbox, async listeners, scheduler, cache with stampede protection, authentication with client-bound tokens, tenant guard, signed webhooks, cursor pagination, TypeScript SDK generation, tests on PostgreSQL and MySQL. **Next:** cache tags, job idempotency keys, non-blocking database and Redis drivers under workers, Swoole adapter, online-safe migration checks, parallel test runner. Also shipped in v0.2: typed entity mapping compiled at build time (the ORM), TOON, and a FIX engine. |
| **3. Scale**, v0.5 to v0.9 | Tenant-aware connection routing (database per tenant, shards), module transports (in-process, queue, remote) without code changes, a PHP client SDK (TypeScript ships in v0.2), load-testing kit and capacity report, Rector upgrade tooling, failure and migration recipes. |
| **4. Stable**, v1.0 | The promises above take effect: support windows, PHP policy, deprecation policy, LTS. |

| Gate | Passed when |
| --- | --- |
| **A**, Core is real | One real service has run on v0.1 in production for 30 days; PHPStan max is clean on it; worker memory is flat over 10,000 requests; the kernel benchmark is published for every commit to `main`. |
| **B**, Work is reliable | Three production applications run queue consumers that scale independently of the web tier; an outbox failover drill (kill the broker mid-publish) loses and duplicates nothing; no open P1 bugs. |
| **C**, Ready to promise | At least three core maintainers; an external security review is complete with findings fixed; ten user-facing decisions have gone through the RFC process; upgrade Rectors exist for every 0.x break. |

v0.1 alone (router, compiled container, typed I/O and worker runtime) must already be good enough to run a real service.

## Governance and community

The framework is a project, not a person. Decisions are made in public and written down.

- **RFCs for anything user-facing.** New APIs, deprecations and breaking changes go through a public RFC with at least two weeks of comment.
- **A core team, not a single maintainer.** Merge rights are earned through sustained contribution, with at least three core maintainers before v1.0.
- **Production first.** Every core feature is extracted from, or proven in, a real production application before it ships.
- **Docs are part of the change.** A pull request without documentation and tests is not finished.
- **Contributors are credited.** Every release note names the people who made it.
- **A code of conduct that is enforced.** Respect is a requirement for participation, not a suggestion.
- **Sustainable funding.** Sponsorship and optional commercial tooling fund maintainers, with the core always free.

**[added]** The process lives in [CONTRIBUTING.md](../CONTRIBUTING.md), the RFC template in [rfcs/0000-template.md](rfcs/0000-template.md), the disclosure policy in [SECURITY.md](../SECURITY.md) and the code of conduct in [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md).

## Our commitment

We will measure ourselves by what happens a year after launch: fewer production incidents, faster onboarding, and codebases that are still easy to change.

We will say no often. Every feature has to earn its place against these principles. When a choice is between convenient magic and boring clarity, we will choose clarity.

PHP deserves a framework built for the way it runs today. This is our promise to build it, in the open, for the long term.
