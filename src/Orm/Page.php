<?php

declare(strict_types=1);

namespace Keel\Orm;

use Keel\Pagination\PageInfo;

/**
 * One page of a cursor-paginated query.
 *
 * @template T of object
 */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(public array $items, public PageInfo $page)
    {
    }
}
