<?php

declare(strict_types=1);

namespace Keel\Build;

final readonly class SourceFile
{
    /**
     * @param array<string, string> $uses alias => fully qualified name
     * @param list<string> $classes classes declared in the file
     * @param list<string> $referenced fully qualified names imported or written in the file
     */
    public function __construct(
        public string $path,
        public string $namespace,
        public array $uses,
        public array $classes,
        public array $referenced,
    ) {
    }

    /** Resolves a name as written in this file to a fully qualified name. */
    public function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = explode('\\', $name, 2);
        if (isset($this->uses[$first[0]])) {
            return $this->uses[$first[0]] . (isset($first[1]) ? '\\' . $first[1] : '');
        }

        return ltrim($this->namespace . '\\' . $name, '\\');
    }
}
