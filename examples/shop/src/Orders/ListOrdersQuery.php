<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Validation\Range;

final readonly class ListOrdersQuery
{
    public function __construct(
        #[Range(min: 1, max: 100)] public int $limit = 20,
        public ?OrderStatus $status = null,
    ) {
    }
}
