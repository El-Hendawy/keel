<?php

declare(strict_types=1);

namespace Keel\Routing;

/**
 * Matches requests against the compiled route table.
 *
 * Static paths are a hash lookup. Dynamic paths are tested against a few combined
 * regular expressions per method (branch reset + MARK), so matching cost does not
 * grow linearly with the number of routes.
 *
 * @phpstan-type Table array{
 *     static: array<string, array<string, int>>,
 *     dynamic: array<string, list<array{0: string, 1: array<string, array{0: int, 1: list<string>}>}>>,
 *     routes: list<array{method: string, path: string, name: ?string, handler: string, status: int}>
 * }
 */
final readonly class Router
{
    /** @param Table $table */
    public function __construct(private array $table)
    {
    }

    public function match(string $method, string $path): RouteResult
    {
        $found = $this->matchMethod($method, $path);
        if ($found === null && $method === 'HEAD') {
            $found = $this->matchMethod('GET', $path);
        }
        if ($found !== null) {
            return $found;
        }

        $allowed = [];
        foreach ($this->methods() as $candidate) {
            if ($candidate !== $method && $this->matchMethod($candidate, $path) !== null) {
                $allowed[] = $candidate;
            }
        }

        return $allowed === []
            ? new RouteResult(RouteResult::NOT_FOUND)
            : new RouteResult(RouteResult::METHOD_NOT_ALLOWED, allowed: $allowed);
    }

    /** @return array{method: string, path: string, name: ?string, handler: string, status: int} */
    public function route(int $index): array
    {
        return $this->table['routes'][$index];
    }

    /** @return list<array{method: string, path: string, name: ?string, handler: string, status: int}> */
    public function routes(): array
    {
        return $this->table['routes'];
    }

    /**
     * Builds a path for a named route.
     *
     * @param array<string, string|int> $params
     */
    public function path(string $name, array $params = []): string
    {
        foreach ($this->table['routes'] as $route) {
            if ($route['name'] === $name) {
                return (string) preg_replace_callback(
                    '/\{(\w+)(?::[^}]+)?\}/',
                    static fn (array $m): string => rawurlencode((string) ($params[$m[1]] ?? throw new \InvalidArgumentException("Missing route parameter \"{$m[1]}\" for route \"$name\"."))),
                    $route['path'],
                );
            }
        }

        throw new \InvalidArgumentException("Unknown route \"$name\".");
    }

    private function matchMethod(string $method, string $path): ?RouteResult
    {
        $static = $this->table['static'][$method][$path] ?? null;
        if ($static !== null) {
            return new RouteResult(RouteResult::FOUND, $static);
        }

        foreach ($this->table['dynamic'][$method] ?? [] as [$regex, $marks]) {
            if (preg_match($regex, $path, $m) === 1) {
                [$route, $names] = $marks[$m['MARK']];
                $params = [];
                foreach ($names as $i => $name) {
                    $params[$name] = rawurldecode($m[$i + 1]);
                }

                return new RouteResult(RouteResult::FOUND, $route, $params);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function methods(): array
    {
        return array_values(array_unique([...array_keys($this->table['static']), ...array_keys($this->table['dynamic'])]));
    }
}
