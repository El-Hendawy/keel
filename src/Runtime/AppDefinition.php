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

    private bool $database = false;

    private bool $auth = false;

    private bool $tokenBinding = true;

    private bool $queue = false;

    private bool $messageEncryption = false;

    /** @var 'json'|'toon'|null the default format when TOON is enabled */
    private ?string $toon = null;

    /** @var list<string> framework migration directories enabled by features */
    private array $migrations = [];

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
        $this->database = true;

        return $this->register(
            \Keel\Database\ConnectionManager::class,
            \Keel\Database\Database::class,
            \Keel\Database\QueryLog::class,
            \Keel\Database\DatabaseHealthCheck::class,
        );
    }

    /** Uses Redis for rate limits, idempotency keys, locks and the cache, so they hold across nodes. */
    public function withRedisStores(): self
    {
        return $this
            ->bind(\Keel\Http\RateLimit\RateLimitStore::class, \Keel\Http\RateLimit\RedisRateLimitStore::class)
            ->bind(\Keel\Http\Idempotency\IdempotencyStore::class, \Keel\Http\Idempotency\RedisIdempotencyStore::class)
            ->bind(\Keel\Locks\LockStore::class, \Keel\Locks\RedisLockStore::class)
            ->bind(\Keel\Cache\Cache::class, \Keel\Cache\RedisCache::class);
    }

    /**
     * Background jobs in the application database (`keel_jobs`), processed by
     * `keel queue:work`. Jobs pushed inside a transaction commit with it: a transactional
     * outbox. Also stores scheduler locks in the database unless Redis stores are used.
     * Requires ->withDatabase().
     */
    public function withQueue(): self
    {
        $this->queue = true;
        $this->migrations[] = \dirname(__DIR__) . '/Queue/migrations';
        $this->bindings[\Keel\Queue\Queue::class] = \Keel\Queue\DatabaseQueue::class;
        $this->bindings[\Keel\Locks\LockStore::class] ??= \Keel\Locks\DatabaseLockStore::class;

        return $this;
    }

    /**
     * Signed outgoing webhooks (Standard Webhooks), delivered by the queue with retries
     * and protected against requests to private networks. Use with ->withQueue().
     */
    public function withWebhooks(): self
    {
        return $this->register(\Keel\Webhooks\Webhooks::class, \Keel\Webhooks\DeliverWebhook::class);
    }

    /** Exports traces to an OpenTelemetry collector over OTLP/HTTP. */
    public function withOtlpTracing(): self
    {
        return $this->bind(\Keel\Observability\Tracing\SpanExporter::class, \Keel\Observability\Tracing\OtlpHttpExporter::class);
    }

    /**
     * Authentication and authorization, deny by default: every endpoint requires a
     * principal unless marked #[Anonymous]. Bearer tokens (`keel_...`, stored hashed in
     * `keel_tokens` when the database is enabled) and JWTs are accepted, and tokens are
     * bound to the client fingerprint they were issued to.
     */
    public function withAuth(): self
    {
        $this->auth = true;
        $this->migrations[] = \dirname(__DIR__) . '/Auth/migrations';

        return $this->register(
            \Keel\Auth\AuthContext::class,
            \Keel\Auth\AuthenticationMiddleware::class,
            \Keel\Auth\BearerAuthenticator::class,
            \Keel\Auth\Token\Tokens::class,
            \Keel\Auth\Jwt\JwtTokens::class,
            \Keel\Auth\Fingerprint\ClientFingerprint::class,
            \Keel\Auth\Dpop\DpopVerifier::class,
            \Keel\Auth\Oidc\OidcProvider::class,
            \Keel\Auth\Oidc\OidcClient::class,
        );
    }

    /**
     * Unsafe by name: accepts bound tokens from any client. A stolen token then works
     * from anywhere until it expires. Prefer `auth.fingerprint.mode = report` to observe
     * before enforcing.
     */
    public function unsafeWithoutTokenBinding(): self
    {
        $this->tokenBinding = false;

        return $this;
    }

    /**
     * Lets clients use TOON (Token-Oriented Object Notation) instead of JSON: `Accept:
     * text/toon` for responses, `Content-Type: text/toon` for bodies. With $default the API
     * answers in TOON unless the client asks for JSON (`http.default_format` at runtime).
     */
    public function withToon(bool $default = false): self
    {
        $this->toon = $default ? 'toon' : 'json';

        return $this;
    }

    /**
     * Microservices: `Services` (calls to other services with credentials, trace, request id and
     * deadline propagated), `DeadlineMiddleware`, `IntegrationEvents` (CloudEvents through the
     * outbox, delivered by the queue) and, with ->withDatabase(), `Inbox` (`keel_inbox`) to handle
     * each incoming event once. Configure under `service`, `services` and `events`.
     */
    public function withServices(): self
    {
        if ($this->database) {
            $this->migrations[] = \dirname(__DIR__) . '/Service/migrations';
            $this->register(\Keel\Service\Inbox::class);
        }

        return $this
            ->register(\Keel\Service\Services::class, \Keel\Service\ServiceTokens::class, \Keel\Service\IntegrationEvents::class, \Keel\Service\DeliverIntegrationEvent::class)
            ->middleware(\Keel\Service\DeadlineMiddleware::class);
    }

    /**
     * Non-relational databases: `NoSqlConnections` (MongoDB, DynamoDB, Elasticsearch/OpenSearch,
     * CouchDB, Neo4j, InfluxDB, configured under `nosql`) and `DocumentStore` bound to the store
     * named by `nosql.store`. The production checks refuse the in-memory store.
     */
    public function withDocumentStore(): self
    {
        $this->bindings[\Keel\NoSql\DocumentStore::class] = \Keel\NoSql\ConfiguredDocumentStore::class;

        return $this->register(\Keel\NoSql\NoSqlConnections::class);
    }

    /**
     * A double-entry, hash-chained ledger in the application database (`Ledger`), with its
     * tables in the framework migrations. Requires ->withDatabase().
     */
    public function withLedger(): self
    {
        $this->migrations[] = \dirname(__DIR__) . '/Ledger/migrations';

        return $this->register(\Keel\Ledger\Ledger::class);
    }

    /**
     * End-to-end message encryption: JWE request bodies (ECDH-ES, A256GCM) are decrypted before
     * routing, and responses are encrypted to the key a client sends in `Keel-E2E-Key`. Server
     * keys come from `crypto.e2e.keys`; `crypto.e2e.required` lists paths that refuse plaintext.
     */
    public function withMessageEncryption(): self
    {
        $this->messageEncryption = true;

        return $this->register(\Keel\Crypto\MessageEncryptionMiddleware::class);
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

    public function hasDatabase(): bool
    {
        return $this->database;
    }

    public function hasAuth(): bool
    {
        return $this->auth;
    }

    /** @return 'json'|'toon'|null null when TOON is not enabled */
    public function toonDefault(): ?string
    {
        return $this->toon;
    }

    public function hasQueue(): bool
    {
        return $this->queue;
    }

    public function hasMessageEncryption(): bool
    {
        return $this->messageEncryption;
    }

    public function hasTokenBinding(): bool
    {
        return $this->tokenBinding;
    }

    /** @return list<string> framework migration directories, applied before the application's own */
    public function frameworkMigrations(): array
    {
        return array_values(array_unique($this->migrations));
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
