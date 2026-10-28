<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

final readonly class Pong
{
    public function __construct(public string $message, public int $pid)
    {
    }
}
