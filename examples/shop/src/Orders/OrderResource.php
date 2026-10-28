<?php

declare(strict_types=1);

namespace Shop\Orders;

/** The public representation of an order. Its public properties are the JSON contract. */
final readonly class OrderResource
{
    /** @param list<OrderLineResource> $items */
    private function __construct(
        public string $id,
        public string $merchantId,
        public OrderStatus $status,
        public ?string $note,
        public array $items,
        public int $totalCents,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function from(Order $order): self
    {
        return new self(
            $order->id,
            $order->merchantId,
            $order->status,
            $order->note,
            array_map(static fn (OrderLine $l): OrderLineResource => new OrderLineResource($l->sku, $l->quantity, $l->unitPriceCents), $order->lines),
            $order->totalCents,
            $order->createdAt,
        );
    }
}
