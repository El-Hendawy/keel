<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Container\Attribute\Singleton;

/** Process-wide state shared by every request of a server process. */
#[Singleton]
final class Counter
{
    public int $value = 0;
}
