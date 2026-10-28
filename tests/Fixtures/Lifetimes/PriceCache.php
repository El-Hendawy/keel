<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Lifetimes;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class PriceCache
{
    // Boot error: Singleton PriceCache cannot depend on Scoped CurrentTenant.
    public function __construct(public CurrentTenant $tenant)
    {
    }
}
