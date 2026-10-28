<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Validation\Pattern;

final readonly class SummaryQuery
{
    public function __construct(
        #[Pattern('/^[A-Z]{3}$/', 'Must be an ISO 4217 currency code, e.g. USD.')] public string $currency = 'EUR',
    ) {
    }
}
