<?php

declare(strict_types=1);

namespace Keel\Testing;

use Keel\Container\Attribute\Singleton;
use Keel\Support\IdGenerator;

/** UUID-shaped ids that count up: 00000000-0000-7000-8000-000000000001, ...2, ... */
#[Singleton]
final class SequentialIdGenerator implements IdGenerator
{
    private int $next = 1;

    public function generate(): string
    {
        return \sprintf('00000000-0000-7000-8000-%012d', $this->next++);
    }

    public function reset(): void
    {
        $this->next = 1;
    }
}
