<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Lifetimes;

use Keel\Container\Attribute\Transient;

#[Transient]
final class TenantFormatter
{
    public function __construct(public CurrentTenant $tenant)
    {
    }
}
