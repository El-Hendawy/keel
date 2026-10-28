<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Http\Exception\NotFound;
use Keel\Auth\Attribute\Authorize;
use Keel\Routing\Attribute\Get;

/** Fetch one order. */
#[Get('/orders/{id}', name: 'orders.show')]
#[Authorize(scopes: ['orders:read'])]
final class GetOrder
{
    public function __invoke(string $id, Orders $orders): OrderResource
    {
        $order = $orders->find($id) ?? throw new NotFound("Order $id does not exist.");

        return OrderResource::from($order);
    }
}
