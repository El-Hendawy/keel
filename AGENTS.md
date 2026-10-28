# Working on the Keel framework

Instructions for coding agents and new contributors working on this repository.

## Layout

| Path | Contents |
| --- | --- |
| `src/Build` | The compiler: scanning, service graph, routes, hydrators, serializers, OpenAPI, the `Builder` |
| `src/Container` | Runtime container base class, scopes, lifetime attributes |
| `src/Http` | Kernel, pipeline, dispatcher, middleware, operational endpoints, client, errors |
| `src/Routing`, `src/Validation` | Route attributes and the runtime router; constraint attributes |
| `src/Runtime` | `AppDefinition`, `Application` boot, runtimes, production checks |
| `src/Async` | Fiber loop, tasks, `await_all` |
| `src/Auth` | Authentication, `#[Authorize]`, tokens, JWTs, client fingerprints and token binding |
| `src/Tenancy` | `TenantContext` and the tenant guard used by the Database |
| `src/Queue`, `src/Scheduling`, `src/Locks` | Jobs, the database queue (outbox), worker, cron scheduler, distributed locks |
| `src/Cache`, `src/Pagination`, `src/Webhooks`, `src/Security` | Cache, signed cursors, outgoing webhooks, `app.key` derivation |
| `src/Build/Sdk` | TypeScript client generator |
| `src/Server`, `src/Realtime` | `keel run` (HTTP/1.1 server, supervisor), WebSockets, server-sent events support, the Hub |
| `src/Database/Driver` | Connection interface: PDO and the non-blocking PostgreSQL driver |
| `src/Queue/Transport` | Broker transports (Redis Streams, SQS with SigV4, RabbitMQ) and the broker worker |
| `src/PHPStan` | The PHPStan extension (`extension.neon`) that checks ORM queries |
| `src/Orm`, `src/Build/Orm` | The ORM runtime (Orm, Query, Criteria, dialects, schema) and its compiler |
| `src/Http/Toon` | TOON encoder/decoder; negotiation is `Http/Middleware/ContentNegotiationMiddleware` |
| `src/Fix`, `src/Build/Fix` | FIX codec, session, engine, gateway, and the typed-message compiler |
| `examples/fix-gateway` | A FIX venue used by the end-to-end FIX test |
| `src/Crypto` | `Encrypter` (data at rest), JWE and end-to-end message encryption |
| `src/Money`, `src/Ledger` | Exact money and the double-entry, hash-chained ledger |
| `src/Service` | Microservices: service calls and tokens, deadlines, CloudEvents, the inbox |
| `src/NoSql` | MongoDB (wire protocol, BSON), DynamoDB, Elasticsearch/OpenSearch, CouchDB, Neo4j, InfluxDB, `DocumentStore` |
| `src/Database`, `src/Observability`, `src/Events`, `src/Module`, `src/Testing`, `src/Console` | As named |
| `examples/shop` | The reference application; the end-to-end tests run against it |
| `tests/Fixtures` | Small applications that must fail or succeed to build |

## Rules

- The hot path (anything called per request) must not use reflection, attributes or string parsing of class
  names. Move that work into `src/Build` and generate code.
- Code that runs under `keel run` must not keep per-request state in singletons: many requests share a
  process. Per-request state is `#[Scoped]`.
- Generated code must pass PHPStan max. After changing a compiler, analyse a fresh build (see below).
- Every error message names the class involved and the fix.
- No `@phpstan-ignore`, no baseline.
- Keep `docs/` in sync in the same change.
- Security-relevant defaults (deny by default, token binding, tenant guard, SSRF guard) are only turned off
  by methods or keys whose name says `unsafe`.

## Commands

```bash
composer install                      # or COMPOSER_VENDOR_DIR=/elsewhere composer install
composer check                        # PHPStan max + PHPUnit
KEEL_TEST_DATABASE_DSN='pgsql:host=127.0.0.1;dbname=keel_test;user=keel' vendor/bin/phpunit tests/Http tests/Auth tests/Queue tests/Orm
KEEL_TEST_DATABASE_DSN='pgsql-async:host=127.0.0.1;dbname=keel_test;user=keel' vendor/bin/phpunit tests/Http tests/Auth tests/Queue tests/Orm tests/Database
KEEL_TEST_REDIS_URL=redis://127.0.0.1:6379 vendor/bin/phpunit tests/Server tests/Queue
vendor/bin/phpstan analyse -c phpstan.neon.dist examples/shop/var/build/test   # generated code
php -d opcache.enable_cli=1 benchmarks/kernel.php
```

On macOS with the repository on an exFAT drive, delete AppleDouble files before running PHPUnit:
`find . -name '._*' -delete`.
