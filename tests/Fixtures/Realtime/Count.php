<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Routing\Attribute\Post;

#[Post('/count')]
final class Count
{
    public function __invoke(Counter $counter): Pong
    {
        return new Pong((string) ++$counter->value, getmypid() ?: 0);
    }
}
