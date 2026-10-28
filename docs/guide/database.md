# Database

Enable it in `app.php` with `->withDatabase()` and configure:

```php
'database' => [
    'dsn' => getenv('DATABASE_DSN'),            // primary
    'replica_dsn' => getenv('DATABASE_REPLICA_DSN') ?: '',
    'username' => getenv('DATABASE_USER') ?: '',
    'password' => getenv('DATABASE_PASSWORD') ?: '',
    'timeout_ms' => 5000,                       // statement timeout per session
    'max_rows' => 10000,                        // result-size cap
    'n_plus_one_threshold' => 10,
],
```

## Queries

```php
$rows = $db->select('SELECT * FROM orders WHERE tenant_id = ? AND status = ?', [$tenant, 'paid']);
$row  = $db->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => $id]);
$n    = $db->execute('UPDATE orders SET status = ? WHERE id = ?', ['paid', $id]);
$rows = $db->select('SELECT * FROM order_items WHERE order_id IN (:ids)', ['ids' => $orderIds]);
```

- SQL must be a `literal-string` (PHPStan enforces it): values go in parameters, never into the statement.
  `rawQuery()` is the named escape hatch for genuinely dynamic SQL.
- A named list parameter expands to one placeholder per item, so `IN (...)` stays literal.
- Results are `Row` objects with typed accessors: `$row->string('id')`, `->int()`, `->nullableString()`,
  `->dateTime()`. A wrong type or a missing column throws immediately.

## Transactions

```php
$db->transaction(function (Database $db) use ($order): void {
    $db->execute('INSERT ...', [...]);
    $events->dispatch(new OrderPlaced(...));   // listeners share the transaction
});
```

Nested calls use savepoints. A transaction left open when the request ends is rolled back.

## Read/write splitting

With `replica_dsn` set, reads go to the replica until the scope writes; from then on they use the primary,
so a request always reads its own writes.

Each worker caches prepared statements per connection (`database.statement_cache`, default 256), so a
repeated query skips the prepare round trip on PostgreSQL and MySQL.

## Tenant isolation

With `database.tenant_tables` set, statements on those tables must filter by the current tenant or they
throw. See [tenancy](tenancy.md).

## N+1 detection

The same statement executed more than `n_plus_one_threshold` times in one scope throws
`NPlusOneDetected` in tests and logs a warning elsewhere. Load related rows in one query (see
`Orders::hydrate()` in the example).

## Migrations

`keel migrate` applies the framework's migrations for the features you enabled (`keel_tokens` for auth,
`keel_jobs` and `keel_locks` for the queue), then `migrations/*.sql`, in filename order, once each, recording
them in `keel_migrations`. `keel make:migration "add refunds"` creates a timestamped file.
Forward-only: to undo, write a new migration. Run it as a release step before new workers start.
