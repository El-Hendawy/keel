<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Fintech;

use Keel\Money\Money;

final readonly class TopUpResult
{
    public function __construct(public string $transaction, public Money $balance)
    {
    }
}
