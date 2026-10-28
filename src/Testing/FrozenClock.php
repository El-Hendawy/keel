<?php

declare(strict_types=1);

namespace Keel\Testing;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Psr\Clock\ClockInterface;

/** The test clock: time only moves when a test moves it. */
#[Singleton]
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(#[Config('testing.now')] string $now = '2026-01-01T00:00:00+00:00')
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable|string $now): void
    {
        $this->now = \is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
