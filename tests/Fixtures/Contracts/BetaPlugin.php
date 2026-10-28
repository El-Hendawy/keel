<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class BetaPlugin implements Plugin
{
}
