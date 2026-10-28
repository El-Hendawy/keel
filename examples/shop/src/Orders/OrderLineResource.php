<?php

declare(strict_types=1);

namespace Shop\Orders;

final readonly class OrderLineResource
{
    public function __construct(
        public string $sku,
        public int $quantity,
        public int $unitPriceCents,
    ) {
    }
}
