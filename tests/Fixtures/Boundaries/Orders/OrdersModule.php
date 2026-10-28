<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Boundaries\Orders;

use Keel\Module\Module;

// Does not declare dependsOn: ['billing'].
#[Module(name: 'orders')]
final class OrdersModule
{
}
