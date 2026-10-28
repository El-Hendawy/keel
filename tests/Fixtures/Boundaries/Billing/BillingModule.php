<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Boundaries\Billing;

use Keel\Module\Module;

#[Module(name: 'billing', exports: [InvoiceService::class])]
final class BillingModule
{
}
