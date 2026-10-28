<?php

declare(strict_types=1);

namespace Keel\Ledger;

/** Debits and credits of a transaction differ in some currency, or it has fewer than two legs. */
final class UnbalancedTransaction extends \InvalidArgumentException
{
}
