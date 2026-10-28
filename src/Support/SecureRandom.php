<?php

declare(strict_types=1);

namespace Keel\Support;

use Keel\Container\Attribute\Singleton;

#[Singleton]
final class SecureRandom implements Random
{
    public function bytes(int $length): string
    {
        return random_bytes(max(1, $length));
    }

    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
