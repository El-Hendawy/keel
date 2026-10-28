<?php

declare(strict_types=1);

namespace Keel\Build\Http;

use Keel\Build\Php;
use Keel\Build\Types\HydratorCompiler;
use Keel\Build\Types\SerializerCompiler;
use Keel\Build\Types\TypeAnalyzer;
use Keel\Build\Types\TypeInfo;
use Keel\Auth\Attribute\Anonymous;
use Keel\Auth\Attribute\Authorize;
use Keel\Auth\Policy;
use Keel\Auth\Principal;
use Keel\Routing\Attribute\Body;
use Keel\Routing\Attribute\Query;
use Keel\Routing\Attribute\Route;
use Keel\Routing\Attribute\Status;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Compiles endpoint classes into a route table and one dispatch method per route.
 *
 * Each `__invoke` parameter is classified once, at build time:
 *  - its name matches a path parameter     -> taken from the URL, coerced to its type
 *  - its type is a registered service      -> resolved from the request scope
 *  - its type is Principal (or ?Principal)  -> the authenticated caller
 *  - its type is any other class           -> the typed input, hydrated from body or query
 * Anything else is a build error, so a route that compiles can always be called.
 *
 * With auth enabled, every route requires a principal unless marked #[Anonymous], and
 * #[Authorize] scopes and policies are compiled into the dispatch method.
 */
final class RouteCompiler
{
    private const int CHUNK = 40;

    /** @var list<string> */
    public array $errors = [];

    /** @var list<RouteDefinition> */
    private array $routes = [];

    /** @var list<string> */
    private array $methods = [];

    /** @param callable(string): bool $isService */
    public function __construct(
        private readonly TypeAnalyzer $types,
        private readonly HydratorCompiler $hydrators,
        private readonly SerializerCompiler $serializers,
        private $isService,
        private readonly bool $auth = false,
    ) {
    }

    /**
     * @param class-string $class
     * @return bool whether the class declares routes
     */
    public function add(string $class): bool
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return false;
        }

        if (!$reflection->hasMethod('__invoke') || !$reflection->getMethod('__invoke')->isPublic()) {
            $this->errors[] = "Endpoint $class must define a public __invoke() method.";

            return true;
        }

        foreach ($attributes as $attribute) {
            $route = $attribute->newInstance();
            $this->define($reflection, $route);
        }

        return true;
    }

    /** @return list<RouteDefinition> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @param ReflectionClass<object> $class */
    private function define(ReflectionClass $class, Route $route): void
    {
        $where = $class->getName() . ' (' . $route->method . ' ' . $route->path . ')';
        $method = strtoupper($route->method);
        if (!str_starts_with($route->path, '/')) {
            $this->errors[] = "$where: paths must start with '/'.";

            return;
        }

        preg_match_all('/\{(\w+)(?::([^}]+))?\}/', $route->path, $placeholders, PREG_SET_ORDER);
        $explicitPatterns = [];
        foreach ($placeholders as $placeholder) {
            $explicitPatterns[$placeholder[1]] = $placeholder[2] ?? null;
        }

        $auth = $this->authOf($class, $where);
        $guard = '';
        if ($auth !== null && !$auth['anonymous']) {
            $guard = '$principal = $scope->get(\\Keel\\Auth\\AuthContext::class)->require(' . Php::export($auth['scopes']) . ");\n        ";
            if ($auth['policy'] !== null) {
                $guard .= 'if (!$scope->get(\\' . $auth['policy'] . '::class)->allows($principal, $request, $params)) {' . "\n"
                    . "            throw new \\Keel\\Http\\Exception\\Forbidden('You are not allowed to perform this action.');\n        }\n        ";
            }
        }

        $invoke = $class->getMethod('__invoke');
        $pathParams = [];
        $patterns = [];
        $input = null;
        $inputSource = null;
        $arguments = [];

        foreach ($invoke->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $at = "$where parameter \$$name";

            if (\array_key_exists($name, $explicitPatterns)) {
                $info = $this->types->typeOf($type, null, null, $at);
                if ($info === null) {
                    continue;
                }
                if (!\in_array($info->kind, [TypeInfo::STRING, TypeInfo::INT, TypeInfo::ENUM], true) || $info->nullable) {
                    $this->errors[] = "$at: path parameters must be string, int or a backed enum, and not nullable.";
                    continue;
                }
                $pathParams[$name] = $info;
                $patterns[$name] = $explicitPatterns[$name] ?? ($info->kind === TypeInfo::INT ? '\d+' : '[^/]+');
                $arguments[] = $this->pathArgument($name, $info);
                continue;
            }

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                $this->errors[] = "$at is not a path parameter and has no class type. Rename it to match a {placeholder} or give it a class type.";
                continue;
            }

            $typeName = $type->getName();
            if ($typeName === Principal::class) {
                if ($auth === null) {
                    $this->errors[] = "$at: endpoints can only receive a Principal when authentication is enabled. Call ->withAuth() in app.php.";
                } elseif ($auth['anonymous'] && !$type->allowsNull()) {
                    $this->errors[] = "$at: {$class->getName()} is #[Anonymous], so the caller may not be authenticated. Declare the parameter as ?Principal.";
                }
                $arguments[] = '$scope->get(\\Keel\\Auth\\AuthContext::class)->' . ($type->allowsNull() ? 'user()' : 'principal()');
                continue;
            }
            if ($typeName === ServerRequestInterface::class || ($this->isService)($typeName)) {
                $arguments[] = '$scope->get(\\' . $typeName . '::class)';
                continue;
            }

            if ($input !== null) {
                $this->errors[] = "$at: an endpoint takes at most one input object (already have $input).";
                continue;
            }
            if (!class_exists($typeName)) {
                $this->errors[] = "$at: $typeName is neither a registered service nor a class that can be used as input.";
                continue;
            }
            /** @var class-string $typeName */
            $input = $typeName;
            $inputSource = match (true) {
                $parameter->getAttributes(Query::class) !== [] => 'query',
                $parameter->getAttributes(Body::class) !== [] => 'body',
                \in_array($method, ['GET', 'HEAD', 'DELETE'], true) => 'query',
                default => 'body',
            };
            $entry = $this->hydrators->entry($typeName);
            $arguments[] = $inputSource === 'query'
                ? "Hydrators::$entry(\$request->getQueryParams(), true)"
                : "Hydrators::$entry(\\Keel\\Http\\RequestBody::decode(\$request))";
        }

        foreach (array_keys($explicitPatterns) as $placeholder) {
            if (!isset($pathParams[$placeholder])) {
                $this->errors[] = "$where: path parameter {{$placeholder}} has no matching __invoke parameter \$$placeholder.";
            }
        }

        $return = $invoke->getReturnType();
        $output = null;
        $returnsResponse = false;
        $call = '$scope->get(\\' . $class->getName() . '::class)(' . implode(', ', $arguments) . ')';
        $statusAttribute = $class->getAttributes(Status::class)[0] ?? null;
        $status = $statusAttribute?->newInstance()->code ?? 200;

        if (!$return instanceof ReflectionNamedType) {
            $this->errors[] = "$where: __invoke() must declare a single return type (an output class, ResponseInterface or void).";

            return;
        }
        $returnName = $return->getName();
        if ($returnName === 'void') {
            $status = $statusAttribute === null ? 204 : $status;
            $body = "$call;\n\n        return \\Keel\\Http\\Responses::empty($status);";
        } elseif (is_a($returnName, ResponseInterface::class, true)) {
            $returnsResponse = true;
            $body = "return $call;";
        } elseif (!$return->isBuiltin() && class_exists($returnName) && !$return->allowsNull()) {
            /** @var class-string $returnName */
            $output = $returnName;
            $entry = $this->serializers->entry($returnName);
            $body = "return \\Keel\\Http\\Responses::json(Serializers::$entry($call), $status);";
        } else {
            $this->errors[] = "$where: __invoke() returns $returnName. Return a typed output class, ResponseInterface or void; throw NotFound instead of returning null. Arrays are not data contracts.";

            return;
        }

        $definition = new RouteDefinition(
            $method,
            $route->path,
            $route->name,
            $class->getName(),
            $status,
            $pathParams,
            $patterns,
            $input,
            $inputSource,
            $output,
            $returnsResponse,
            self::summary($class),
            $auth,
        );
        $definition->handler = 'r' . \count($this->routes);
        $this->routes[] = $definition;
        $this->methods[] = "    /** @param array<string, string> \$params */\n"
            . "    public static function {$definition->handler}(Scope \$scope, ServerRequestInterface \$request, array \$params): ResponseInterface\n"
            . "    {\n        $guard$body\n    }\n";
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{anonymous: bool, scopes: list<string>, policy: ?string}|null
     */
    private function authOf(ReflectionClass $class, string $where): ?array
    {
        $anonymous = $class->getAttributes(Anonymous::class) !== [];
        $attribute = $class->getAttributes(Authorize::class)[0] ?? null;
        $authorize = $attribute?->newInstance();
        if (!$this->auth) {
            if ($authorize !== null) {
                $this->errors[] = "$where uses #[Authorize], but authentication is not enabled. Call ->withAuth() in app.php.";
            }

            return null;
        }
        if ($anonymous && $authorize !== null) {
            $this->errors[] = "$where is both #[Anonymous] and #[Authorize]. Pick one.";
        }
        $arguments = $attribute?->getArguments() ?? [];
        $policy = $arguments['policy'] ?? $arguments[1] ?? null; // raw, so a wrong class is reported, not trusted
        if ($policy !== null) {
            if (!\is_string($policy) || !is_a($policy, Policy::class, true)) {
                $policy = \is_string($policy) ? $policy : get_debug_type($policy);
                $this->errors[] = "$where: policy $policy must implement " . Policy::class . '.';
                $policy = null;
            } elseif (!($this->isService)($policy)) {
                $this->errors[] = "$where: policy $policy is not a service. Give it #[Singleton] or #[Scoped].";
                $policy = null;
            }
        }

        return ['anonymous' => $anonymous, 'scopes' => $authorize->scopes ?? [], 'policy' => $policy];
    }

    private function pathArgument(string $name, TypeInfo $info): string
    {
        $raw = '$params[' . Php::string($name) . ']';

        return match ($info->kind) {
            TypeInfo::INT => "\\Keel\\Validation\\Coerce::int($raw) ?? throw new \\Keel\\Http\\Exception\\NotFound()",
            TypeInfo::ENUM => (TypeInfo::isIntBacked($info->classOrFail())
                ? '\\' . $info->class . "::tryFrom((int) $raw)"
                : '\\' . $info->class . "::tryFrom($raw)") . ' ?? throw new \\Keel\\Http\\Exception\\NotFound()',
            default => $raw,
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>} [route table, conflict errors]
     */
    public function table(): array
    {
        $static = [];
        $dynamic = [];
        $routes = [];
        $errors = [];
        $seen = [];

        foreach ($this->routes as $index => $route) {
            $signature = $route->method . ' ' . preg_replace('/\{(\w+)(?::[^}]+)?\}/', '{}', $route->path);
            if (isset($seen[$signature])) {
                $errors[] = "Route conflict: $signature is declared by both {$seen[$signature]} and {$route->class}.";
            }
            $seen[$signature] = $route->class;

            $routes[] = [
                'method' => $route->method,
                'path' => $route->path,
                'name' => $route->name,
                'handler' => $route->handler,
                'status' => $route->status,
            ];
            if ($route->isStatic()) {
                $static[$route->method][$route->path] = $index;
            } else {
                $dynamic[$route->method][] = $index;
            }
        }

        $compiledDynamic = [];
        foreach ($dynamic as $method => $indexes) {
            foreach (array_chunk($indexes, self::CHUNK) as $chunk) {
                $parts = [];
                $marks = [];
                foreach ($chunk as $n => $index) {
                    [$regex, $names] = $this->regex($this->routes[$index]);
                    $mark = 'm' . $n;
                    $parts[] = $regex . '(*MARK:' . $mark . ')';
                    $marks[$mark] = [$index, $names];
                }
                $compiledDynamic[$method][] = ['~^(?|' . implode('|', $parts) . ')$~', $marks];
            }
        }

        return [['static' => $static, 'dynamic' => $compiledDynamic, 'routes' => $routes], $errors];
    }

    /** @return array{0: string, 1: list<string>} */
    private function regex(RouteDefinition $route): array
    {
        $names = [];
        $regex = '';
        $offset = 0;
        preg_match_all('/\{(\w+)(?::([^}]+))?\}/', $route->path, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$whole, $start] = $match[0];
            $regex .= preg_quote(substr($route->path, $offset, $start - $offset), '~');
            $name = $match[1][0];
            $names[] = $name;
            $regex .= '(' . ($route->patterns[$name] ?? '[^/]+') . ')';
            $offset = $start + \strlen($whole);
        }
        $regex .= preg_quote(substr($route->path, $offset), '~');

        return [$regex, $names];
    }

    public function compileEndpoints(string $namespace, string $class): string
    {
        return "<?php\n\n// Generated by Keel. Do not edit: run `keel build` instead.\n\ndeclare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n"
            . "use Keel\\Container\\Scope;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Psr\\Http\\Message\\ServerRequestInterface;\n\n"
            . "final class $class\n{\n" . implode("\n", $this->methods) . "}\n";
    }

    /** @param ReflectionClass<object> $class */
    private static function summary(ReflectionClass $class): ?string
    {
        $doc = $class->getDocComment();
        if ($doc === false) {
            return null;
        }
        foreach (explode("\n", $doc) as $line) {
            $line = trim(ltrim(trim($line), '/*'));
            if ($line !== '' && !str_starts_with($line, '@')) {
                return $line;
            }
        }

        return null;
    }
}
