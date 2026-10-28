# Getting started

## Create a project

```bash
bin/keel new ../acme          # from a checkout of the framework
cd ../acme && composer install
vendor/bin/keel serve         # development server on http://127.0.0.1:8000
```

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
