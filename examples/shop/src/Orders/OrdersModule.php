<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Module\Module;

#[Module(
    name: 'orders',
    exports: [OrderPlaced::class],
    dependsOn: ['tenancy'],
)]
final class OrdersModule
{
}
