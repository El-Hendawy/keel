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
          migrate           Apply pending SQL migrations (framework features first, then ./migrations)
          serve             Start the development server (PHP built-in server; rebuilds on change)
          run               Run the Keel server: event loop, many requests per process,
                            WebSockets and server-sent events [--host=127.0.0.1 --port=8080 --workers=N]
          sdk               Generate a TypeScript client from the OpenAPI document (--out=sdk/client.ts)

          queue:work        Process background jobs (--queue=default,webhooks --once --max-jobs=N)
          queue:failed      List failed jobs
          queue:retry <id>  Retry a failed job, or `all`
          schedule:run      Run the scheduled tasks due this minute (call from cron every minute)
          schedule:work     Run the scheduler continuously

          token:issue       Issue an API token: --principal=ID [--tenant=ID] [--scopes=a,b] [--ttl=0] [--name=..]
          token:revoke <id> Revoke a token by id
          key:generate      Print a new random app.key

          orm:schema        Print CREATE TABLE statements for the #[Entity] classes (this database's dialect)
          orm:diff          Print (or --write as a migration) what the database lacks for the entities
          fix:run           Run the FIX engine: the sessions in config fix.sessions [--session=a,b]

          make:endpoint <Name> --method=POST --path=/things [--scope=things:write]
          make:migration <name>

          crypto:e2e-key    Print a new key pair for end-to-end message encryption [--kid=ID]
          orm:reencrypt <C> Rewrite an entity's #[Encrypted] columns with the current key
          ledger:verify     Check the ledger's hash chain

          deploy:docker     Write a production Dockerfile (keel run, build in the image)
          deploy:kubernetes Write Deployment, Service, autoscaler and disruption budget
                            --name=orders --image=registry/orders:1.0 [--replicas=2 --max-replicas=10]

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
                'run' => self::runServer($appFile, $env, $options),
                'sdk' => self::sdk($appFile, $env, $options),
                'queue:work' => self::queueWork($appFile, $env, $options),
                'queue:failed' => self::queueFailed($appFile, $env),
                'queue:retry' => self::queueRetry($appFile, $env, $arguments[0] ?? throw new \InvalidArgumentException('Usage: keel queue:retry <id|all>')),
                'schedule:run' => self::scheduleRun($appFile, $env),
                'schedule:work' => self::scheduleWork($appFile, $env),
                'token:issue' => self::tokenIssue($appFile, $env, $options),
                'token:revoke' => self::tokenRevoke($appFile, $env, $arguments[0] ?? throw new \InvalidArgumentException('Usage: keel token:revoke <token-id>')),
                'fix:run' => self::fixRun($appFile, $env, $options),
                'orm:schema', 'orm:diff' => self::ormSchema($appFile, $env, $command === 'orm:diff', $options),
                'key:generate' => self::out('base64:' . base64_encode(random_bytes(32))),
                'make:endpoint' => self::makeEndpoint($arguments[0] ?? null, $options),
                'make:migration' => self::out("Created:\n  " . implode("\n  ", (new CodeGenerator((string) getcwd()))->migration($arguments[0] ?? throw new \InvalidArgumentException('Usage: keel make:migration <name>'), $options['dir'] ?? 'migrations'))),
                'deploy:docker' => self::out("Created:\n  " . implode("\n  ", (new DeploymentGenerator((string) getcwd()))->docker())),
                'deploy:kubernetes' => self::out("Created:\n  " . implode("\n  ", (new DeploymentGenerator((string) getcwd()))->kubernetes(
                    $options['name'] ?? throw new \InvalidArgumentException('Usage: keel deploy:kubernetes --name=orders --image=registry.example.com/orders:1.0 [--replicas=2 --max-replicas=10]'),
                    $options['image'] ?? throw new \InvalidArgumentException('Usage: keel deploy:kubernetes --name=orders --image=registry.example.com/orders:1.0'),
                    (int) ($options['replicas'] ?? 2),
                    (int) ($options['max-replicas'] ?? 10),
                ))),
                'crypto:e2e-key' => self::e2eKey($options['kid'] ?? gmdate('Y-m') . '-' . bin2hex(random_bytes(2))),
                'orm:reencrypt' => self::reencrypt($appFile, $env, $arguments[0] ?? throw new \InvalidArgumentException('Usage: keel orm:reencrypt <Entity class>')),
                'ledger:verify' => self::ledgerVerify($appFile, $env),
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
        $applied = Migrator::forApplication($app, $directory)->migrate();

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

    private static function e2eKey(string $kid): int
    {
        $pair = \Keel\Crypto\MessageKeys::generate($kid);
        fwrite(\STDOUT, "Private key (config crypto.e2e.keys.$kid; keep it secret, e.g. in an environment variable):\n\n{$pair['private']}\n");
        fwrite(\STDOUT, "Public key (what clients encrypt to; also served at /.well-known/keel-e2e-keys):\n\n" . json_encode($pair['public'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");

        return 0;
    }

    private static function reencrypt(string $appFile, string $env, string $class): int
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Class $class does not exist. Pass the entity's full class name, e.g. 'App\\Wallets\\Wallet'.");
        }
        $app = Application::boot($appFile, ['env' => $env]);
        $scope = $app->container()->createScope();
        try {
            $count = $scope->get(\Keel\Orm\Orm::class)->reencrypt($class);
        } finally {
            $scope->dispose();
        }

        return self::out("Re-encrypted $count row(s) of $class with the current key.");
    }

    private static function ledgerVerify(string $appFile, string $env): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $scope = $app->container()->createScope();
        try {
            $count = $scope->get(\Keel\Ledger\Ledger::class)->verify();
        } catch (\Keel\Ledger\LedgerTampered $e) {
            fwrite(\STDERR, $e->getMessage() . "\n");

            return 1;
        } finally {
            $scope->dispose();
        }

        return self::out("The ledger's $count transaction(s) match their hash chain.");
    }

    /** @param array<string, string> $options */
    private static function runServer(string $appFile, string $env, array $options): int
    {
        // Many requests share a process here: give each its own database connection by default.
        $app = Application::boot($appFile, ['env' => $env, 'defaults' => ['database' => ['pool_size' => 8]]]);
        $workers = (int) ($options['workers'] ?? 0);
        if ($workers <= 0) {
            $cpus = (int) trim((string) @shell_exec(\PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n hw.ncpu' : 'nproc'));
            $workers = max(1, $cpus);
        }

        return (new \Keel\Server\Supervisor($app, $options['host'] ?? '127.0.0.1', (int) ($options['port'] ?? 8080), $workers))->run();
    }

    /** @param array<string, string> $options */
    private static function sdk(string $appFile, string $env, array $options): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $openApi = json_decode((string) file_get_contents($app->manifest->directory . '/openapi.json'), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($openApi));
        /** @var array<string, mixed> $openApi */
        $code = (new \Keel\Build\Sdk\TypeScriptGenerator())->generate($openApi);
        $out = self::absolute($options['out'] ?? 'sdk/client.ts');
        if (!is_dir(\dirname($out)) && !mkdir(\dirname($out), 0o775, true) && !is_dir(\dirname($out))) {
            throw new \RuntimeException('Cannot create ' . \dirname($out));
        }
        file_put_contents($out, $code);

        return self::out("Wrote $out");
    }

    /** @param array<string, string> $options */
    private static function queueWork(string $appFile, string $env, array $options): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $queues = array_values(array_filter(explode(',', $options['queue'] ?? implode(',', self::queues($app)))));
        $transport = \Keel\Queue\Transport\Transports::fromContainer($app->container());
        if ($transport !== null) {
            $broker = new \Keel\Queue\BrokerWorker($app->container(), $transport);
            if (isset($options['once'])) {
                return self::out(\sprintf('Processed %d job(s).', $broker->work($queues, 0, 0.5, 0.5)));
            }
            \Keel\Runtime\WorkerPolicy::fromEnvironment()->listenForSignals($broker->stop(...));
            self::out('Processing ' . implode(', ', $queues) . ' from ' . $transport::class . ' (Ctrl+C or SIGTERM to stop after the current job).');
            $processed = $broker->work($queues, (int) ($options['max-jobs'] ?? 0));
            $app->shutdown();

            return self::out("Stopped after $processed job(s).");
        }
        $worker = new \Keel\Queue\QueueWorker($app->container(), (int) ($options['visibility'] ?? 300));
        if (isset($options['once'])) {
            return self::out(\sprintf('Processed %d job(s).', $worker->drain($queues)));
        }
        $policy = \Keel\Runtime\WorkerPolicy::fromEnvironment();
        $policy->listenForSignals($worker->stop(...));
        self::out('Processing ' . implode(', ', $queues) . ' (Ctrl+C or SIGTERM to stop after the current job).');
        $processed = $worker->work($queues, (int) ($options['max-jobs'] ?? 0), (float) ($options['sleep'] ?? 1.0));
        $app->shutdown();

        return self::out("Stopped after $processed job(s).");
    }

    /** @return list<string> every queue a handler uses */
    private static function queues(Application $app): array
    {
        $queues = array_values(array_unique(array_map(static fn (array $job): string => $job['queue'], $app->manifest->jobs())));

        return $queues === [] ? ['default'] : $queues;
    }

    private static function queueFailed(string $appFile, string $env): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $failed = (new \Keel\Queue\FailedJobs($app->container()->get(ConnectionManager::class)->writer()))->list();
        if ($failed === []) {
            return self::out('No failed jobs.');
        }
        $lines = array_map(static fn (array $j): string => "{$j['id']}  {$j['type']}  attempts={$j['attempts']}  {$j['failed_at']}\n    {$j['last_error']}", $failed);

        return self::out(implode("\n", $lines));
    }

    private static function queueRetry(string $appFile, string $env, string $id): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $count = (new \Keel\Queue\FailedJobs($app->container()->get(ConnectionManager::class)->writer()))->retry($id, time());

        return self::out("Re-queued $count job(s).");
    }

    private static function scheduleRun(string $appFile, string $env): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $ran = (new \Keel\Scheduling\Scheduler($app->container()))->runDue(new \DateTimeImmutable());

        return self::out($ran === [] ? 'No tasks due.' : "Ran:\n  " . implode("\n  ", $ran));
    }

    private static function scheduleWork(string $appFile, string $env): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $scheduler = new \Keel\Scheduling\Scheduler($app->container());
        $policy = \Keel\Runtime\WorkerPolicy::fromEnvironment();
        $policy->listenForSignals();
        self::out('Scheduler running (Ctrl+C or SIGTERM to stop).');
        while (!$policy->shouldStop(0)) {
            $scheduler->runDue(new \DateTimeImmutable());
            // Sleep to the start of the next minute; a signal interrupts the sleep.
            sleep(max(1, (intdiv(time(), 60) + 1) * 60 - time()));
        }
        $app->shutdown();

        return 0;
    }

    /** @param array<string, string> $options */
    private static function tokenIssue(string $appFile, string $env, array $options): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $principal = $options['principal'] ?? throw new \InvalidArgumentException('Usage: keel token:issue --principal=ID [--tenant=ID] [--scopes=a,b] [--kind=api_key] [--ttl=0] [--name=..]');
        $scopes = array_values(array_filter(array_map(trim(...), explode(',', $options['scopes'] ?? ''))));
        $issued = $app->container()->get(\Keel\Auth\Token\Tokens::class)->issue(
            $principal,
            $options['tenant'] ?? null,
            $scopes,
            $options['kind'] ?? \Keel\Auth\Principal::API_KEY,
            isset($options['ttl']) ? (int) $options['ttl'] : 0,
            null,
            $options['name'] ?? '',
        );

        return self::out(\sprintf(
            "Token id: %s\nExpires: %s\nBound to: the first client that uses it\n\n%s\n\nShown once. Store it in a secret manager.",
            $issued->id,
            $issued->expiresAt === null ? 'never' : gmdate('Y-m-d H:i:s \U\T\C', $issued->expiresAt),
            $issued->token,
        ));
    }

    private static function tokenRevoke(string $appFile, string $env, string $id): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $app->container()->get(\Keel\Auth\Token\Tokens::class)->revoke($id);

        return self::out("Revoked $id.");
    }

    /** @param array<string, string> $options */
    private static function ormSchema(string $appFile, string $env, bool $diff, array $options): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $container = $app->container();
        if (!$container->has(\Keel\Orm\EntityMap::class)) {
            throw new \InvalidArgumentException('The application has no #[Entity] classes.');
        }
        $pdo = $container->get(ConnectionManager::class)->writer();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $override = $container->configuration()['database.dialect'] ?? '';
        $schema = new \Keel\Orm\Schema($container->get(\Keel\Orm\EntityMap::class), \Keel\Orm\Dialect\Dialects::for(\is_string($driver) ? $driver : '', \is_string($override) ? $override : ''));
        $statements = $diff ? $schema->diff($pdo) : $schema->create();
        $sql = implode("\n", array_map(static fn (string $s): string => str_starts_with($s, '--') ? $s : "$s;", $statements));
        if ($diff && isset($options['write'])) {
            if ($statements === []) {
                return self::out('The database matches the entities.');
            }
            $files = (new CodeGenerator(\dirname($appFile)))->migration('orm sync', $options['dir'] ?? 'migrations');
            file_put_contents(\dirname($appFile) . '/' . $files[0], "-- Generated by `keel orm:diff`: review before applying.\n\n$sql\n");

            return self::out("Wrote {$files[0]}");
        }

        return self::out($statements === [] ? '-- The database matches the entities.' : $sql);
    }

    /** @param array<string, string> $options */
    private static function fixRun(string $appFile, string $env, array $options): int
    {
        $app = Application::boot($appFile, ['env' => $env]);
        $config = $app->container()->configuration();
        $sessions = $config['fix.sessions'] ?? [];
        if (!\is_array($sessions) || $sessions === []) {
            throw new \InvalidArgumentException('No FIX sessions configured. Add fix.sessions to config.php.');
        }
        $only = isset($options['session']) ? explode(',', $options['session']) : null;
        $settings = [];
        foreach ($sessions as $id => $session) {
            if (\is_array($session) && ($only === null || \in_array((string) $id, $only, true))) {
                $settings[] = \Keel\Fix\SessionSettings::fromConfig((string) $id, $session);
            }
        }
        $store = $config['fix.store'] ?? $app->definition->rootDir . '/var/fix';
        $host = $config['fix.listen_host'] ?? '127.0.0.1';
        $engine = new \Keel\Fix\Engine(
            $app->container(),
            $settings,
            \is_string($store) ? $store : null,
            $app->container()->get(\Psr\Log\LoggerInterface::class),
            \is_string($host) ? $host : '127.0.0.1',
        );
        \Keel\Runtime\WorkerPolicy::fromEnvironment()->listenForSignals($engine->stop(...));
        self::out('FIX engine running ' . \count($settings) . ' session(s) (Ctrl+C or SIGTERM logs out and stops).');
        $engine->run();
        $app->shutdown();

        return 0;
    }

    /** @param array<string, string> $options */
    private static function makeEndpoint(?string $name, array $options): int
    {
        if ($name === null || !isset($options['path'])) {
            throw new \InvalidArgumentException('Usage: keel make:endpoint Orders/CancelOrder --method=POST --path=/orders/{id}/cancel [--scope=orders:write]');
        }
        $files = (new CodeGenerator((string) getcwd()))->endpoint($name, $options['method'] ?? 'GET', $options['path'], $options['scope'] ?? null);

        return self::out("Created:\n  " . implode("\n  ", $files));
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

        return self::out("Created $name in $target.\n\n  cd $directory\n  composer install\n  keel serve      # or vendor/bin/keel serve\n\nThen open http://127.0.0.1:8000/hello/world");
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
