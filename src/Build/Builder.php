<?php

declare(strict_types=1);

namespace Keel\Build;

use Keel\Build\Http\OpenApiGenerator;
use Keel\Build\Http\RouteCompiler;
use Keel\Build\Service\Argument;
use Keel\Build\Service\ContainerCompiler;
use Keel\Build\Service\ServiceAnalyzer;
use Keel\Build\Service\ServiceDefinition;
use Keel\Build\Types\HydratorCompiler;
use Keel\Build\Types\SerializerCompiler;
use Keel\Build\Types\TypeAnalyzer;
use Keel\Container\Lifetime;
use Keel\Container\Scope;
use Keel\Events\Listener;
use Keel\Keel;
use Keel\Module\Module;
use Keel\Module\ModuleChecker;
use Keel\Runtime\AppDefinition;
use Keel\Runtime\BuildManifest;
use Keel\Runtime\Environment;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

/**
 * The compiler. Reads the application once, validates the whole graph, and writes
 * plain PHP that the runtime loads without reflection:
 *
 *   Container.php    services, lifetimes and factories
 *   Endpoints.php    one dispatch method per route
 *   Hydrators.php    typed input parsing and validation
 *   Serializers.php  typed output mapping
 *   manifest.php     what the runtime needs to boot: the route table (static map +
 *                    combined regexes), listener map, middleware and bindings
 *   openapi.json     the API contract
 *   metadata.json    everything above, for tools and coding agents (`keel inspect`)
 */
final class Builder
{
    /** Framework services that every application gets. */
    public const array DEFAULT_SERVICES = [
        \Keel\Http\RequestContext::class,
        \Keel\Http\ErrorRenderer::class,
        \Keel\Http\TrustedProxies::class,
        \Keel\Support\SystemClock::class,
        \Keel\Support\SecureRandom::class,
        \Keel\Support\UuidV7Generator::class,
        \Keel\Observability\Tracing\Tracer::class,
        \Keel\Observability\Tracing\NullSpanExporter::class,
        \Keel\Observability\Logging\JsonLogger::class,
        \Keel\Observability\Metrics\Metrics::class,
        \Keel\Observability\Health\WorkerState::class,
        \Keel\Http\Client\CurlHttpClient::class,
        \Keel\Http\Client\ResilientHttpClient::class,
        \Keel\Http\RateLimit\InMemoryRateLimitStore::class,
        \Keel\Http\Idempotency\InMemoryIdempotencyStore::class,
        \Keel\Events\EventDispatcher::class,
        \Keel\Queue\JobRunner::class,
    ];

    public const array DEFAULT_BINDINGS = [
        \Psr\Clock\ClockInterface::class => \Keel\Support\SystemClock::class,
        \Keel\Support\Random::class => \Keel\Support\SecureRandom::class,
        \Keel\Support\IdGenerator::class => \Keel\Support\UuidV7Generator::class,
        \Psr\Log\LoggerInterface::class => \Keel\Observability\Logging\JsonLogger::class,
        \Keel\Observability\Tracing\SpanExporter::class => \Keel\Observability\Tracing\NullSpanExporter::class,
        \Psr\Http\Client\ClientInterface::class => \Keel\Http\Client\ResilientHttpClient::class,
        \Keel\Http\RateLimit\RateLimitStore::class => \Keel\Http\RateLimit\InMemoryRateLimitStore::class,
        \Keel\Http\Idempotency\IdempotencyStore::class => \Keel\Http\Idempotency\InMemoryIdempotencyStore::class,
        \Psr\EventDispatcher\EventDispatcherInterface::class => \Keel\Events\EventDispatcher::class,
        \Keel\Queue\Queue::class => \Keel\Queue\SyncQueue::class,
        \Keel\Locks\LockStore::class => \Keel\Locks\InMemoryLockStore::class,
        \Keel\Cache\Cache::class => \Keel\Cache\InMemoryCache::class,
    ];

    /** In tests, time, randomness, ids and outbound HTTP are fakes by contract. */
    public const array TEST_BINDINGS = [
        \Psr\Clock\ClockInterface::class => \Keel\Testing\FrozenClock::class,
        \Keel\Support\Random::class => \Keel\Testing\SeededRandom::class,
        \Keel\Support\IdGenerator::class => \Keel\Testing\SequentialIdGenerator::class,
        \Psr\Http\Client\ClientInterface::class => \Keel\Testing\FakeHttpClient::class,
    ];

    public const array DEFAULT_MIDDLEWARE = [
        \Keel\Http\Middleware\RequestContextMiddleware::class,
        \Keel\Http\Middleware\TelemetryMiddleware::class,
        \Keel\Http\Middleware\LoadSheddingMiddleware::class,
        \Keel\Http\Middleware\SecurityHeadersMiddleware::class,
        \Keel\Http\Middleware\RateLimitMiddleware::class,
        \Keel\Http\Middleware\BodyLimitMiddleware::class,
        \Keel\Http\Middleware\IdempotencyMiddleware::class,
    ];

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, ServiceDefinition> */
    private array $services = [];

    public function __construct(private readonly AppDefinition $app, private readonly string $environment)
    {
    }

    public function directory(): string
    {
        return $this->app->buildDirectory() . '/' . $this->environment;
    }

    /** A cheap hash of everything that affects the build; used to rebuild automatically in development. */
    public function fingerprint(): string
    {
        $parts = [Keel::VERSION, $this->environment, (string) filemtime(__FILE__)];
        foreach ([...$this->app->scannedDirectories(), \dirname(__DIR__)] as $directory) {
            foreach (ClassFinder::phpFiles($directory) as $file) {
                $parts[] = $file . ':' . (int) filemtime($file);
            }
        }
        $parts[] = serialize([
            $this->app->bindingsFor($this->environment),
            $this->app->registered(),
            $this->app->extraMiddleware(),
            $this->app->removedMiddleware(),
            $this->app->trustedProxies(),
            $this->app->hasOperationalEndpoints(),
            $this->app->hasAuth(),
            $this->app->hasTokenBinding(),
            $this->app->frameworkMigrations(),
            $this->app->hasQueue(),
            $this->app->toonDefault(),
        ]);

        return hash('xxh128', implode("\n", $parts));
    }

    public function build(): BuildManifest
    {
        $started = hrtime(true);
        $directory = $this->directory();
        $fingerprint = $this->fingerprint();
        $namespace = 'Keel\\Generated\\B' . substr(hash('xxh128', $directory . $fingerprint), 0, 12);

        $finder = new ClassFinder();
        $sources = $this->app->scannedDirectories();
        if ($this->app->hasOperationalEndpoints()) {
            $sources[] = \dirname(__DIR__) . '/Http/Endpoint';
        }
        $classes = $finder->find($sources);
        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
                $this->errors[] = "$class was found in the source tree but cannot be autoloaded. Check composer.json autoload.";
            }
        }

        if ($this->app->hasQueue() && !$this->app->hasDatabase()) {
            $this->errors[] = '->withQueue() stores jobs in the database. Add ->withDatabase() in app.php.';
        }

        // 1. Services
        $analyzer = new ServiceAnalyzer();
        $this->synthetic(ServerRequestInterface::class, Lifetime::Scoped);
        $this->synthetic(Scope::class, Lifetime::Scoped);
        $this->synthetic(Environment::class, Lifetime::Singleton);
        $this->synthetic(BuildManifest::class, Lifetime::Singleton);

        $bindings = $this->app->bindingsFor($this->environment)
            + ($this->environment === 'test' ? self::TEST_BINDINGS : [])
            + $this->featureBindings()
            + self::DEFAULT_BINDINGS;
        $middleware = array_values(array_filter(
            [...$this->defaultMiddleware(), ...$this->app->extraMiddleware()],
            fn (string $class): bool => !\in_array($class, $this->app->removedMiddleware(), true),
        ));

        $entities = array_values(array_filter($classes, static fn (string $c): bool => class_exists($c) && (new ReflectionClass($c))->getAttributes(\Keel\Orm\Attribute\Entity::class) !== []));
        if ($entities !== [] && !$this->app->hasDatabase()) {
            $this->errors[] = 'The application has #[Entity] classes. Add ->withDatabase() in app.php.';
        }
        $fixMessages = array_values(array_filter($classes, static fn (string $c): bool => class_exists($c) && (new ReflectionClass($c))->getAttributes(\Keel\Fix\Attribute\FixMessage::class) !== []));
        $candidates = array_unique([
            ...($fixMessages === [] ? [] : [\Keel\Fix\FixGateway::class]),
            ...($entities === [] ? [] : [\Keel\Orm\Orm::class, \Keel\Orm\EntityMap::class]),
            \Keel\Realtime\Hub::class,
            ...self::DEFAULT_SERVICES,
            ...$middleware,
            ...array_values($bindings),
            ...$this->app->registered(),
            ...$classes,
        ]);

        $modules = [];
        $listenerClasses = [];
        $handlerClasses = [];
        $fixHandlers = [];
        $scheduled = [];
        $routeCandidates = [];
        foreach ($candidates as $class) {
            if (!class_exists($class)) {
                if (\in_array($class, $classes, true)) {
                    continue;
                }
                $this->errors[] = "$class is referenced in app.php or the framework defaults but does not exist.";
                continue;
            }
            /** @var class-string $class */
            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes(Module::class) !== []) {
                $modules[] = $class;
            }
            $lifetime = ServiceAnalyzer::declaredLifetime($reflection);
            $isListener = $reflection->getAttributes(Listener::class) !== [];
            $isEndpoint = $reflection->getAttributes(\Keel\Routing\Attribute\Route::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
            $isHandler = $reflection->getAttributes(\Keel\Queue\Handler::class) !== [];
            $isFixHandler = $reflection->getAttributes(\Keel\Fix\Attribute\FixHandler::class) !== [];
            if ($isFixHandler) {
                $fixHandlers[] = $class;
            }
            $schedule = $reflection->getAttributes(\Keel\Scheduling\Schedule::class)[0] ?? null;
            if ($isListener) {
                $listenerClasses[] = $class;
            }
            if ($isHandler) {
                $handlerClasses[] = $class;
            }
            if ($schedule !== null) {
                $scheduled[$class] = $schedule->newInstance()->cron;
            }
            $isSocketHandler = $reflection->implementsInterface(\Keel\Realtime\WebSocketHandler::class) && $reflection->isInstantiable();
            $isListener = $isListener || $isHandler || $isFixHandler || $isSocketHandler || $schedule !== null;
            if ($isEndpoint) {
                $routeCandidates[] = $class;
            }
            // Endpoints and listeners are Transient unless they say otherwise.
            $lifetime ??= ($isEndpoint || $isListener) ? Lifetime::Transient : null;
            if ($lifetime === null) {
                if (!\in_array($class, $classes, true)) {
                    $this->errors[] = "$class is registered or bound in app.php but has no #[Singleton], #[Scoped] or #[Transient] attribute.";
                }
                continue;
            }
            $definition = $analyzer->fromClass($reflection, $lifetime);
            if ($definition !== null) {
                $this->services[$definition->id] = $definition;
            }
            foreach ($analyzer->factoriesOf($reflection) as $factory) {
                if (isset($this->services[$factory->id])) {
                    $this->errors[] = "{$factory->id} is provided by both {$factory->origin} and {$this->services[$factory->id]->origin}.";
                }
                $this->services[$factory->id] = $factory;
            }
        }
        // A dependency whose class declares its own lifetime is a service wherever it lives
        // (framework, vendor or app). The class states its lifetime, so this is not guesswork.
        // Endpoint parameters count as dependencies too.
        foreach ($routeCandidates as $class) {
            if (!method_exists($class, '__invoke')) {
                continue;
            }
            foreach ((new \ReflectionMethod($class, '__invoke'))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $name = $bindings[$type->getName()] ?? $type->getName();
                if (isset($this->services[$name]) || !class_exists($name)) {
                    continue;
                }
                $reflection = new ReflectionClass($name);
                $lifetime = ServiceAnalyzer::declaredLifetime($reflection);
                if ($lifetime !== null && ($dependency = $analyzer->fromClass($reflection, $lifetime)) !== null) {
                    $this->services[$name] = $dependency;
                }
            }
        }
        do {
            $added = false;
            foreach ($this->services as $definition) {
                foreach ($definition->arguments as $argument) {
                    $type = $bindings[$argument->type] ?? $argument->type;
                    if ($argument->kind !== Argument::SERVICE || isset($this->services[$type]) || !class_exists($type)) {
                        continue;
                    }
                    $reflection = new ReflectionClass($type);
                    $lifetime = ServiceAnalyzer::declaredLifetime($reflection);
                    if ($lifetime !== null && ($dependency = $analyzer->fromClass($reflection, $lifetime)) !== null) {
                        $this->services[$type] = $dependency;
                        $added = true;
                    }
                }
            }
        } while ($added);
        $this->errors = [...$this->errors, ...$analyzer->errors];

        $aliases = [];
        foreach ($bindings as $abstract => $concrete) {
            if ($abstract !== $concrete) {
                $aliases[$abstract] = $concrete;
            }
        }

        $containerCompiler = new ContainerCompiler($this->services, $aliases);
        $containerCompiler->validate();
        $this->errors = [...$this->errors, ...$containerCompiler->errors];

        // 2. Routes, inputs and outputs
        $types = new TypeAnalyzer();
        $hydrators = new HydratorCompiler($types);
        $serializers = new SerializerCompiler($types);
        $isService = fn (string $type): bool => isset($this->services[$aliases[$type] ?? $type]);
        $routes = new RouteCompiler($types, $hydrators, $serializers, $isService, $this->app->hasAuth());
        foreach ($routeCandidates as $class) {
            $routes->add($class);
        }
        [$table, $conflicts] = $routes->table();

        // 3. Listeners, job handlers and scheduled tasks
        $listeners = [];
        $asyncListeners = [];
        $jobs = [];
        $job = function (string $type, string $payload, string $handler, \Keel\Queue\Handler $options) use (&$jobs, $hydrators, $serializers): void {
            if (!class_exists($payload) || (new ReflectionClass($payload))->isAbstract()) {
                $this->errors[] = "$handler handles $payload, which must be a concrete, typed class to be queued.";

                return;
            }
            /** @var class-string $payload */
            $jobs[$type] = [
                'class' => $payload,
                'handler' => $handler,
                'hydrator' => $hydrators->entry($payload),
                'serializer' => $serializers->entry($payload),
                'queue' => $options->queue,
                'maxAttempts' => max(1, $options->maxAttempts),
                'backoff' => max(1, $options->backoffSeconds),
            ];
        };
        foreach ([...$listenerClasses, ...$handlerClasses] as $class) {
            $reflection = new ReflectionClass($class);
            $invoke = $reflection->hasMethod('__invoke') ? new \ReflectionMethod($class, '__invoke') : null;
            $type = $invoke?->getParameters()[0]?->getType();
            if ($invoke === null || $invoke->getNumberOfParameters() !== 1 || !$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                $this->errors[] = "Listener or handler $class must define __invoke(SomeType \$value) with exactly one class-typed parameter.";
                continue;
            }
            $handler = ($reflection->getAttributes(\Keel\Queue\Handler::class)[0] ?? null)?->newInstance();
            if ($handler !== null) {
                if (isset($jobs[$type->getName()])) {
                    $this->errors[] = "Job {$type->getName()} has two handlers: {$jobs[$type->getName()]['handler']} and $class. A job has exactly one.";
                }
                $job($type->getName(), $type->getName(), $class, $handler);
                continue;
            }
            $listener = ($reflection->getAttributes(Listener::class)[0] ?? null)?->newInstance();
            if ($listener !== null && $listener->async) {
                $job('listener:' . $class, $type->getName(), $class, new \Keel\Queue\Handler());
                $asyncListeners[$type->getName()][] = 'listener:' . $class;
                continue;
            }
            $listeners[$type->getName()][] = $class;
        }
        $schedule = [];
        foreach ($scheduled as $class => $cron) {
            try {
                \Keel\Scheduling\CronExpression::parse($cron);
            } catch (\InvalidArgumentException $e) {
                $this->errors[] = "#[Schedule] on $class: {$e->getMessage()}";
                continue;
            }
            $invoke = (new ReflectionClass($class))->hasMethod('__invoke') ? new \ReflectionMethod($class, '__invoke') : null;
            if ($invoke === null || $invoke->getNumberOfRequiredParameters() !== 0) {
                $this->errors[] = "Scheduled task $class must define __invoke() without parameters; take dependencies in the constructor.";
                continue;
            }
            $schedule[] = ['class' => $class, 'cron' => $cron];
        }

        $orm = new \Keel\Build\Orm\OrmCompiler();
        foreach ($entities as $class) {
            $orm->entity($class);
        }
        $orm->finish();
        $this->errors = [...$this->errors, ...$orm->errors];

        $fix = new \Keel\Build\Fix\FixCompiler();
        foreach ($fixMessages as $class) {
            $fix->message($class);
        }
        foreach ($fixHandlers as $class) {
            $fix->handler($class);
        }
        $this->errors = [...$this->errors, ...$fix->errors];

        $hydratorCode = $hydrators->compile($namespace, 'Hydrators');
        $serializerCode = $serializers->compile($namespace, 'Serializers');
        $this->errors = [...$this->errors, ...$routes->errors, ...$conflicts, ...$types->errors];

        // 4. Module boundaries
        $moduleChecker = new ModuleChecker($modules);
        $signatureReferences = [];
        foreach ($this->services as $definition) {
            if ($definition->class !== null) {
                foreach ($definition->arguments as $argument) {
                    if ($argument->kind === Argument::SERVICE) {
                        $signatureReferences[$definition->class][] = $argument->type;
                    }
                }
            }
        }
        $moduleChecker->check($classes, $finder, $signatureReferences);
        $this->errors = [...$this->errors, ...$moduleChecker->errors];
        $routeDefinitions = $routes->routes();
        foreach ($routeDefinitions as $route) {
            $route->module = $moduleChecker->moduleOf($route->class);
        }

        $this->errors = array_values(array_unique($this->errors));
        if ($this->errors !== []) {
            throw new BuildFailed($this->errors);
        }

        // 5. Write
        $files = [
            'Container.php' => $containerCompiler->compile($namespace, 'Container'),
            'Hydrators.php' => $hydratorCode,
            'Serializers.php' => $serializerCode,
            'Endpoints.php' => $routes->compileEndpoints($namespace, 'Endpoints'),
        ];
        if (!$fix->isEmpty()) {
            $files['Fix.php'] = $fix->compileClass($namespace, 'Fix');
        }
        if (!$orm->isEmpty()) {
            $files['Entities.php'] = $orm->compileClass($namespace, 'Entities');
        }
        foreach ($files as $name => $code) {
            Php::write("$directory/$name", $code);
        }

        $openApi = (new OpenApiGenerator($types, $this->app->toonDefault() !== null))->generate($routeDefinitions, $this->app->name, $this->app->appVersion());
        Php::write("$directory/openapi.json", json_encode($openApi, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");

        $manifest = [
            'version' => Keel::VERSION,
            'fingerprint' => $fingerprint,
            'env' => $this->environment,
            'builtAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'container' => $namespace . '\\Container',
            'endpoints' => $namespace . '\\Endpoints',
            'files' => array_keys($files),
            'routes' => $table,
            'listeners' => $listeners,
            'middleware' => $middleware,
            'bindings' => $aliases,
            'config' => [
                'http' => ['trusted_proxies' => $this->app->trustedProxies()]
                    + ($this->app->toonDefault() === null ? [] : ['default_format' => $this->app->toonDefault()]),
                'app' => ['name' => $this->app->name],
                'auth' => ['binding' => $this->app->hasTokenBinding()],
            ],
            'migrations' => $this->app->frameworkMigrations(),
            'hydrators' => $namespace . '\\Hydrators',
            'serializers' => $namespace . '\\Serializers',
            'jobs' => $jobs,
            'asyncListeners' => $asyncListeners,
            'schedule' => $schedule,
        ] + ($fix->isEmpty() ? [] : ['fix' => $fix->manifest($namespace . '\\Fix')])
          + ($orm->isEmpty() ? [] : ['orm' => $namespace . '\\Entities']);
        Php::write("$directory/manifest.php", "<?php\n\n// Generated by Keel. Do not edit.\n\nreturn " . Php::export($manifest) . ";\n");

        $metadata = $this->metadata($routeDefinitions, $containerCompiler, $moduleChecker, $listeners, $middleware, $aliases);
        $metadata['jobs'] = array_map(static fn (array $j): array => ['payload' => $j['class'], 'handler' => $j['handler'], 'queue' => $j['queue'], 'maxAttempts' => $j['maxAttempts']], $jobs);
        $metadata['schedule'] = $schedule;
        $metadata['buildMs'] = round((hrtime(true) - $started) / 1e6, 1);
        Php::write("$directory/metadata.json", json_encode($metadata, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");

        $loaded = BuildManifest::load($directory);
        \assert($loaded !== null);

        return $loaded;
    }

    /** @return list<class-string<\Psr\Http\Server\MiddlewareInterface>> */
    private function defaultMiddleware(): array
    {
        $middleware = [];
        foreach (self::DEFAULT_MIDDLEWARE as $class) {
            if ($class === \Keel\Http\Middleware\TelemetryMiddleware::class && $this->app->toonDefault() !== null) {
                // Outside everything that can produce a response, so errors are negotiated too.
                $middleware[] = \Keel\Http\Middleware\ContentNegotiationMiddleware::class;
            }
            if ($class === \Keel\Http\Middleware\IdempotencyMiddleware::class && $this->app->hasAuth()) {
                // Authenticate before idempotency, so a replay is only served to the same caller.
                $middleware[] = \Keel\Auth\AuthenticationMiddleware::class;
            }
            $middleware[] = $class;
            if ($class === \Keel\Http\Middleware\RequestContextMiddleware::class && $this->app->hasMessageEncryption()) {
                // Outside content negotiation and everything that reads bodies.
                $middleware[] = \Keel\Crypto\MessageEncryptionMiddleware::class;
            }
        }

        return $middleware;
    }

    /** @return array<string, string> */
    private function featureBindings(): array
    {
        $bindings = [];
        if ($this->app->hasAuth()) {
            $bindings[\Keel\Auth\Authenticator::class] = \Keel\Auth\BearerAuthenticator::class;
            $bindings[\Keel\Auth\Token\TokenStore::class] = $this->app->hasDatabase()
                ? \Keel\Auth\Token\DatabaseTokenStore::class
                : \Keel\Auth\Token\InMemoryTokenStore::class;
        }

        return $bindings;
    }

    private function synthetic(string $id, Lifetime $lifetime): void
    {
        $this->services[$id] = ServiceDefinition::synthetic($id, $lifetime);
    }

    /**
     * @param list<Http\RouteDefinition> $routes
     * @param array<string, list<class-string>> $listeners
     * @param list<string> $middleware
     * @param array<string, string> $aliases
     * @return array<string, mixed>
     */
    private function metadata(array $routes, ContainerCompiler $container, ModuleChecker $modules, array $listeners, array $middleware, array $aliases): array
    {
        $graph = $container->graph();
        $services = [];
        $config = [];
        foreach ($this->services as $id => $definition) {
            $services[$id] = [
                'lifetime' => $definition->lifetime->value,
                'class' => $definition->class,
                'factory' => $definition->factory === null ? null : implode('::', $definition->factory),
                'synthetic' => $definition->synthetic,
                'dependsOn' => $graph[$id] ?? [],
                'module' => $modules->moduleOf($id),
            ];
            foreach ($definition->arguments as $argument) {
                if ($argument->kind === Argument::CONFIG) {
                    $config[(string) $argument->configKey] = [
                        'type' => $argument->type,
                        'required' => !$argument->hasDefault,
                        'default' => $argument->hasDefault ? $argument->default : null,
                    ];
                }
            }
        }
        ksort($services);
        ksort($config);

        return [
            'keel' => Keel::VERSION,
            'app' => $this->app->name,
            'environment' => $this->environment,
            'routes' => array_map(static fn (Http\RouteDefinition $r): array => [
                'method' => $r->method,
                'path' => $r->path,
                'name' => $r->name,
                'endpoint' => $r->class,
                'module' => $r->module,
                'input' => $r->input,
                'inputSource' => $r->inputSource,
                'output' => $r->output,
                'status' => $r->status,
                'summary' => $r->summary,
                'auth' => $r->auth,
            ], $routes),
            'services' => $services,
            'bindings' => $aliases,
            'modules' => $modules->modules(),
            'listeners' => $listeners,
            'middleware' => $middleware,
            'config' => $config,
        ];
    }
}
