<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

use Attribute;
use Keel\Container\Lifetime;

#[Attribute(Attribute::TARGET_CLASS)]
final class Scoped implements LifetimeAttribute
{
    public function lifetime(): Lifetime
    {
        return Lifetime::Scoped;
    }
}
