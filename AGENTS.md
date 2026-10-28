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
| `src/Orm`, `src/Build/Orm` | The ORM runtime (Orm, Query, Criteria, dialects, schema) and its compiler |
| `src/Http/Toon` | TOON encoder/decoder; negotiation is `Http/Middleware/ContentNegotiationMiddleware` |
| `src/Fix`, `src/Build/Fix` | FIX codec, session, engine, gateway, and the typed-message compiler |
| `examples/fix-gateway` | A FIX venue used by the end-to-end FIX test |
| `src/Database`, `src/Observability`, `src/Events`, `src/Module`, `src/Testing`, `src/Console` | As named |
| `examples/shop` | The reference application; the end-to-end tests run against it |
| `tests/Fixtures` | Small applications that must fail or succeed to build |

## Rules

- The hot path (anything called per request) must not use reflection, attributes or string parsing of class
  names. Move that work into `src/Build` and generate code.
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
vendor/bin/phpstan analyse -c phpstan.neon.dist examples/shop/var/build/test   # generated code
php -d opcache.enable_cli=1 benchmarks/kernel.php
```

On macOS with the repository on an exFAT drive, delete AppleDouble files before running PHPUnit:
`find . -name '._*' -delete`.
