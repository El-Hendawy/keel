<?php

declare(strict_types=1);

namespace Shop\Billing;

use Keel\Events\Listener;
use Shop\Orders\OrderPlaced;

#[Listener]
final readonly class CreateInvoiceWhenOrderPlaced
{
    public function __construct(private InvoiceService $invoices)
    {
    }

    public function __invoke(OrderPlaced $event): void
    {
        $this->invoices->createFor($event->orderId, $event->totalCents);
    }
}
