<?php

declare(strict_types=1);

namespace Shop\Billing;

final readonly class InvoicePaid
{
    public function __construct(public string $invoiceId, public string $orderId)
    {
    }
}
