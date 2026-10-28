<?php

declare(strict_types=1);

namespace Keel\Support;

/** Randomness as a dependency: cryptographically secure in production, seeded in tests. */
interface Random
{
    public function bytes(int $length): string;

    public function int(int $min, int $max): int;
}
