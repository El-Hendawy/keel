<?php

declare(strict_types=1);

namespace Shop\Orders;

/** The order entity: typed, immutable, no magic properties. */
final readonly class Order
{
    /** @param list<OrderLine> $lines */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $merchantId,
        public OrderStatus $status,
        public ?string $note,
        public array $lines,
        public int $totalCents,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
