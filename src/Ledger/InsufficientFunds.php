<?php

declare(strict_types=1);

namespace Keel\Ledger;

use Keel\Http\Exception\HttpException;

/** A posting would take a limited account past zero. Rendered as 422; nothing was posted. */
final class InsufficientFunds extends HttpException
{
    public function __construct(string $detail)
    {
        parent::__construct(422, $detail, 'Insufficient funds', extensions: ['code' => 'insufficient_funds']);
    }
}
