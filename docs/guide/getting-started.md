# Getting started

## Create a project

Requirements: PHP 8.3+ with `ext-json` and `ext-mbstring`, plus `ext-pdo` and the driver for your database,
`ext-curl` for the HTTP client, `ext-pcntl` for graceful shutdown and `ext-redis` for shared stores.

```bash
composer global require el-hendawy/keel     # once: installs the `keel` command
keel new acme                               # scaffold a project
cd acme && composer install
keel serve                                  # development server on http://127.0.0.1:8000
```

Make sure Composer's global bin directory is on your `PATH` (`composer global config bin-dir --absolute`).
Inside a project, `keel` runs the project's own version.

Keel's Composer package is `el-hendawy/keel` (namespace `Keel\`). Until the first tagged release it is
installed from `main` (`^0.3@dev`). To add Keel to an existing project:
`composer require el-hendawy/keel:^0.3@dev`. The [tutorial](../tutorial.md) builds a complete API from
here.

The skeleton contains:

| Path | Purpose |
| --- | --- |
| `app.php` | The application's shape, compiled by `keel build`: what to scan, bindings, middleware |
| `config.php` | Runtime values, read once per worker at boot. `$env` is the `Environment` |
| `public/index.php` | The single entry point for FrankenPHP (worker or classic), RoadRunner and FPM |
| `src/<Module>/` | Your code, one directory per module |
| `tests/` | In-process tests with `TestApp` |
| `Caddyfile` | FrankenPHP in worker mode |
| `AGENTS.md` | Conventions for coding agents (and humans) |

## The development loop

In `dev` and `test`, the build refreshes itself when a source file changes. A long-running worker cannot
reload classes it has already loaded, so run FrankenPHP with `--watch` (or restart RoadRunner workers) in
development; `keel serve` runs one process per request and picks up every change.

```bash
vendor/bin/keel routes      # what is mounted where
vendor/bin/keel inspect     # everything the compiler knows, as JSON
vendor/bin/phpstan analyse  # level max, from day one
vendor/bin/phpunit
```

## Your first endpoint

```php
namespace App\Greeting;

use Keel\Routing\Attribute\Get;
use Psr\Clock\ClockInterface;

/** Greets someone by name. */
#[Get('/hello/{name}', name: 'greeting.hello')]
final class SayHello
{
    public function __invoke(string $name, ClockInterface $clock): Greeting
    {
        return new Greeting("Hello, $name!", $clock->now());
    }
}

final readonly class Greeting
{
    public function __construct(public string $message, public \DateTimeImmutable $at) {}
}
```

The docblock's first line becomes the OpenAPI summary. `$name` matches `{name}`; `ClockInterface` is a
service, so it is injected; the return type is the response schema.

Next: [endpoints and types](endpoints-and-types.md).
