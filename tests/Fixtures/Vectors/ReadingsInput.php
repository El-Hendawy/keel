<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Vectors;

use Keel\Validation\Count;
use Keel\Vector\FloatVector;
use Keel\Vector\IntVector;

final readonly class ReadingsInput
{
    public function __construct(
        #[Count(min: 1, max: 1000)] public FloatVector $values,
        public float $threshold,
        public ?IntVector $weights = null,
    ) {
    }
}
