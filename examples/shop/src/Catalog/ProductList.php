<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Pagination\PageInfo;

final readonly class ProductList
{
    /** @param list<ProductResource> $data */
    public function __construct(public array $data, public PageInfo $page)
    {
    }
}
