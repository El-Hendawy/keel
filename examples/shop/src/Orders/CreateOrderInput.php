<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Validation\Count;
use Keel\Validation\Length;
use Keel\Validation\Uuid;

final readonly class CreateOrderInput
{
    public function __construct(
        #[Uuid] public string $merchantId,
        /** @var list<LineItem> */
        #[Count(min: 1, max: 100)] public array $items,
        #[Length(max: 500)] public ?string $note = null,
    ) {
    }
}
