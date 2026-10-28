<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Psr\Http\Server\MiddlewareInterface;

/**
 * The application's shape, declared in app.php and read by the compiler.
 *
 *     return AppDefinition::create(__DIR__, 'shop')
 *         ->scan('src')
 *         ->config('config.php')
 *         ->bind(PaymentGateway::class, StripeGateway::class)
 *         ->withDatabase();
 *
 * Everything here is resolved at build time. Runtime values (secrets, DSNs) belong in
 * the config file, which is read at boot and may use environment variables.
 */
final class AppDefinition
{
    /** @var list<string> */
    private array $scan = [];

    /** @var array<string, string> */
    private array $bindings = [];

    /** @var array<string, array<string, string>> env => bindings */
    private array $envBindings = [];

    /** @var list<class-string> */
    private array $register = [];

    /** @var list<class-string<MiddlewareInterface>> */
    private array $middleware = [];

    /** @var list<class-string<MiddlewareInterface>> */
    private array $withoutMiddleware = [];

    /** @var list<string> */
    private array $trustedProxies = [];

    private ?string $configFile = null;

    private string $buildDir;

    private bool $operationalEndpoints = true;

    private string $version = '1.0.0';

    private function __construct(public readonly string $rootDir, public readonly string $name)
    {
        $this->buildDir = $rootDir . '/var/build';
    }

    public static function create(string $rootDir, string $name = 'app'): self
    {
        $real = realpath($rootDir);
        if ($real === false) {
            throw new \InvalidArgumentException("Application root \"$rootDir\" does not exist.");
        }

        return new self($real, $name);
    }

    /** Directories (relative to the root) whose classes are compiled into the application. */
    public function scan(string ...$directories): self
    {
        foreach ($directories as $directory) {
            $this->scan[] = $this->path($directory);
        }

        return $this;
    }

    /**
     * Binds an interface (or abstract class) to the implementation that satisfies it.
     *
     * @param class-string $abstract
     * @param class-string $concrete
     */
    public function bind(string $abstract, string $concrete): self
    {
        $this->bindings[$abstract] = $concrete;

        return $this;
    }

    /**
     * Binds only in one environment, for example a fake payment gateway in `test`.
     *
     * @param class-string $abstract
     * @param class-string $concrete
     */
    public function bindIn(string $environment, string $abstract, string $concrete): self
    {
        $this->envBindings[$environment][$abstract] = $concrete;

        return $this;
    }

    /**
     * Registers classes outside the scanned directories (framework or vendor classes
     * carrying a lifetime attribute).
     *
     * @param class-string ...$classes
     */
    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->register[] = $class;
        }

        return $this;
    }

    /**
     * Appends middleware after the framework defaults.
     *
     * @param class-string<MiddlewareInterface> ...$middleware
     */
    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $class) {
            $this->middleware[] = $class;
        }

        return $this;
    }

    /**
     * Removes a default middleware. Removing security middleware is a deliberate, visible act.
     *
     * @param class-string<MiddlewareInterface> ...$middleware
     */
    public function withoutMiddleware(string ...$middleware): self
    {
        foreach ($middleware as $class) {
            $this->withoutMiddleware[] = $class;
        }

        return $this;
    }

    /**
     * Trusts forwarding headers (X-Forwarded-For) from these proxy addresses or CIDR ranges.
     * Unsafe by name: trusting a range you do not control lets clients spoof their address.
     */
    public function trustProxy(string ...$cidrs): self
    {
        foreach ($cidrs as $cidr) {
            $this->trustedProxies[] = $cidr;
        }

        return $this;
    }

    public function config(string $file): self
    {
        $this->configFile = $this->path($file);

        return $this;
    }

    public function buildDir(string $directory): self
    {
        $this->buildDir = $this->path($directory);

        return $this;
    }

    /** Registers the database layer: connection manager, scoped Database, query log and health check. */
    public function withDatabase(): self
    {
        return $this->register(
            \Keel\Database\ConnectionManager::class,
            \Keel\Database\Database::class,
            \Keel\Database\QueryLog::class,
            \Keel\Database\DatabaseHealthCheck::class,
        );
    }

    /** Uses Redis for rate limits and idempotency keys, so limits hold across nodes. */
    public function withRedisStores(): self
    {
        return $this
            ->bind(\Keel\Http\RateLimit\RateLimitStore::class, \Keel\Http\RateLimit\RedisRateLimitStore::class)
            ->bind(\Keel\Http\Idempotency\IdempotencyStore::class, \Keel\Http\Idempotency\RedisIdempotencyStore::class);
    }

    /** Exports traces to an OpenTelemetry collector over OTLP/HTTP. */
    public function withOtlpTracing(): self
    {
        return $this->bind(\Keel\Observability\Tracing\SpanExporter::class, \Keel\Observability\Tracing\OtlpHttpExporter::class);
    }

    public function withoutOperationalEndpoints(): self
    {
        $this->operationalEndpoints = false;

        return $this;
    }

    public function version(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    /** @return list<string> */
    public function scannedDirectories(): array
    {
        return $this->scan;
    }

    /** @return array<string, string> */
    public function bindingsFor(string $environment): array
    {
        return ($this->envBindings[$environment] ?? []) + $this->bindings;
    }

    /** @return list<class-string> */
    public function registered(): array
    {
        return $this->register;
    }

    /** @return list<class-string<MiddlewareInterface>> */
    public function extraMiddleware(): array
    {
        return $this->middleware;
    }

    /** @return list<class-string<MiddlewareInterface>> */
    public function removedMiddleware(): array
    {
        return $this->withoutMiddleware;
    }

    /** @return list<string> */
    public function trustedProxies(): array
    {
        return $this->trustedProxies;
    }

    public function configFile(): ?string
    {
        return $this->configFile;
    }

    public function buildDirectory(): string
    {
        return $this->buildDir;
    }

    public function hasOperationalEndpoints(): bool
    {
        return $this->operationalEndpoints;
    }

    public function appVersion(): string
    {
        return $this->version;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->rootDir . '/' . $path;
    }
}
