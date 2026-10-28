<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Status;

/** Place an order. */
#[Post('/orders', name: 'orders.create')]
#[Status(201)]
final class CreateOrder
{
    public function __invoke(CreateOrderInput $in, Orders $orders): OrderResource
    {
        return OrderResource::from($orders->create($in));
    }
}
