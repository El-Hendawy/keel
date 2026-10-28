<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Fintech;

use Keel\Money\Money;
use Keel\Validation\Bic;
use Keel\Validation\Iban;

final readonly class TopUpInput
{
    public function __construct(
        public string $wallet,
        public Money $amount,
        #[Iban] public string $fromIban,
        #[Bic] public ?string $bic = null,
    ) {
    }
}
