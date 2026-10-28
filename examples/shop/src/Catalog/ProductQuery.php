<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Validation\Range;

final readonly class ProductQuery
{
    public function __construct(
        #[Range(min: 1, max: 100)] public int $limit = 20,
        public ?string $cursor = null,
        public ?string $search = null,
    ) {
    }
}
