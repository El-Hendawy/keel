# Caching and pagination

## Cache

```php
$rates = $cache->remember("fx:$currency", 300, fn () => $this->fetchRates($currency));
$cache->set('feature-flags', $flags, 60);
$cache->delete('feature-flags');
```

| Binding | When |
| --- | --- |
| `InMemoryCache` (default) | Per worker, LRU-bounded by `cache.max_items` (10,000). Each worker has its own copy, so use it for data that may be briefly stale per node. |
| `RedisCache` (`->withRedisStores()`) | Shared by every node; keys are prefixed with `app.name`. |

- **Values must be JSON-compatible** (null, scalars, arrays). Objects are refused: cache a DTO's data, not the
  object, so a cache entry can never instantiate a class when read.
- **Stampede protection.** `remember()` refreshes values early with a probability that grows as expiry nears
  (XFetch), so a popular key is recomputed by one request before it expires instead of by all of them after.
  With Redis, a missing key is recomputed by one worker while the others wait up to `cache.lock_wait_seconds`
  (default 2) for the result, using a non-blocking sleep under fibers.
- Cache keys are yours to scope: include the tenant id for tenant data (`"tenant:$id:plan"`).

## Cursor pagination

Offset pagination (`?page=500`) gets slower with every page and skips or repeats rows when data changes.
Keel's cursors are for keyset pagination: the cursor holds the sort key of the last row of a page.

```php
#[Get('/orders')]
final class ListOrders
{
    public function __invoke(ListOrdersQuery $query, Orders $orders, Cursors $cursors): OrderList
    {
        $after = $query->cursor === null ? null : $cursors->decode($query->cursor, 'orders.list');
        $page = $orders->latest($query->limit + 1, $after);       // one extra row tells us if there is more
        $hasMore = count($page) > $query->limit;
        $page = array_slice($page, 0, $query->limit);
        $last = end($page);
        $next = $hasMore ? $cursors->encode(['c' => $last->createdAt->format(DATE_RFC3339_EXTENDED), 'i' => $last->id], 'orders.list') : null;

        return new OrderList(array_map(OrderResource::from(...), $page), new PageInfo($next, $hasMore));
    }
}
```

```sql
SELECT * FROM orders
WHERE tenant_id = ? AND (created_at < ? OR (created_at = ? AND id < ?))
ORDER BY created_at DESC, id DESC
LIMIT ?
```

- Cursors are opaque to clients and **signed** with a key derived from `app.key`: a client cannot forge one to
  start at an arbitrary position. A tampered cursor is a 400.
- The second argument is a purpose label; a cursor from one list cannot be used on another.
- `PageInfo` (`nextCursor`, `hasMore`) is a normal output type, so it appears in OpenAPI and the SDK.
- Always order by a unique column last (here `id`) so the order is total.
