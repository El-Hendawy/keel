<?php

declare(strict_types=1);

namespace Keel\Testing;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Support\Random;

/** Deterministic randomness: the same seed gives the same sequence on every run. */
#[Singleton]
final class SeededRandom implements Random
{
    private \Random\Randomizer $randomizer;

    public function __construct(#[Config('testing.seed')] int $seed = 42)
    {
        $this->randomizer = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar($seed));
    }

    public function bytes(int $length): string
    {
        return $this->randomizer->getBytes(max(1, $length));
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }
}
