<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Lifetimes;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class ReportCache
{
    // Indirect: the Transient formatter needs a Scoped service.
    public function __construct(public TenantFormatter $formatter)
    {
    }
}
