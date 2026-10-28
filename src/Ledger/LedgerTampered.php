<?php

declare(strict_types=1);

namespace Keel\Ledger;

/** Ledger::verify() found a transaction that does not match the hash chain. */
final class LedgerTampered extends \RuntimeException
{
}
