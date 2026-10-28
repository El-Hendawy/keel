<?php

declare(strict_types=1);

namespace Keel\Pagination;

/** Pagination metadata to include in a list output: `public PageInfo $page`. */
final readonly class PageInfo
{
    public function __construct(public ?string $nextCursor, public bool $hasMore)
    {
    }
}
