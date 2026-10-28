<?php

declare(strict_types=1);

namespace Keel\Build\Http;

use Keel\Build\Types\TypeInfo;

/** Everything the compiler learned about one route; also feeds OpenAPI and `keel inspect`. */
final class RouteDefinition
{
    /**
     * @param class-string $class
     * @param array<string, TypeInfo> $pathParams
     * @param array<string, string> $patterns path parameter => regex
     * @param class-string|null $input
     * @param 'body'|'query'|null $inputSource
     * @param class-string|null $output
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $name,
        public readonly string $class,
        public readonly int $status,
        public readonly array $pathParams,
        public readonly array $patterns,
        public readonly ?string $input,
        public readonly ?string $inputSource,
        public readonly ?string $output,
        public readonly bool $returnsResponse,
        public readonly ?string $summary,
        public string $handler = '',
        public ?string $module = null,
    ) {
    }

    public function isStatic(): bool
    {
        return $this->pathParams === [] && !str_contains($this->path, '{');
    }
}
