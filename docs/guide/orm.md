# The ORM

Keel's ORM is a **compiled data mapper**. Entities are plain classes, mappings are attributes, and the
compiler turns them into generated hydrators and SQL metadata at build time. It works on every database PDO
can reach.

```php
// app.php: entities need the database layer
->withDatabase()
```

## Why not Eloquent

| | Eloquent (active record) | Keel ORM (data mapper) |
| --- | --- | --- |
| Entities | Extend `Model`; attributes are magic `__get` | Plain classes with typed, public properties; PHPStan sees everything |
| Mapping | Discovered at runtime | Validated and compiled at build time; a wrong relation or type is a build error |
| Relations | Lazy by default: `$order->lines` silently runs a query (N+1) | Never lazy: an unloaded relation throws `RelationNotLoaded`; `with()` loads one query per relation |
| Same row twice | Two objects | Identity map: one object per row per request |
| Saving | Saves all attributes or tracks dirty via magic | Writes only the columns that changed, compared to what was read |
| Concurrency | Last write wins | `#[Version]` optimistic locking: a lost update is a `StaleEntity` (409) |
| Multi-tenancy | Global scopes you must remember to add | `#[TenantKey]`: queries are filtered and inserts stamped automatically; writing another tenant's row is a `TenantViolation` |
| Column names in queries | Strings that can reach SQL | Property names checked against the compiled metadata; values always bound |
| Pagination | Offset (slow on deep pages) | Signed keyset cursors: `cursorPaginate()` |
| Large scans | `chunk()` | `lazy()` streams with flat memory (no identity map) |
| Upserts | `upsert()` on some drivers | `upsert()` on every dialect: `ON CONFLICT`, `ON DUPLICATE KEY` or `MERGE` |
| Schema | Hand-written migrations | `keel orm:schema` and a forward-only `keel orm:diff --write` |

## Entities

```php
#[Entity('posts')]
#[Index(['tenantId', 'createdAt'])]
final class Post
{
    /**
     * @param One<Author> $author
     * @param Many<Comment> $comments
     * @param Many<Tag> $tags
     */
    public function __construct(
        public string $authorId,
        public string $title,
        #[Column(type: 'text')] public string $body = '',
        public PostStatus $status = PostStatus::Draft,                     // backed enum
        public bool $featured = false,
        #[Column(type: 'decimal', precision: 10, scale: 2)] public string $price = '0',
        public array $meta = [],                                           // JSON
        #[Id(generated: 'auto')] public ?int $id = null,
        #[TenantKey] public string $tenantId = '',
        #[Version] public int $version = 0,
        #[CreatedAt] public ?\DateTimeImmutable $createdAt = null,
        #[UpdatedAt] public ?\DateTimeImmutable $updatedAt = null,
        #[SoftDelete] public ?\DateTimeImmutable $deletedAt = null,
        #[BelongsTo(Author::class, foreignKey: 'authorId')] public One $author = new One(),
        #[HasMany(Comment::class, foreignKey: 'postId', orderBy: 'position')] public Many $comments = new Many(),
        #[BelongsToMany(Tag::class, pivot: 'post_tags', localColumn: 'post_id', relatedColumn: 'tag_id')] public Many $tags = new Many(),
    ) {}
}
```

- **Columns** are the constructor-promoted properties; names become snake_case (`authorId` → `author_id`)
  unless `#[Column(name: ...)]` says otherwise.
- **Types:** `string`, `int`, `float`, `bool`, `DateTimeImmutable` (stored in UTC), backed enums, `array`
  (JSON, read back as `array<mixed>`), `#[Column(type: 'text')]`, and `#[Column(type: 'decimal')]` held in a
  string so money never meets floating point.
- **Ids:** `#[Id]` is a UUIDv7 assigned on insert when empty (or pass `$orm->newId()` for readonly ids);
  `generated: 'auto'` is a database sequence read back after insert; `generated: 'none'` means you always set
  it.
- Entities can be `readonly` when nothing in them is managed by the ORM (see `Tag` in the tests).

The compiler rejects, with the class and the fix: no `#[Id]`, two entities on one table, unsupported types, a
relation that is not a `Many`/`One` with a default, a relation to a non-entity, a foreign key that is not a
column, a readonly `#[Version]` or timestamp, and entities without `->withDatabase()`.

## Reading

```php
$orm->find(Post::class, 42);            // ?Post, from the identity map when already loaded
$orm->get(Post::class, 42);             // Post, or 404

$posts = $orm->query(Post::class)
    ->where('status', PostStatus::Published)
    ->where('price', '>=', '10.00')
    ->where(fn (Criteria $c) => $c->where('featured', true)->orWhere('title', 'like', 'Keel%'))
    ->whereIn('authorId', $ids)
    ->whereHas('comments', fn (Criteria $c) => $c->where('body', 'like', '%thanks%'))
    ->with('author', 'comments.author', 'tags')
    ->orderBy('createdAt', 'desc')
    ->limit(20)
    ->get();

$query->count();  $query->exists();  $query->sum('price');  $query->avg('rating');  $query->pluck('title');
$query->first();  $query->firstOrFail();  $query->lockForUpdate()->get();
```

Operators: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `like`, `not like`, plus `whereIn`, `whereNotIn`, `whereNull`,
`whereNotNull`, `whereBetween`, `whereHas` and `whereDoesntHave` (EXISTS subqueries, so rows never repeat).
An unknown property name throws, listing the valid ones.

### Relations are never lazy

```php
$post = $orm->get(Post::class, 42);
$post->comments->all();               // RelationNotLoaded: say what you need

$orm->load($post, 'comments.author'); // or ->with(...) on the query
foreach ($post->comments as $comment) { echo $comment->author->get()?->name; }
```

Each relation level is one query using `IN (...)` (split into chunks below each database's parameter limit),
whatever the number of rows. Related queries apply the same tenant and soft-delete rules.

### Pagination and large result sets

```php
$page = $orm->query(Product::class)->orderBy('name')->cursorPaginate(20, $cursor);
return new ProductList(array_map(ProductResource::from(...), $page->items), $page->page);

foreach ($orm->query(Post::class)->lazy(500) as $post) { /* flat memory */ }
```

Cursors are signed and bound to the entity and the sort order: a cursor from another list, or a forged one, is
a 400. The id is added as a tie-breaker so the order is total.

## Writing

```php
$post = new Post($author->id, 'Hello');
$orm->save($post);                  // INSERT: id, tenant, timestamps and version are filled in

$post->title = 'Hello, world';
$orm->save($post);                  // UPDATE posts SET title = ?, updated_at = ?, version = ? WHERE id = ? AND tenant_id = ? AND version = ?

$orm->saveAll($newComments);        // multi-row INSERT per batch
$orm->delete($post);                // soft delete (sets deletedAt)
$orm->restore($post);
$orm->delete($post, force: true);   // DELETE
$orm->upsert($tags, uniqueBy: ['name']);
$orm->attach($post, 'tags', ['t1', 't2']);
$orm->detach($post, 'tags', ['t2']);

$orm->transaction(function (Orm $orm) { /* ... */ });

// Bulk, without loading (no versions, no identity map; tenant and soft-delete filters still apply)
$orm->query(Post::class)->where('status', PostStatus::Draft)->update(['status' => PostStatus::Published]);
$orm->query(Post::class)->where('createdAt', '<', $cutoff)->delete();
```

- **Optimistic locking:** with `#[Version]`, an update whose version no longer matches the row throws
  `StaleEntity`, which renders as 409. Reload and retry, or show the conflict to the user.
- **Tenancy:** with `#[TenantKey]`, every query filters by the current tenant, inserts take the current tenant,
  and saving an entity of another tenant throws `TenantViolation`. `->unsafeAcrossTenants()` on a query and
  `TenantContext::acrossTenants()` are the named exceptions. ORM statements satisfy the tenant guard.
- The identity map lives for the scope (one request or job). `$orm->clear()` empties it in long jobs.

## Checked by PHPStan

Keel ships a PHPStan extension (`extension.neon`; registered automatically with
`phpstan/extension-installer`, or `includes: [vendor/el-hendawy/keel/extension.neon]`). It checks ORM queries
during analysis, so mistakes fail in CI instead of at runtime:

```php
$orm->query(Post::class)->where('titel', 'x');          // Post has no column property "titel". Columns: ...
$orm->query(Post::class)->where('status', 'published'); // Post::$status expects PostStatus, 'published' given
$orm->query(Post::class)->where('rating', '=>', 3);     // Unsupported operator "=>"
$orm->query(Post::class)->with('comments.writer');      // Comment has no relation "writer"
$orm->query(Post::class)->update(['title' => 42]);      // Post::$title expects string, 42 given
```

It checks column names in `where*`, `orderBy`, `pluck` and aggregates, value types against the property's
declared type, operators, relation paths in `with`/`whereHas`, and `update()` keys and values. Names built
at runtime are still checked when the query runs.

## Databases

| Driver | Dialect | Tested here |
| --- | --- | --- |
| `pdo_sqlite` | SQLite 3.35+ | Yes: full suite |
| `pdo_pgsql` | PostgreSQL 12+ (also CockroachDB via `database.dialect = 'cockroach'`) | Yes: full suite, every CI run |
| `pdo_mysql` | MySQL 8, MariaDB 10.5+ | Yes: full suite on MySQL, every CI run |
| `pdo_sqlsrv`, `pdo_dblib` | SQL Server 2012+, Azure SQL | Generated SQL is unit-tested; not yet run against a server |
| `pdo_oci` | Oracle 12c+ | Generated SQL is unit-tested; not yet run against a server. Use UUID ids: auto-increment ids cannot be read back through PDO |
| `pdo_firebird`, `pdo_ibm`, `pdo_odbc`, others | Standard SQL:2008 (`AnsiDialect`) | Generated SQL is unit-tested |

Set `database.dialect` (`sqlite`, `pgsql`, `mysql`, `sqlsrv`, `oci`, `ansi`) when the driver does not say which
database is behind it, as with ODBC.

What differs per dialect: identifier quoting, `LIMIT`/`OFFSET` versus `OFFSET … FETCH`, booleans, upserts,
how generated ids are read back (`RETURNING`, `OUTPUT INSERTED`, `lastInsertId()`), column types, and the
catalog queries used by `orm:diff`.

## Schema

```bash
keel orm:schema               # CREATE TABLE and CREATE INDEX for this database's dialect
keel orm:diff                 # what the database lacks: new tables, new columns, their indexes
keel orm:diff --write         # the same, as a migration file to review and commit
```

The diff is **forward-only**: columns or tables that exist only in the database are listed as comments, never
dropped. A new `NOT NULL` column gets a default so it can be added to a table that has rows. Review generated
migrations before applying them; they use the connected database's dialect, so an application that supports
several databases should keep its migrations portable by hand (see `examples/shop/migrations/004_products.sql`).

## Performance notes

- Hydration is generated code calling constructors with named arguments: no reflection, no `__get`.
- Statements are cached per connection (`database.statement_cache`), and every query is traced and counted
  by the N+1 detector.
- Use `saveAll()` for many inserts and `lazy()` for large scans.
