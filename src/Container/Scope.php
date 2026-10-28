<?php

declare(strict_types=1);

namespace Keel\Container;

/**
 * One unit of work: an HTTP request, a queued job or a consumed message.
 *
 * Scoped services live here and are disposed, in reverse creation order, when the
 * scope closes. Nothing request-specific survives into the next unit of work.
 */
final class Scope
{
    /** @var array<string, object> */
    private array $instances = [];

    private bool $disposed = false;

    public function __construct(private readonly Container $container)
    {
        $this->instances[self::class] = $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        return $this->container->resolve($id, $this);
    }

    /** The container this scope belongs to, for code that opens scopes of its own (jobs, tasks). */
    public function container(): Container
    {
        return $this->container;
    }

    /** Provides a synthetic scoped service, such as the current request. */
    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /** @internal */
    public function instance(string $id): ?object
    {
        return $this->instances[$id] ?? null;
    }

    /**
     * @internal
     * @template T of object
     * @param T $instance
     * @return T
     */
    public function store(string $id, object $instance): object
    {
        if ($this->disposed) {
            throw new Exception\ContainerError('Cannot resolve services from a disposed scope.');
        }

        return $this->instances[$id] = $instance;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        $errors = [];
        foreach (array_reverse($this->instances) as $instance) {
            if ($instance instanceof Disposable) {
                try {
                    $instance->dispose();
                } catch (\Throwable $e) {
                    $errors[] = $e;
                }
            }
        }
        $this->instances = [];

        if ($errors !== []) {
            throw $errors[0];
        }
    }
}
