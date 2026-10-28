<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

use Keel\Container\Lifetime;

interface LifetimeAttribute
{
    public function lifetime(): Lifetime;
}
