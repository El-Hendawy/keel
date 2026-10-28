<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Lifetimes;

use Keel\Container\Attribute\Scoped;

#[Scoped]
final class CurrentTenant
{
}
