<?php

declare(strict_types=1);

namespace Keel\Console;

use Keel\Build\Builder;
use Keel\Build\BuildFailed;
use Keel\Database\ConnectionManager;
use Keel\Database\Migrator;
use Keel\Keel;
use Keel\Runtime\AppDefinition;
use Keel\Runtime\Application;
use Keel\Runtime\ProductionChecks;

/**
 * The `keel` command. Small on purpose: every command is a thin wrapper around a
 * class you can also call from your own code.
 */
final class Cli
{
    private const string HELP = <<<'TXT'
        Keel %s, the typed, long-running PHP framework.

        Usage: keel <command> [options]

          new <dir>         Create a new project
          build             Compile the application (run this in your deploy pipeline)
          routes            List routes
          inspect           Print build metadata as JSON: routes, services, modules, config keys
          openapi           Print the OpenAPI document
          check             Run the production boot checks without serving traffic
          migrate           Apply pending SQL migrations from ./migrations
          serve             Start the development server (PHP built-in server)

        Options:
          --app=<file>      Application file (default: ./app.php)
          --env=<name>      Environment (default: KEEL_ENV or dev)
          --port=<port>     Port for `serve` (default: 8000)
          --dir=<path>      Migrations directory for `migrate` (default: ./migrations)

        TXT;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        [$command, $arguments, $options] = self::parse(\array_slice($argv, 1));
        $appFile = self::absolute($options['app'] ?? 'app.php');
        $env = $options['env'] ?? (getenv('KEEL_ENV') ?: 'dev');

        try {
            return match ($command) {
                'new' => self::create($arguments[0] ?? null, $options),
                'build' => self::build($appFile, $env),
                'routes' => self::routes($appFile, $env),
                'inspect' => self::inspect($appFile, $env),
                'openapi' => self::openapi($appFile, $env),
                'check' => self::check($appFile, $env),
                'migrate' => self::migrate($appFile, $env, self::absolute($options['dir'] ?? 'migrations')),
                'serve' => self::serve($appFile, $options),
                'version', '--version', '-V' => self::out(Keel::VERSION),
                default => self::out(\sprintf(self::HELP, Keel::VERSION), $command === null || \in_array($command, ['help', '--help', '-h'], true) ? 0 : 1),
            };
        } catch (BuildFailed $e) {
            fwrite(\STDERR, $e->getMessage() . "\n");

            return 1;
        } catch (\Throwable $e) {
            fwrite(\STDERR, $e::class . ': ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    private static function build(string $appFile, string $env): int
    {
        $start = hrtime(true);
        $builder = new Builder(self::definition($appFile), $env);
        $manifest = $builder->build();
        $routes = \count($manifest->data['routes']['routes']);

        return self::out(\sprintf(
            'Built %s for "%s" in %.0f ms: %d routes, %d listener types -> %s',
            basename(\dirname($appFile)),
            $env,
            (hrtime(true) - $start) / 1e6,
            $routes,
            \count($manifest->data['listeners']),
            $builder->directory(),
        ));
    }

    private static function routes(string $appFile, string $env): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $rows = [];
        foreach ($app->http()->router()->routes() as $route) {
            $rows[] = [$route['method'], $route['path'], (string) $route['name'], (string) $route['status']];
        }
        $widths = [6, 4, 4, 6];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], \strlen($cell));
            }
        }
        $out = [self::row(['METHOD', 'PATH', 'NAME', 'STATUS'], $widths)];
        foreach ($rows as $row) {
            $out[] = self::row($row, $widths);
        }

        return self::out(implode("\n", $out));
    }

    /**
     * @param list<string> $cells
     * @param list<int> $widths
     */
    private static function row(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($cells as $i => $cell) {
            $padded[] = str_pad($cell, $widths[$i] ?? 0);
        }

        return rtrim(implode('  ', $padded));
    }

    private static function inspect(string $appFile, string $env): int
    {
        $app = Application::boot($appFile, ['env' => $env]);

        return self::out((string) file_get_contents($app->manifest->directory . '/metadata.json'));
    }

    private static function openapi(string $appFile, string $env): int
    {
        $app = Application::boot($appFile, ['env' => $env]);

        return self::out((string) file_get_contents($app->manifest->directory . '/openapi.json'));
    }

    private static function check(string $appFile, string $env): int
    {
        (new Builder(self::definition($appFile), $env))->build();
        $app = Application::boot($appFile, ['env' => $env, 'checks' => false]);
        $problems = ProductionChecks::problems($app);
        if ($problems === []) {
            return self::out('All production checks passed.');
        }
        fwrite(\STDERR, "Production checks failed:\n  - " . implode("\n  - ", $problems) . "\n");

        return 1;
    }

    private static function migrate(string $appFile, string $env, string $directory): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        if (!$app->container()->has(ConnectionManager::class)) {
            throw new \RuntimeException('The database layer is not enabled. Add ->withDatabase() in app.php.');
        }
        $applied = (new Migrator($app->container()->get(ConnectionManager::class)->writer(), $directory))->migrate();

        return self::out($applied === [] ? 'Nothing to migrate.' : "Applied:\n  " . implode("\n  ", $applied));
    }

    /** @param array<string, string> $options */
    private static function serve(string $appFile, array $options): int
    {
        $port = (int) ($options['port'] ?? 8000);
        $host = $options['host'] ?? '127.0.0.1';
        $router = \dirname(__DIR__) . '/Runtime/dev-router.php';
        $autoload = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        $autoload = \dirname((string) $autoload, 2) . '/autoload.php';
        self::out("Keel dev server on http://$host:$port (Ctrl+C to stop). Use FrankenPHP or RoadRunner in production.");
        $command = \sprintf(
            'KEEL_APP=%s KEEL_AUTOLOAD=%s PHP_CLI_SERVER_WORKERS=4 %s -S %s:%d %s',
            escapeshellarg($appFile),
            escapeshellarg($autoload),
            escapeshellarg(\PHP_BINARY),
            $host,
            $port,
            escapeshellarg($router),
        );
        passthru($command, $code);

        return $code;
    }

    /** @param array<string, string> $options */
    private static function create(?string $directory, array $options): int
    {
        if ($directory === null) {
            throw new \InvalidArgumentException('Usage: keel new <directory>');
        }
        $target = self::absolute($directory);
        $name = $options['name'] ?? basename($target);
        (new ProjectGenerator())->generate($target, $name);

        return self::out("Created $name in $target.\n\n  cd $directory\n  composer install\n  vendor/bin/keel serve\n\nThen open http://127.0.0.1:8000/hello/world");
    }

    private static function definition(string $appFile): AppDefinition
    {
        $definition = require $appFile;
        if (!$definition instanceof AppDefinition) {
            throw new \RuntimeException("$appFile must return an AppDefinition.");
        }

        return $definition;
    }

    private static function out(string $text, int $code = 0): int
    {
        fwrite($code === 0 ? \STDOUT : \STDERR, rtrim($text) . "\n");

        return $code;
    }

    private static function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
    }

    /**
     * @param list<string> $args
     * @return array{0: ?string, 1: list<string>, 2: array<string, string>}
     */
    private static function parse(array $args): array
    {
        $command = null;
        $arguments = [];
        $options = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
                $options[$key] = $value;
            } elseif ($command === null) {
                $command = $arg;
            } else {
                $arguments[] = $arg;
            }
        }

        return [$command, $arguments, $options];
    }
}
