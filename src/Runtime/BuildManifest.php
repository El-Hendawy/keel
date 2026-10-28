<?php

declare(strict_types=1);

namespace Keel\Runtime;

/**
 * @phpstan-import-type Table from \Keel\Routing\Router
 *
 * What the build produced: generated class names, the route table and listener map.
 * Loaded once per worker; read-only afterwards.
 *
 * @phpstan-type Data array{
 *     version: string,
 *     fingerprint: string,
 *     env: string,
 *     builtAt: string,
 *     container: class-string<\Keel\Container\Container>,
 *     endpoints: class-string,
 *     files: list<string>,
 *     routes: Table,
 *     listeners: array<string, list<class-string>>,
 *     middleware: list<class-string<\Psr\Http\Server\MiddlewareInterface>>,
 *     bindings: array<string, string>,
 *     config: array<string, mixed>
 * }
 */
final class BuildManifest
{
    /** @var array<string, list<class-string>> */
    private array $resolvedListeners = [];

    /** @param Data $data */
    public function __construct(public readonly array $data, public readonly string $directory)
    {
    }

    public static function load(string $directory): ?self
    {
        $file = $directory . '/manifest.php';
        if (!is_file($file)) {
            return null;
        }
        /** @var Data $data */
        $data = require $file;

        return new self($data, $directory);
    }

    /** Requires the generated classes. Safe to call more than once. */
    public function loadClasses(): void
    {
        if (class_exists($this->data['container'], false)) {
            return;
        }
        foreach ($this->data['files'] as $file) {
            require_once $this->directory . '/' . $file;
        }
    }

    /**
     * Listener service ids for an event class, including listeners of its parents and interfaces.
     *
     * @return list<class-string>
     */
    public function listenersFor(string $eventClass): array
    {
        if (isset($this->resolvedListeners[$eventClass])) {
            return $this->resolvedListeners[$eventClass];
        }
        $listeners = $this->data['listeners'][$eventClass] ?? [];
        foreach ([...(class_parents($eventClass) ?: []), ...(class_implements($eventClass) ?: [])] as $type) {
            $listeners = [...$listeners, ...($this->data['listeners'][$type] ?? [])];
        }

        return $this->resolvedListeners[$eventClass] = array_values(array_unique($listeners));
    }

    /** @return array<string, string> */
    public function bindings(): array
    {
        return $this->data['bindings'];
    }
}
