<?php

declare(strict_types=1);

namespace Shop\Account;

use Keel\Auth\Principal;
use Keel\Auth\Token\Tokens;
use Keel\Routing\Attribute\Delete;

/** Sign out: revoke the token used for this request. */
#[Delete('/tokens/current', name: 'account.logout')]
final class RevokeCurrentToken
{
    public function __invoke(Principal $principal, Tokens $tokens): void
    {
        if ($principal->tokenId !== null) {
            $tokens->revoke($principal->tokenId);
        }
    }
}
