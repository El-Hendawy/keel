<?php

declare(strict_types=1);

namespace Keel\Ledger;

use Keel\Http\Exception\HttpException;

/** An idempotency key was reused for a different transaction. Rendered as 409. */
final class LedgerConflict extends HttpException
{
    public function __construct(string $detail)
    {
        parent::__construct(409, $detail, 'Conflict', extensions: ['code' => 'idempotency_key_reused']);
    }
}
