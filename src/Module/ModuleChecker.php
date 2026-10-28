<?php

declare(strict_types=1);

namespace Keel\Module;

use Keel\Build\ClassFinder;

/**
 * Enforces module boundaries at build time.
 *
 * Each class belongs to the module whose namespace is the longest prefix of its own.
 * Every class a file references (imports, fully qualified names, constructor and
 * endpoint types) is checked: a reference into another module must target an export,
 * and the target module must be a declared dependency.
 */
final class ModuleChecker
{
    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, array{name: string, namespace: string, exports: list<string>, dependsOn: list<string>}> namespace => module */
    private array $modules = [];

    /** @param list<class-string> $moduleClasses */
    public function __construct(array $moduleClasses)
    {
        $names = [];
        foreach ($moduleClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $module = $reflection->getAttributes(Module::class)[0]->newInstance();
            if (isset($names[$module->name])) {
                $this->errors[] = "Module name \"{$module->name}\" is declared twice ({$names[$module->name]} and $class).";
            }
            $names[$module->name] = $class;
            $this->modules[$reflection->getNamespaceName()] = [
                'name' => $module->name,
                'namespace' => $reflection->getNamespaceName(),
                'exports' => [...$module->exports, $class],
                'dependsOn' => $module->dependsOn,
            ];
        }
        krsort($this->modules); // longest namespaces first

        foreach ($this->modules as $module) {
            foreach ($module['dependsOn'] as $dependency) {
                if (!isset($names[$dependency])) {
                    $this->errors[] = "Module \"{$module['name']}\" depends on unknown module \"$dependency\".";
                }
            }
        }
        $this->detectCycles();
    }

    public function moduleOf(string $class): ?string
    {
        return $this->find($class)['name'] ?? null;
    }

    /**
     * @param list<class-string> $classes
     * @param array<string, list<string>> $extraReferences class => types used in signatures
     */
    public function check(array $classes, ClassFinder $finder, array $extraReferences = []): void
    {
        if ($this->modules === []) {
            return;
        }
        $reported = [];
        foreach ($classes as $class) {
            $owner = $this->find($class);
            if ($owner === null) {
                continue;
            }
            $file = $finder->fileOf($class);
            $references = [...($file->referenced ?? []), ...($extraReferences[$class] ?? [])];
            foreach (array_unique($references) as $reference) {
                $target = $this->find($reference);
                if ($target === null || $target['name'] === $owner['name']) {
                    continue;
                }
                $key = $owner['name'] . '|' . $reference;
                if (isset($reported[$key])) {
                    continue;
                }
                if (!\in_array($target['name'], $owner['dependsOn'], true)) {
                    $reported[$key] = true;
                    $this->errors[] = "Module \"{$owner['name']}\" uses $reference from module \"{$target['name']}\" (in $class) but does not list \"{$target['name']}\" in dependsOn.";
                } elseif (!\in_array($reference, $target['exports'], true) && (class_exists($reference) || interface_exists($reference) || enum_exists($reference))) {
                    $reported[$key] = true;
                    $this->errors[] = "Module \"{$owner['name']}\" uses $reference (in $class), which module \"{$target['name']}\" does not export. Use its public API or add it to exports.";
                }
            }
        }
    }

    /** @return list<array{name: string, namespace: string, exports: list<string>, dependsOn: list<string>}> */
    public function modules(): array
    {
        return array_values($this->modules);
    }

    /** @return array{name: string, namespace: string, exports: list<string>, dependsOn: list<string>}|null */
    private function find(string $class): ?array
    {
        foreach ($this->modules as $namespace => $module) {
            if (str_starts_with($class, $namespace . '\\')) {
                return $module;
            }
        }

        return null;
    }

    private function detectCycles(): void
    {
        $graph = [];
        foreach ($this->modules as $module) {
            $graph[$module['name']] = $module['dependsOn'];
        }
        $state = [];
        foreach (array_keys($graph) as $name) {
            if (($state[$name] ?? 0) === 0) {
                $this->visit($name, [], $state, $graph);
            }
        }
    }

    /**
     * @param list<string> $path
     * @param array<string, int> $state 1 = visiting, 2 = done
     * @param array<string, list<string>> $graph
     */
    private function visit(string $name, array $path, array &$state, array $graph): void
    {
        $state[$name] = 1;
        foreach ($graph[$name] ?? [] as $next) {
            if (($state[$next] ?? 0) === 1) {
                $this->errors[] = 'Modules depend on each other in a cycle: ' . implode(' -> ', [...$path, $name, $next]) . '. Extract the shared part into its own module or communicate through events.';
            } elseif (($state[$next] ?? 0) === 0) {
                $this->visit($next, [...$path, $name], $state, $graph);
            }
        }
        $state[$name] = 2;
    }
}
