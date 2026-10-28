<?php

declare(strict_types=1);

namespace Shop\Tenancy;

use Keel\Module\Module;

#[Module(name: 'tenancy', exports: [CurrentTenant::class])]
final class TenancyModule
{
}
