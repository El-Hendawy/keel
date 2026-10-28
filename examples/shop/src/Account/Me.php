<?php

declare(strict_types=1);

namespace Shop\Account;

use Keel\Auth\Principal;
use Keel\Routing\Attribute\Get;

/** Who the current credential belongs to. */
#[Get('/me', name: 'account.me')]
final class Me
{
    public function __invoke(Principal $principal): MeResource
    {
        return new MeResource($principal->id, $principal->tenantId, $principal->scopes, $principal->kind);
    }
}
