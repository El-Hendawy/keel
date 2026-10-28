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

## Commands

```bash
composer install                      # or COMPOSER_VENDOR_DIR=/elsewhere composer install
composer check                        # PHPStan max + PHPUnit
vendor/bin/phpstan analyse -c phpstan.neon.dist examples/shop/var/build/test   # generated code
php -d opcache.enable_cli=1 benchmarks/kernel.php
```

On macOS with the repository on an exFAT drive, delete AppleDouble files before running PHPUnit:
`find . -name '._*' -delete`.
