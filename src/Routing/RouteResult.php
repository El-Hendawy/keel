<?php

declare(strict_types=1);

namespace Keel\Routing;

final readonly class RouteResult
{
    public const int FOUND = 0;
    public const int NOT_FOUND = 1;
    public const int METHOD_NOT_ALLOWED = 2;

    /**
     * @param self::* $status
     * @param array<string, string> $params
     * @param list<string> $allowed
     */
    public function __construct(
        public int $status,
        public int $route = -1,
        public array $params = [],
        public array $allowed = [],
    ) {
    }
}
