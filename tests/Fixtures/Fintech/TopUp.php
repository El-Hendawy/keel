<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Fintech;

use Keel\Ledger\Entry;
use Keel\Ledger\Ledger;
use Keel\Routing\Attribute\Post;

#[Post('/wallets/top-ups')]
final class TopUp
{
    public function __invoke(TopUpInput $in, Ledger $ledger): TopUpResult
    {
        $account = "liabilities:wallets:{$in->wallet}";
        $id = $ledger->post([
            Entry::debit('assets:bank', $in->amount),
            Entry::credit($account, $in->amount),
        ], "Top-up from {$in->fromIban}");

        return new TopUpResult($id, $ledger->balance($account, $in->amount->currency)->negate());
    }
}
