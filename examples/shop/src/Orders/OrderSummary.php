<?php

declare(strict_types=1);

namespace Shop\Orders;

final readonly class OrderSummary
{
    public function __construct(
        public string $orderId,
        public int $totalCents,
        public string $currency,
        public float $rate,
        public int $convertedTotalCents,
    ) {
    }
}
