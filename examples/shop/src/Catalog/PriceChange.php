<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Validation\Range;

final readonly class PriceChange
{
    public function __construct(
        #[Range(min: 0)] public int $priceCents,
        public int $version,
    ) {
    }
}
