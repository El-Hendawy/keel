<?php

declare(strict_types=1);

namespace Keel\Tenancy;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;

/**
 * Checks that SQL touching a tenant-owned table is filtered by the current tenant.
 *
 * A statement that reads or writes a table listed in `database.tenant_tables` must name
 * the tenant column (`database.tenant_column`, default `tenant_id`) and bind the current
 * tenant id as one of its parameters. That catches the two classic leaks: a forgotten
 * `WHERE tenant_id = ?` and a filter bound to the wrong value. Analysis of each distinct
 * statement is cached for the life of the worker, so the hot path is a hash lookup.
 *
 * This is a guard rail, not a parser: on PostgreSQL, also enable row-level security with
 * `database.tenant_rls` (see docs/guide/tenancy.md) for defence in depth.
 */
#[Singleton]
final class TenantGuard
{
    /** @var array<string, list<string>> sql => tenant tables it touches */
    private array $analysed = [];

    /** @param list<string> $tables */
    public function __construct(
        #[Config('database.tenant_tables')] private readonly array $tables = [],
        #[Config('database.tenant_column')] private readonly string $column = 'tenant_id',
    ) {
    }

    public function enabled(): bool
    {
        return $this->tables !== [];
    }

    /** @param array<int|string, mixed> $params */
    public function check(string $sql, array $params, TenantContext $tenant): void
    {
        $touched = $this->analysed[$sql] ??= $this->tablesIn($sql);
        if ($touched === [] || $tenant->isCrossTenant()) {
            return;
        }
        $table = implode(', ', $touched);
        $id = $tenant->current();
        if ($id === null) {
            throw new TenantViolation("This statement uses tenant-owned table(s) $table, but the scope has no tenant. Authenticate with a tenant-bound credential, or wrap deliberate cross-tenant work in TenantContext::acrossTenants(). SQL: $sql");
        }
        if (preg_match('/\b' . preg_quote($this->column, '/') . '\b/i', $sql) !== 1) {
            throw new TenantViolation("This statement uses tenant-owned table(s) $table without filtering by {$this->column}. Add `{$this->column} = ?` with the current tenant. SQL: $sql");
        }
        foreach ($params as $value) {
            if ($value === $id || (\is_array($value) && \in_array($id, $value, true))) {
                return;
            }
        }

        throw new TenantViolation("This statement filters tenant-owned table(s) $table by {$this->column}, but the current tenant is not among its parameters. Bind the current tenant id (TenantContext::id()). SQL: $sql");
    }

    /** @return list<string> */
    private function tablesIn(string $sql): array
    {
        preg_match_all('/\b(?:from|join|update|into|delete\s+from)\s+["`]?(\w+)["`]?/i', $sql, $matches);
        $names = array_map(strtolower(...), $matches[1]);

        return array_values(array_intersect(array_map(strtolower(...), $this->tables), $names));
    }
}
