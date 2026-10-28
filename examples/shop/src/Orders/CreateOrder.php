<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Auth\Attribute\Authorize;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Status;

/** Place an order. */
#[Post('/orders', name: 'orders.create')]
#[Status(201)]
#[Authorize(scopes: ['orders:write'])]
final class CreateOrder
{
    public function __invoke(CreateOrderInput $in, Orders $orders): OrderResource
    {
        return OrderResource::from($orders->create($in));
    }
}
