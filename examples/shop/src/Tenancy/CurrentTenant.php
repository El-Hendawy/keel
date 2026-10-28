<?php

declare(strict_types=1);

namespace Shop\Tenancy;

use Keel\Container\Attribute\Scoped;
use Keel\Tenancy\TenantContext;

/**
 * The tenant of the current request, taken from the authenticated principal's token,
 * never from anything else the client sends. Scoped: one per request, never shared.
 */
#[Scoped]
final readonly class CurrentTenant
{
    public string $id;

    public function __construct(TenantContext $tenant)
    {
        $this->id = $tenant->id();
    }
}
