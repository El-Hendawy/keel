<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Vectors;

use Keel\Routing\Attribute\Post;

#[Post('/readings/summary')]
final class SummarizeReadings
{
    public function __invoke(ReadingsInput $in): ReadingsStats
    {
        return new ReadingsStats(
            $in->values->mean(),
            $in->values->whereGreaterThan($in->threshold),
            $in->values->sort(),
            $in->weights?->sum(),
            $in->weights,
        );
    }
}
