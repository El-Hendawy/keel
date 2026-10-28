<?php

declare(strict_types=1);

namespace Keel\Money;

/** Two amounts in different currencies were combined. */
final class CurrencyMismatch extends \InvalidArgumentException
{
}
