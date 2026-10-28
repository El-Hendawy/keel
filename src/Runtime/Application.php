<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Build\Builder;
use Keel\Container\Container;
use Keel\Http\HttpKernel;
use Keel\Observability\Health\WorkerState;
use Keel\Observability\Tracing\Tracer;
use Keel\Routing\Router;

/**
 * A booted application: the compiled container plus the HTTP kernel.
 *
 * Boot happens once per worker. In development and test the build is refreshed
 * automatically when sources change; in production a missing or stale build is an
 * error, because building belongs in your deploy pipeline (`keel build --env=prod`).
 */
final class Application
{
    /**
     * Builds already verified in this process. Generated classes cannot be reloaded once
     * declared, so re-checking freshness within one process can never change the outcome.
     *
     * @var array<string, true>
     */
    private static array $verified = [];

    private ?HttpKernel $http = null;

    private function __construct(
        public readonly AppDefinition $definition,
        public readonly Environment $environment,
        public readonly BuildManifest $manifest,
        private readonly Container $container,
    ) {
    }

    /**
     * @param string $appFile path to app.php, which returns an AppDefinition
     * @param array{env?: string, debug?: bool, config?: array<string, mixed>, testing?: bool, checks?: bool} $options
     */
    public static function boot(string $appFile, array $options = []): self
    {
        $definition = self::definition($appFile);
        $env = $options['env'] ?? (self::env('KEEL_ENV') ?? 'dev');
        $debug = $options['debug'] ?? filter_var(self::env('KEEL_DEBUG') ?? ($env === 'dev' ? '1' : '0'), \FILTER_VALIDATE_BOOL);
        $environment = new Environment($env, $debug, $definition->rootDir);

        $builder = new Builder($definition, $env);
        $manifest = BuildManifest::load($builder->directory());
        $production = $environment->isProduction();

        $verified = isset(self::$verified[$builder->directory()]);
        if ($manifest === null || (!$production && !$verified && $manifest->data['fingerprint'] !== $builder->fingerprint())) {
            if ($production) {
                throw new BootFailed("No build found in {$builder->directory()}. Run `keel build --env=$env` during deployment.");
            }
            $manifest = $builder->build();
        }
        self::$verified[$builder->directory()] = true;
        $manifest->loadClasses();

        $config = array_replace_recursive(
            $manifest->data['config'],
            $environment->isTest() ? ['log' => ['level' => 'warning']] : [],
            self::loadConfig($definition, $environment),
            $options['config'] ?? [],
        );

        $class = $manifest->data['container'];
        $container = new $class($config, $options['testing'] ?? $environment->isTest());
        $container->provide(Environment::class, $environment);
        $container->provide(BuildManifest::class, $manifest);

        $app = new self($definition, $environment, $manifest, $container);
        if ($production && ($options['checks'] ?? true)) {
            ProductionChecks::assert($app);
        }

        return $app;
    }

    public function http(): HttpKernel
    {
        return $this->http ??= new HttpKernel(
            $this->container,
            new Router($this->manifest->data['routes']),
            $this->manifest->data['endpoints'],
            $this->manifest->data['middleware'],
        );
    }

    public function container(): Container
    {
        return $this->container;
    }

    /** Runs after the response has been sent: exports telemetry, collects cycles. */
    public function afterRequest(): void
    {
        $this->container->get(Tracer::class)->flush();
    }

    /** Marks the worker as draining so readiness fails while in-flight work completes. */
    public function drain(): void
    {
        $this->container->get(WorkerState::class)->draining = true;
    }

    public function shutdown(): void
    {
        $this->afterRequest();
        $this->container->dispose();
    }

    private static function definition(string $appFile): AppDefinition
    {
        if (!is_file($appFile)) {
            throw new BootFailed("Application file $appFile not found.");
        }
        $definition = require $appFile;
        if (!$definition instanceof AppDefinition) {
            throw new BootFailed("$appFile must return an " . AppDefinition::class . '.');
        }

        return $definition;
    }

    /** @return array<string, mixed> */
    private static function loadConfig(AppDefinition $definition, Environment $environment): array
    {
        $file = $definition->configFile();
        if ($file === null) {
            return [];
        }
        $load = static fn (string $file, Environment $env): mixed => require $file;
        $config = $load($file, $environment);
        if (!\is_array($config)) {
            throw new BootFailed("$file must return an array.");
        }

        /** @var array<string, mixed> */
        return $config;
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
