<?php

declare(strict_types=1);

namespace Keel\Tenancy;

use Keel\Container\Attribute\Scoped;
use Keel\Http\Exception\Forbidden;

/**
 * The tenant of the current scope. Set from the authenticated principal (HTTP) or the
 * job's recorded tenant (queue). With `database.tenant_tables` configured, the Database
 * refuses statements on those tables that do not filter by this tenant.
 */
#[Scoped]
final class TenantContext
{
    private ?string $id = null;

    private int $crossTenant = 0;

    public function set(string $tenantId): void
    {
        if ($this->id !== null && $this->id !== $tenantId) {
            throw new \LogicException('The tenant of a scope cannot change once set.');
        }
        $this->id = $tenantId;
    }

    public function current(): ?string
    {
        return $this->id;
    }

    /** The current tenant id; 403 when the scope has none (an unauthenticated or tenantless principal). */
    public function id(): string
    {
        return $this->id ?? throw new Forbidden('This operation needs a tenant, and the credential does not belong to one.');
    }

    /**
     * Unsafe by name: runs $work with the tenant guard lifted, for deliberate cross-tenant
     * work such as reporting or maintenance jobs. Every caller is a place to review.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function acrossTenants(\Closure $work): mixed
    {
        $this->crossTenant++;
        try {
            return $work();
        } finally {
            $this->crossTenant--;
        }
    }

    public function isCrossTenant(): bool
    {
        return $this->crossTenant > 0;
    }
}
