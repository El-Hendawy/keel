<?php

declare(strict_types=1);

namespace Shop\Billing;

final readonly class Invoice
{
    public function __construct(
        public string $id,
        public string $orderId,
        public int $amountCents,
        public string $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
