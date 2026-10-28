<?php

declare(strict_types=1);

namespace Keel\Console;

/**
 * `keel make:*`: writes the files of a new endpoint or migration in the house style,
 * so every endpoint starts typed, documented and secured.
 */
final readonly class CodeGenerator
{
    public function __construct(private string $root)
    {
    }

    /**
     * @param string $name e.g. Orders/CancelOrder
     * @return list<string> files written, relative to the root
     */
    public function endpoint(string $name, string $method, string $path, ?string $scope = null): array
    {
        $method = strtoupper($method);
        if (!\in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException("Unsupported method $method.");
        }
        if (preg_match('#^[A-Z]\w*(/[A-Z]\w*)*$#', $name) !== 1) {
            throw new \InvalidArgumentException('Name endpoints like Orders/CancelOrder (StudlyCase segments).');
        }
        [$namespace, $directory] = $this->namespaceFor(\dirname($name) === '.' ? '' : \dirname($name));
        $class = basename($name);
        preg_match_all('/\{(\w+)(?::[^}]+)?\}/', $path, $matches);
        $params = array_map(static fn (string $p): string => "string \$$p", $matches[1]);
        $hasBody = \in_array($method, ['POST', 'PUT', 'PATCH'], true);
        $attribute = ucfirst(strtolower($method));
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
        $routeName = \dirname($name) === '.' ? $snake : strtolower(str_replace('/', '.', \dirname($name))) . '.' . $snake;

        $files = [];
        if ($hasBody) {
            $params[] = "{$class}Input \$input";
            $files["$directory/{$class}Input.php"] = $this->php($namespace, '', <<<PHP
                /** The request body of $method $path. Every field is validated before the endpoint runs. */
                final readonly class {$class}Input
                {
                    public function __construct(
                        // #[\\Keel\\Validation\\Length(min: 1, max: 200)] public string \$example,
                    ) {
                    }
                }
                PHP);
        }
        $files["$directory/{$class}Result.php"] = $this->php($namespace, '', <<<PHP
            /** The response of $method $path. Public properties are the JSON fields. */
            final readonly class {$class}Result
            {
                public function __construct(
                    public string \$id,
                ) {
                }
            }
            PHP);
        $authorize = $scope === null ? '' : "#[Authorize(scopes: ['$scope'])]\n";
        $uses = "use Keel\\Routing\\Attribute\\$attribute;" . ($scope === null ? '' : "\nuse Keel\\Auth\\Attribute\\Authorize;");
        $signature = implode(', ', $params);
        $files["$directory/$class.php"] = $this->php($namespace, $uses, <<<PHP
            /** TODO: one line describing what this endpoint does (it becomes the OpenAPI summary). */
            #[$attribute('$path', name: '$routeName')]
            {$authorize}final class $class
            {
                public function __invoke($signature): {$class}Result
                {
                    throw new \\Keel\\Http\\Exception\\HttpException(501, 'Not implemented yet.');
                }
            }
            PHP);

        return $this->write($files);
    }

    /** @return list<string> */
    public function migration(string $name, string $directory = 'migrations'): array
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $name), '_'));
        $file = $directory . '/' . gmdate('YmdHis') . "_$slug.sql";

        return $this->write([$file => "-- $name\n-- Forward-only: to undo, write another migration. Keep it portable across SQLite, PostgreSQL and MySQL where you can.\n\n"]);
    }

    /** @return array{0: string, 1: string} namespace and directory for a sub-namespace */
    private function namespaceFor(string $sub): array
    {
        $base = 'App';
        $source = 'src';
        $composer = $this->root . '/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            $autoload = \is_array($data) && \is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
            $psr4 = \is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
            foreach ($psr4 as $prefix => $dir) {
                if (\is_string($prefix) && \is_string($dir)) {
                    $base = rtrim($prefix, '\\');
                    $source = rtrim($dir, '/');
                    break;
                }
            }
        }
        $sub = trim($sub, '/');

        return [
            $sub === '' ? $base : $base . '\\' . str_replace('/', '\\', $sub),
            $sub === '' ? $source : "$source/$sub",
        ];
    }

    private function php(string $namespace, string $uses, string $body): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\n" . ($uses === '' ? '' : "$uses\n\n") . $body . "\n";
    }

    /**
     * @param array<string, string> $files
     * @return list<string>
     */
    private function write(array $files): array
    {
        foreach (array_keys($files) as $file) {
            if (file_exists($this->root . '/' . $file)) {
                throw new \RuntimeException("$file already exists; nothing was written.");
            }
        }
        foreach ($files as $file => $contents) {
            $path = $this->root . '/' . $file;
            if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0o775, true) && !is_dir(\dirname($path))) {
                throw new \RuntimeException('Cannot create ' . \dirname($path));
            }
            file_put_contents($path, $contents);
        }

        return array_keys($files);
    }
}
