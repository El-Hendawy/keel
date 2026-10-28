<?php

declare(strict_types=1);

namespace Shop\Billing;

use Keel\Module\Module;

#[Module(
    name: 'billing',
    exports: [InvoiceService::class, InvoicePaid::class],
    dependsOn: ['tenancy', 'orders'],
)]
final class BillingModule
{
}
