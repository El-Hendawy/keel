# Architecture

Keel has two halves: a **compiler** that runs before deploy and a **runtime** that boots its output once per
worker. Anything that can be decided from the source code is decided by the compiler; the runtime only does
what depends on the request.

## The compiler (`Keel\Build`)

`keel build --env=<env>` (or the first boot in `dev`/`test`) runs these steps:

1. **Scan.** `ClassFinder` tokenizes the directories listed in `app.php` without executing them, recording each
   file's classes and `use` imports (needed for docblock types and module checks).
2. **Services.** Every class with `#[Singleton]`, `#[Scoped]` or `#[Transient]` becomes a definition, as do
   `#[Factory]` methods, framework defaults and bound implementations. Endpoints and listeners default to
   Transient. A dependency whose class declares a lifetime is registered wherever it lives.
3. **Validate the graph** (`ContainerCompiler`): missing services (with a suggested fix), cycles, and lifetime
   violations. A Singleton must not need a scope, directly or through Transients.
4. **Routes and types** (`RouteCompiler`, `TypeAnalyzer`): each `__invoke` parameter is classified as a path
   parameter, a service, the `Principal` or *the* input object. Inputs and outputs are analysed recursively; an
   untyped array is an error. Constraint attributes are checked against the field type. With auth enabled,
   `#[Authorize]` scopes and policies (and deny-by-default) are compiled into each dispatch method.
5. **Listeners, jobs, schedule and modules.** Listener maps are compiled; `#[Handler]` jobs and async
   listeners get a hydrator and serializer for their payload, so jobs are encoded and validated by generated
   code; `#[Schedule]` cron expressions are validated. `ModuleChecker` verifies that every cross-module
   reference targets an export of a declared dependency.
6. **Write**, atomically (temp file and rename, so a running worker never reads half a file):

| File | Contents |
| --- | --- |
| `Container.php` | A final subclass of `Keel\Container\Container`: `LIFETIMES` and `ALIASES` constants, one factory method per service, and a `match` dispatching to them |
| `Endpoints.php` | One static method per route: resolve services from the scope, hydrate input, invoke, serialize |
| `Hydrators.php` | One method per input class: type checks, coercion (query only), constraints, unknown-field rejection, violation paths |
| `Serializers.php` | One method per output class: public properties to arrays; enums and dates converted |
| `manifest.php` | Route table (static map + combined regexes), listener and async-listener maps, jobs, schedule, middleware list, bindings, framework migrations, fingerprint |
| `openapi.json` | OpenAPI 3.1 from the same types |
| `metadata.json` | Everything above as data, for `keel inspect`, tools and coding agents |

Generated classes live in `Keel\Generated\B<hash>`, so several applications (or builds) can be loaded in one
process, which the test suite does.

All errors are collected and reported together, each naming the class and the fix:

```
The build failed with 2 error(s):

  - Singleton PriceCache cannot depend on Scoped CurrentTenant. Make it Scoped, or depend on something with a longer lifetime.
  - Module "orders" uses App\Billing\InvoiceRepository from module "billing" (in App\Orders\Checkout) but does not list "billing" in dependsOn.
```

## The runtime

```
Runtime::run(app.php)
 └─ Application::boot()               once per worker
     ├─ load manifest (rebuild if stale, dev/test only; prod requires `keel build`)
     ├─ require generated classes
     ├─ load config.php → new Container(config)
     ├─ provide Environment, BuildManifest
     └─ ProductionChecks (prod)

 loop: for each request
     HttpKernel::handle(request)
      ├─ scope = container->createScope()          ← everything request-specific lives here
      ├─ Pipeline (each middleware resolved from the scope)
      │    RequestContext → Telemetry → LoadShedding → SecurityHeaders → RateLimit → BodyLimit
      │      → Authentication (with ->withAuth()) → Idempotency → yours
      │    └─ RouteDispatcher: Router::match → Endpoints::rN(scope, request, params)
      │                                         (auth guard, hydrate, invoke, serialize)
      ├─ errors → ErrorRenderer → problem+json (inside the pipeline, so telemetry sees the status)
      └─ finally: scope->dispose()                ← Disposable services closed, open transactions rolled back
     emit response
     Application::afterRequest()                   ← export spans after the client has its response
     recycle? (max requests, memory, SIGTERM)
```

### Lifetimes in a worker

| Lifetime | Instances | Typical use |
| --- | --- | --- |
| Singleton | One per worker, for its whole life | Stateless services, connection managers, clients, caches of immutable data |
| Scoped | One per scope (request, job, message), disposed at the end | Anything that knows "who" or "which": current tenant, unit of work, request context, `Database` |
| Transient | New on every injection | Endpoints, listeners, cheap stateless helpers |

The rule that keeps workers safe: **a longer-lived service must not hold a shorter-lived one.** The compiler
enforces it, so request state cannot leak into the next request through a singleton.

### Concurrency

`Keel\Async` is a small fiber scheduler: deferred callbacks, timers and `curl_multi`. `await_all()` starts each
closure in a fiber and suspends the caller; operations that would block (a timer, an HTTP call) register with
the loop and suspend their fiber. Outside any fiber the same calls drive the loop until done, so code is
identical under FPM and under a worker.

Structured concurrency rules:

- `await_all()` returns or throws only after every operation has settled.
- The first failure cancels the others (`CancelledException` is thrown at their suspension point) and is rethrown.
- `timeout:` cancels everything and throws `TimeoutException`.
- If the caller is cancelled, its children are cancelled.

Spans started inside a task nest under the span that was active when the task started.

### Background work

Queue workers and the scheduler boot the same build as the web workers and open one scope per job or task,
exactly like a request. A job's tenant and trace context travel with it. See
[background work](guide/background-work.md).

### Why the hot path is fast

- Service resolution: one constant-array lookup and a `match` on a string (compiled to a hash jump table).
- Routing: a hash lookup for static paths; one `preg_match` per 40 dynamic routes per method otherwise.
- Validation: straight-line generated PHP, no attribute instantiation, no reflection.
- Arrays in generated files are immutable and live in opcache shared memory.

See `benchmarks/kernel.php` for numbers.
