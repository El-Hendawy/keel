<?php

declare(strict_types=1);

namespace Shop\Billing;

use Keel\Http\Exception\NotFound;
use Keel\Auth\Attribute\Authorize;
use Keel\Routing\Attribute\Get;

/** Fetch the invoice of an order. */
#[Get('/orders/{orderId}/invoice', name: 'billing.invoice')]
#[Authorize(scopes: ['invoices:read'])]
final class GetInvoice
{
    public function __invoke(string $orderId, InvoiceService $invoices): InvoiceResource
    {
        $invoice = $invoices->forOrder($orderId) ?? throw new NotFound("Order $orderId has no invoice.");

        return InvoiceResource::from($invoice);
    }
}
