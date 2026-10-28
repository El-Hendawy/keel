<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Validation\Length;
use Keel\Validation\Range;

final readonly class LineItem
{
    public function __construct(
        /** Stock keeping unit. */
        #[Length(min: 1, max: 64)] public string $sku,
        #[Range(min: 1, max: 1000)] public int $quantity,
        /** Price of one unit, in cents. */
        #[Range(min: 0)] public int $unitPriceCents,
    ) {
    }
}
