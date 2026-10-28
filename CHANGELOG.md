# Changelog

All notable changes are documented here. Until 1.0, minor versions may contain breaking changes; each one is
listed under **Breaking** with an upgrade note.

## 0.1.0 (unreleased)

The first roadmap phase: core.

### Added

- Build-time compiler: container, routes, endpoints, hydrators, serializers, listener map, OpenAPI 3.1 and
  `metadata.json`, written atomically into a per-environment build directory.
- Container with `Singleton`, `Scoped` and `Transient` lifetimes, `#[Config]`, `#[All]`, `#[Factory]`,
  bindings per environment, scopes with disposal, and build errors for lifetime violations, cycles and
  missing services.
- Attribute routing with typed path parameters, 404/405, named routes.
- Typed inputs and outputs with constraints (`Uuid`, `Email`, `Url`, `Length`, `Count`, `Range`, `Pattern`,
  `NotBlank`), strict JSON, lenient query strings, unknown-field rejection and violation paths.
- HTTP kernel with PSR-15 middleware and RFC 9457 errors. Default middleware: request context, telemetry,
  load shedding, security headers, rate limiting, body limit, idempotency keys.
- Runtimes: FrankenPHP worker mode, RoadRunner, FPM/CGI; worker recycling and graceful drain on SIGTERM.
- Fiber scheduler with structured concurrency (`await_all`, timeouts, cancellation) and a non-blocking PSR-18
  client with circuit breaker and bulkhead.
- Modules with exports and dependencies, enforced at build time.
- SQL layer: literal-string queries, typed rows, list parameters, read/write split with read-your-writes,
  statement timeouts, result cap, savepoints, N+1 detection, migrations.
- Observability: JSON logs with trace ids, tracing with W3C propagation and OTLP/HTTP export, Prometheus RED
  metrics, liveness and readiness endpoints.
- Testing: `TestApp`, frozen clock, seeded randomness, sequential ids, fake HTTP client.
- CLI: `new`, `build`, `routes`, `inspect`, `openapi`, `check`, `migrate`, `serve`.
- Production boot checks.
- Example multi-module application and the kernel benchmark.

### Contributors

Omar Hendawy.
