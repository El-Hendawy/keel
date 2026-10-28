<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Misconfigured;

use Keel\Auth\Attribute\Authorize;
use Keel\Auth\Principal;
use Keel\Routing\Attribute\Get;

#[Get('/secret')]
#[Authorize(scopes: ['admin'])]
final class SecretEndpoint
{
    public function __invoke(Principal $principal): void
    {
    }
}
