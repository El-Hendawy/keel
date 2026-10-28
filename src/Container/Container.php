<?php

declare(strict_types=1);

namespace Keel\Container;

use Keel\Container\Exception\ConfigMissing;
use Keel\Container\Exception\ContainerError;
use Keel\Container\Exception\ScopeRequired;
use Keel\Container\Exception\ServiceNotFound;
use Psr\Container\ContainerInterface;

/**
 * Base class of the compiled container.
 *
 * The build step generates a final subclass with one factory method per service and
 * the LIFETIMES / ALIASES tables as constants. Resolution is a constant lookup plus a
 * `match` jump: no reflection, no string parsing, no closures on the hot path.
 *
 * Application code never calls get() with a string: dependencies arrive through
 * constructors. The PSR-11 methods exist for interoperability with other libraries.
 */
abstract class Container implements ContainerInterface
{
    /** @var array<string, int> service id => Lifetime::code() */
    protected const array LIFETIMES = [];

    /** @var array<string, string> abstract id => concrete id */
    protected const array ALIASES = [];

    /** @var array<string, true> ids that must be provided at runtime (scope->set / provide) */
    protected const array SYNTHETIC = [];

    /** @var array<string, object> */
    private array $singletons = [];

    /** @var array<string, object> */
    private array $overrides = [];

    /** @var array<string, mixed> flattened dotted keys */
    private readonly array $config;

    /** @param array<string, mixed> $config nested configuration */
    public function __construct(array $config, private readonly bool $testing = false)
    {
        $this->config = self::flatten($config);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        return $this->resolve($id, null);
    }

    public function has(string $id): bool
    {
        return isset(static::LIFETIMES[static::ALIASES[$id] ?? $id]);
    }

    public function createScope(): Scope
    {
        return new Scope($this);
    }

    /** Provides a synthetic singleton, such as the build manifest or environment. */
    public function provide(string $id, object $instance): void
    {
        if (!isset(static::SYNTHETIC[$id]) || (static::LIFETIMES[$id] ?? -1) !== 0) {
            throw new ContainerError(\sprintf('"%s" is not a synthetic singleton.', $id));
        }
        $this->singletons[$id] = $instance;
    }

    /**
     * Replaces a service with a test double. Only available when booted for tests.
     *
     * @template T of object
     * @param class-string<T> $id
     * @param T $instance
     */
    public function override(string $id, object $instance): void
    {
        if (!$this->testing) {
            throw new ContainerError('Services can only be overridden in the test environment.');
        }
        $this->overrides[$id] = $instance;
        $target = static::ALIASES[$id] ?? $id;
        $this->overrides[$target] = $instance;
        unset($this->singletons[$target]);
    }

    /**
     * @internal used by Scope and the compiled factories
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function resolve(string $id, ?Scope $scope): object
    {
        if ($this->overrides !== [] && isset($this->overrides[$id])) {
            /** @var T */
            return $this->overrides[$id];
        }

        $target = static::ALIASES[$id] ?? $id;
        $lifetime = static::LIFETIMES[$target] ?? throw ServiceNotFound::for($id);

        if ($lifetime === 0) {
            /** @var T */
            return $this->singletons[$target] ??= $this->build($target, null);
        }

        if ($lifetime === 1) {
            if ($scope === null) {
                throw ScopeRequired::for($id);
            }

            /** @var T */
            return $scope->instance($target) ?? $scope->store($target, $this->build($target, $scope));
        }

        /** @var T */
        return $this->build($target, $scope);
    }

    /** Disposes every singleton that holds resources. Called on worker shutdown. */
    public function dispose(): void
    {
        foreach (array_reverse($this->singletons) as $service) {
            if ($service instanceof Disposable) {
                $service->dispose();
            }
        }
        $this->singletons = [];
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return $this->config;
    }

    /** Builds a new instance of a concrete service. Generated. */
    abstract protected function build(string $id, ?Scope $scope): object;

    protected function synthetic(string $id): never
    {
        throw new ContainerError(\sprintf(
            '"%s" is provided at runtime by the framework and has not been set in this context.',
            $id,
        ));
    }

    protected function configString(string $key, ?string $default = null): string
    {
        $value = $this->configValue($key, $default);

        return \is_string($value) || \is_int($value) || \is_float($value)
            ? (string) $value
            : throw ConfigMissing::type($key, 'a string', $value);
    }

    protected function configInt(string $key, ?int $default = null): int
    {
        $value = $this->configValue($key, $default);
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw ConfigMissing::type($key, 'an int', $value);
    }

    protected function configFloat(string $key, ?float $default = null): float
    {
        $value = $this->configValue($key, $default);
        if (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw ConfigMissing::type($key, 'a number', $value);
    }

    protected function configBool(string $key, ?bool $default = null): bool
    {
        $value = $this->configValue($key, $default);

        return match (\is_string($value) ? strtolower($value) : $value) {
            true, 'true', '1', 1, 'yes', 'on' => true,
            false, 'false', '0', 0, 'no', 'off', '' => false,
            default => throw ConfigMissing::type($key, 'a bool', $value),
        };
    }

    /**
     * @param list<string>|null $default
     * @return list<string>
     */
    protected function configStringList(string $key, ?array $default = null): array
    {
        $value = $this->configValue($key, $default);
        if (\is_string($value)) {
            $value = $value === '' ? [] : array_map(trim(...), explode(',', $value)); // from an environment variable
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw ConfigMissing::type($key, 'a list of strings', $value);
        }
        $strings = [];
        foreach ($value as $item) {
            $strings[] = \is_string($item) ? $item : throw ConfigMissing::type($key, 'a list of strings', $value);
        }

        return $strings;
    }

    /**
     * @param array<mixed>|null $default
     * @return array<mixed>
     */
    protected function configArray(string $key, ?array $default = null): array
    {
        $value = $this->configValue($key, $default);

        return \is_array($value) ? $value : throw ConfigMissing::type($key, 'an array', $value);
    }

    /** A null default means the key is required. */
    private function configValue(string $key, mixed $default): mixed
    {
        if (\array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        return $default ?? throw ConfigMissing::key($key);
    }

    /**
     * @param array<mixed> $config
     * @return array<string, mixed>
     */
    private static function flatten(array $config, string $prefix = ''): array
    {
        $flat = [];
        foreach ($config as $key => $value) {
            $path = $prefix . $key;
            $flat[$path] = $value;
            if (\is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat += self::flatten($value, $path . '.');
            }
        }

        return $flat;
    }
}
