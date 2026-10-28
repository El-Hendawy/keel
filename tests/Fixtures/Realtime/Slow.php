<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Routing\Attribute\Get;

use function Keel\Async\delay;

/** Waits without blocking the process (like a slow query or API call would). */
#[Get('/slow/{ms}')]
final class Slow
{
    public function __invoke(int $ms): Pong
    {
        delay($ms / 1000);

        return new Pong('slept', getmypid() ?: 0);
    }
}
