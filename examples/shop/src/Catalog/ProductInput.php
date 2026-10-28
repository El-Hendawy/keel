<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Validation\Length;
use Keel\Validation\Range;

final readonly class ProductInput
{
    public function __construct(
        #[Length(min: 1, max: 64)] public string $sku,
        #[Length(min: 1, max: 200)] public string $name,
        #[Range(min: 0)] public int $priceCents,
    ) {
    }
}
