# Changelog

All notable changes are documented here. Until 1.0, minor versions may contain breaking changes; each one is
listed under **Breaking** with an upgrade note.

## 0.3.0 (unreleased)

### Added

- **`keel run`**: Keel's own HTTP/1.1 server. Many requests per process on the event loop (a fiber per
  connection), keep-alive, chunked bodies, limits and timeouts, a supervisor that runs N processes on one
  socket, restarts crashed ones and drains on SIGTERM. Singletons are shared by a process's requests.
- **Non-blocking PostgreSQL** (`pgsql-async:` DSN) on ext-pgsql's asynchronous API, with prepared statements
  per connection, PDO-compatible errors, and a connection pool (`database.pool_size`) leased per request.
  `Database` now runs on a `Connection` interface (PDO or async).
- **WebSockets** (RFC 6455) accepted from ordinary endpoints (`WebSocket::accept()`), handlers run in a fresh
  scope per event with the caller's principal and tenant; browser token auth through the subprotocol.
- **Server-sent events** (`EventStream`, `EventSink`), also under FrankenPHP and FPM.
- **Hub**: pub/sub for real-time clients, across processes and nodes with `realtime.redis`.
- A non-blocking Redis client (`AsyncRedis`) and socket watchers on the event loop (`Io`).
- **OpenID Connect**: discovery, JWKS with caching and rotation on unknown key ids (with a cool-down),
  ES256/ES384 and RS* keys, and the authorization code flow with PKCE (`OidcClient`).
- **DPoP** (RFC 9449): proof verification (signature, htm/htu, iat, ath, jti replay protection) and tokens
  bound to the client's key (`cnf.jkt` for JWTs, `dpopKey:` for Keel tokens).
- **Broker transports** for the queue: Redis Streams, Amazon SQS (JSON API, SigV4 signed without the SDK)
  and RabbitMQ (php-amqplib), fed by the transactional outbox (`queue.transport`).
- **PHPStan extension** (`extension.neon`) checking ORM queries: column and relation names, value types,
  operators, `update()` keys.
- The `keel` command works when installed globally and runs the project's own version inside a project.

### Changed

- `Database` leases connections from the `ConnectionManager` instead of using a shared PDO directly.
  `ConnectionManager::prepare()` is gone; use `acquire()`/`release()` or `with()`.
- `Application::boot()` accepts `defaults` (applied under config.php).
- The documentation site opens on a new landing page, and the docs use the Keel design system (Archivo,
  JetBrains Mono, self-hosted). The former home page is now `overview`.

### Fixed

- The supervisor's signal handlers no longer restart interrupted system calls, so SIGTERM stops it.

## 0.2.0 (unreleased)

Toward phase 2, "Work", plus security and SaaS features.

### Added

- **Authentication and authorization** (`->withAuth()`): deny by default with `#[Anonymous]` as the opt-out;
  `#[Authorize(scopes, policy)]` compiled into endpoints; `Principal` parameters; opaque `keel_` tokens stored
  as SHA-256 hashes (`keel_tokens`), with issue, revoke and revoke-all; JWT verification and issuing
  (HS256/384/512, RS256/384/512, algorithm pinned per key); OpenAPI security schemes.
- **Token binding by default**: tokens are bound to an HMAC fingerprint of the client (normalised user agent,
  language, JA4 from trusted proxies, optional IP prefix), at issue or on first use. Mismatches are refused
  and the token revoked (`enforce`) or only reported (`report`); a `FingerprintMismatch` event, a warning log
  and `auth_fingerprint_mismatches_total` are emitted. `->unsafeWithoutTokenBinding()` turns it off.
- **Tenancy**: `TenantContext` set from the principal; the tenant guard refuses statements on
  `database.tenant_tables` that do not filter by and bind the current tenant; `acrossTenants()`; PostgreSQL
  row-level security via `database.tenant_rls`.
- **Queue** (`->withQueue()`): typed jobs with `#[Handler]`, retries with exponential backoff and jitter,
  failed jobs, `SKIP LOCKED` reservation, trace and tenant propagation, and a transactional outbox (jobs are
  written through the request's transaction). Async listeners with `#[Listener(async: true)]`.
- **Scheduler**: `#[Schedule('cron')]`, validated at build time, run once per due minute across nodes with
  distributed locks (in-memory, Redis or database).
- **Cache**: in-memory LRU and Redis caches with XFetch early refresh and miss locking.
- **Webhooks** (`->withWebhooks()`): Standard Webhooks signatures, queued delivery with retries, SSRF
  protection, and a verifier for incoming webhooks.
- **Pagination**: signed, purpose-bound keyset cursors and `PageInfo`.
- **TypeScript SDK**: `keel sdk` generates a typed, dependency-free client from the OpenAPI document.
- **CLI**: `make:endpoint`, `make:migration`, `token:issue`, `token:revoke`, `key:generate`, `queue:work`,
  `queue:failed`, `queue:retry`, `schedule:run`, `schedule:work`.
- **Testing**: `tokenFor()`, `runJobs()`, `runSchedule()`, `freshDatabase()`, `TestDatabase` and
  `KEEL_TEST_DATABASE_DSN`; CI runs the database tests on PostgreSQL and MySQL.
- **ORM**: compiled data mapper with `#[Entity]`, typed columns (enums, decimals, JSON, UTC date-times),
  `Many`/`One` relations that are never lazy, identity map and dirty-column updates, `#[Version]` optimistic
  locking, `#[TenantKey]` automatic tenant scoping, soft deletes, timestamps, criteria with `whereHas`, eager
  loading, keyset `cursorPaginate()`, `lazy()`, bulk update/delete, upserts and pivots. Dialects for SQLite,
  PostgreSQL, MySQL/MariaDB, SQL Server, Oracle and ANSI SQL; `keel orm:schema` and forward-only
  `keel orm:diff --write`.
- **TOON**: `->withToon()` negotiates Token-Oriented Object Notation responses and accepts TOON bodies; a strict
  encoder/decoder; `text/toon` in OpenAPI.
- **FIX**: tag=value codec and stream parser, a session state machine (logon, sequence recovery, heartbeats,
  rejects, logout), file-backed sequence stores, typed messages with repeating groups compiled at build time,
  `#[FixHandler]` dispatch, `FixGateway`, and the `keel fix:run` engine. Example: `examples/fix-gateway`.
- Documentation site (MkDocs Material, deployed to GitHub Pages) with a tutorial; `keel new` projects
  install Keel from GitHub; `TestResponse::assertJsonCount()`.
- Prepared statements are cached per connection.
- `app.key` with HKDF-derived keys per purpose; production refuses to boot without it.

### Fixed

- The N+1 detector counts only reads; batched writes no longer trip it.

- Responses replayed from an idempotency key are reported under their route in metrics and traces, instead
  of `unmatched`.
- The HTTP client test no longer depends on `php -S` worker processes, which made it fail on some CI hosts.

### Breaking

- The Composer package is now `el-hendawy/keel` (the `keel` vendor on Packagist belongs to someone else).
  Replace `keel/framework` in your `composer.json`; the `Keel\` namespace is unchanged.

- `IdempotencyMiddleware` is now scoped (it records the route), and `StoredResponse` has a `route` field.
  Custom idempotency stores keep working; entries stored by 0.1 replay without a route.
- `Listener` is now a readonly class with an `async` argument.
- `Migrator` takes one or more directories; use `Migrator::forApplication()` to include framework migrations.
- The production checks now require `app.key`.

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
