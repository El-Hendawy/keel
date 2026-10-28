<?php

declare(strict_types=1);

namespace Shop\Orders;

/** Published when an order is placed. Part of the orders module's public API. */
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public string $tenantId,
        public int $totalCents,
    ) {
    }
}
