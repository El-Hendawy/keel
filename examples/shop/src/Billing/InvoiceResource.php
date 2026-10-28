<?php

declare(strict_types=1);

namespace Shop\Billing;

final readonly class InvoiceResource
{
    public function __construct(
        public string $id,
        public string $orderId,
        public int $amountCents,
        public string $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function from(Invoice $invoice): self
    {
        return new self($invoice->id, $invoice->orderId, $invoice->amountCents, $invoice->status, $invoice->createdAt);
    }
}
