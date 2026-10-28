<?php

declare(strict_types=1);

namespace Keel\Support;

use Keel\Container\Attribute\Singleton;
use Psr\Clock\ClockInterface;

#[Singleton]
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
