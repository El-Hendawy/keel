<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Auth\Attribute\Authorize;
use Keel\Pagination\Cursors;
use Keel\Pagination\PageInfo;
use Keel\Routing\Attribute\Get;

/** List orders, newest first, one page at a time. */
#[Get('/orders', name: 'orders.list')]
#[Authorize(scopes: ['orders:read'])]
final class ListOrders
{
    public function __invoke(ListOrdersQuery $query, Orders $orders, Cursors $cursors): OrderList
    {
        $after = $query->cursor === null ? null : $cursors->decode($query->cursor, 'orders.list');
        $page = $orders->latest($query->limit + 1, $query->status, $after);
        $hasMore = \count($page) > $query->limit;
        $page = \array_slice($page, 0, $query->limit);
        $last = end($page);
        $next = $hasMore && $last !== false
            ? $cursors->encode(['c' => $last->createdAt->format(\DATE_RFC3339_EXTENDED), 'i' => $last->id], 'orders.list')
            : null;

        return new OrderList(array_map(OrderResource::from(...), $page), new PageInfo($next, $hasMore));
    }
}
