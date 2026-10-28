<?php

declare(strict_types=1);

namespace Shop\Orders;

final readonly class OrderList
{
    /** @param list<OrderResource> $data */
    public function __construct(public array $data)
    {
    }
}
