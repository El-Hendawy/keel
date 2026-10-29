<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Vectors;

use Keel\Vector\FloatVector;
use Keel\Vector\IntVector;

final readonly class ReadingsStats
{
    public function __construct(
        public float $mean,
        public IntVector $above,
        public FloatVector $sorted,
        public ?int $weightSum,
        public ?IntVector $weights,
    ) {
    }
}
