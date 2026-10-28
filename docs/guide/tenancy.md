# Multi-tenancy

A SaaS bug that leaks one customer's data to another is the worst bug you can ship. Keel makes the tenant
explicit and checks it where the data is read.

## Where the tenant comes from

`TenantContext` holds the tenant of the current scope:

- **HTTP:** from the authenticated principal (`Principal::$tenantId`, set from the token or the JWT's
  `auth.jwt.tenant_claim`). Never from a header or body field the client controls.
- **Jobs:** the tenant that was current when the job was pushed is recorded with the job and restored in the
  worker.

```php
#[Scoped]
final readonly class Orders
{
    public function __construct(private Database $db, private TenantContext $tenant) {}

    public function find(string $id): ?Order
    {
        $row = $this->db->selectOne('SELECT * FROM orders WHERE id = ? AND tenant_id = ?', [$id, $this->tenant->id()]);
        // ...
    }
}
```

`id()` throws 403 when the scope has no tenant; `current()` returns null instead.

## The tenant guard

List the tables that belong to tenants:

```php
// config.php
'database' => [
    'tenant_tables' => ['orders', 'invoices'],
    'tenant_column' => 'tenant_id',   // the default
],
```

Every statement that reads or writes one of those tables must:

1. name the tenant column, and
2. bind the current tenant's id as one of its parameters.

Otherwise the `Database` throws `TenantViolation` before the statement runs:

```
This statement uses tenant-owned table(s) orders without filtering by tenant_id. Add `tenant_id = ?`
with the current tenant. SQL: SELECT * FROM orders WHERE id = ?
```

That catches the forgotten `WHERE tenant_id = ?` and the filter bound to the wrong value. Each distinct SQL
string is analysed once per worker, so the cost per query is a hash lookup. `TenantViolation` is a logic
error: it is never caught for you, and it fails tests.

The guard reads the SQL text; it is a guard rail, not a SQL parser. A tenant table reached through a view or
a function is not seen.

### Cross-tenant work

Reports, maintenance jobs and admin tools sometimes need every tenant. That is unsafe by name:

```php
$count = $tenant->acrossTenants(fn () => $db->selectOne('SELECT COUNT(*) AS n FROM orders')?->int('n'));
```

Every `acrossTenants()` call is a place to review.

## Row-level security on PostgreSQL

For defence in depth, let the database enforce the same rule. With `database.tenant_rls = true`, Keel sets
`keel.tenant_id` on the connection for each scope with a tenant and clears it when the scope ends:

```sql
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON orders
    USING (tenant_id = current_setting('keel.tenant_id', true))
    WITH CHECK (tenant_id = current_setting('keel.tenant_id', true));
```

Run the application as a role that does not own the tables (owners bypass RLS unless `FORCE` is set), and give
cross-tenant jobs a separate role with `BYPASSRLS`.
