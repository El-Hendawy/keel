<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Pagination\PageInfo;

final readonly class OrderList
{
    /** @param list<OrderResource> $data */
    public function __construct(public array $data, public PageInfo $page)
    {
    }
}
