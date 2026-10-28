<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Boundaries\Orders;

use Keel\Container\Attribute\Singleton;
use Keel\Tests\Fixtures\Boundaries\Billing\InvoiceRepository;

#[Singleton]
final class Checkout
{
    public function __construct(public InvoiceRepository $invoices)
    {
    }
}
