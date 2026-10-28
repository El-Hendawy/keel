<?php

declare(strict_types=1);

namespace Keel\Ledger;

use Keel\Money\Money;

/** One leg of a ledger transaction: a signed amount on an account (debits positive). */
final readonly class Entry
{
    public function __construct(public string $account, public Money $amount)
    {
        if ($account === '' || \strlen($account) > 255) {
            throw new \InvalidArgumentException('A ledger account name is 1 to 255 characters, e.g. "assets:bank:eur".');
        }
    }

    public static function debit(string $account, Money $amount): self
    {
        return new self($account, $amount->abs());
    }

    public static function credit(string $account, Money $amount): self
    {
        return new self($account, $amount->abs()->negate());
    }
}
