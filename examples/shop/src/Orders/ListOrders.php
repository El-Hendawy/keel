<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Routing\Attribute\Get;

/** List the most recent orders. */
#[Get('/orders', name: 'orders.list')]
final class ListOrders
{
    public function __invoke(ListOrdersQuery $query, Orders $orders): OrderList
    {
        return new OrderList(array_map(OrderResource::from(...), $orders->latest($query->limit, $query->status)));
    }
}
