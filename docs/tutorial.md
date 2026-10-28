# Tutorial: a multi-tenant notes API

In this tutorial you build a small but production-shaped API: teams keep notes, every request is
authenticated, each team only ever sees its own notes, input is validated, lists are paginated, and the whole
thing is tested. Every file below was run and tested exactly as shown.

You need PHP 8.3+, Composer, and the `pdo_sqlite` extension (bundled with most PHP builds).

## 1. Create the project

Keel is not on Packagist yet, so its command line comes from a checkout:

```bash
git clone https://github.com/El-Hendawy/keel.git
cd keel && composer install
bin/keel new ../notes
cd ../notes && composer install
```

The new project installs `keel/framework` from GitHub and contains:

| File | Purpose |
| --- | --- |
| `app.php` | The application's shape, read at build time: what to scan, which features are on |
| `config.php` | Runtime values (DSNs, secrets), read once per worker at boot |
| `src/Greeting/` | A module with one endpoint |
| `tests/SayHelloTest.php` | An in-process test |
| `public/index.php`, `Caddyfile` | The worker entry point and a FrankenPHP config |
| `AGENTS.md` | Rules for coding agents working on the project |

Start the development server and try it:

```bash
vendor/bin/keel serve
curl localhost:8000/hello/world
# {"message":"Hello, world!","at":"2026-10-28T19:54:16.123+00:00"}
```

The development server rebuilds the application whenever a source file changes.

## 2. Turn on the database, auth and TOON

Edit `app.php`:

```php title="app.php"
<?php

declare(strict_types=1);

use Keel\Runtime\AppDefinition;

return AppDefinition::create(__DIR__, 'notes')
    ->scan('src')
    ->config('config.php')
    ->withDatabase()
    ->withAuth()     // deny by default: every endpoint needs a token unless marked #[Anonymous]
    ->withToon();    // clients may ask for TOON instead of JSON
```

And `config.php`:

```php title="config.php"
<?php

declare(strict_types=1);

/** @var Keel\Runtime\Environment $env */
return [
    'app' => ['key' => getenv('APP_KEY') ?: ''],
    'database' => [
        'dsn' => getenv('DATABASE_DSN') ?: 'sqlite:' . __DIR__ . '/var/notes.sqlite',
        // Statements on these tables must filter by the current tenant, or they fail.
        'tenant_tables' => ['notes'],
    ],
    'log' => ['level' => $env->isTest() ? 'warning' : 'info'],
    'metrics' => ['token' => getenv('METRICS_TOKEN') ?: ''],
];
```

`curl localhost:8000/hello/world` now answers **401**: with auth on, nothing is public by accident. Keep the
greeting public by saying so:

```php title="src/Greeting/SayHello.php" hl_lines="2"
#[Get('/hello/{name}', name: 'greeting.hello')]
#[Anonymous]
final class SayHello
```

(with `use Keel\Auth\Attribute\Anonymous;`).

## 3. The entity

A note belongs to a team (the tenant). Create a module and the entity:

```php title="src/Notes/NotesModule.php"
<?php

declare(strict_types=1);

namespace App\Notes;

use Keel\Module\Module;

#[Module(name: 'notes')]
final class NotesModule
{
}
```

```php title="src/Notes/Note.php"
<?php

declare(strict_types=1);

namespace App\Notes;

use Keel\Orm\Attribute\Column;
use Keel\Orm\Attribute\CreatedAt;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\Id;
use Keel\Orm\Attribute\TenantKey;
use Keel\Orm\Attribute\Version;

#[Entity('notes')]
final class Note
{
    public function __construct(
        public string $title,
        #[Column(type: 'text')] public string $body,
        public bool $pinned = false,
        #[Id] public string $id = '',                          // a UUIDv7, assigned on insert
        #[TenantKey] public string $tenantId = '',             // filled from the token's tenant
        #[Version] public int $version = 0,                    // optimistic locking
        #[CreatedAt] public ?\DateTimeImmutable $createdAt = null,
    ) {
    }
}
```

Let Keel write the migration, then apply it (Keel's own tables for tokens come first):

```bash
vendor/bin/keel orm:diff --write     # writes migrations/<timestamp>_orm_sync.sql: review it
vendor/bin/keel migrate
```

## 4. Endpoints

An endpoint is one invokable class. Its parameters are bound by type: the input object from the body or
query string, services from the container.

```php title="src/Notes/NoteInput.php"
<?php

declare(strict_types=1);

namespace App\Notes;

use Keel\Validation\Length;

final readonly class NoteInput
{
    public function __construct(
        #[Length(min: 1, max: 120)] public string $title,
        #[Length(max: 10_000)] public string $body = '',
        public bool $pinned = false,
    ) {
    }
}
```

```php title="src/Notes/NoteResource.php"
<?php

declare(strict_types=1);

namespace App\Notes;

final readonly class NoteResource
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public bool $pinned,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function from(Note $note): self
    {
        return new self($note->id, $note->title, $note->body, $note->pinned, $note->createdAt ?? new \DateTimeImmutable());
    }
}
```

```php title="src/Notes/CreateNote.php"
<?php

declare(strict_types=1);

namespace App\Notes;

use Keel\Auth\Attribute\Authorize;
use Keel\Orm\Orm;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Status;

/** Create a note. */
#[Post('/notes', name: 'notes.create')]
#[Status(201)]
#[Authorize(scopes: ['notes:write'])]
final class CreateNote
{
    public function __invoke(NoteInput $in, Orm $orm): NoteResource
    {
        $note = new Note($in->title, $in->body, $in->pinned);
        $orm->save($note); // id, tenant, version and createdAt are filled in

        return NoteResource::from($note);
    }
}
```

Listing uses cursor pagination, which stays fast on deep pages and cannot skip or repeat rows:

```php title="src/Notes/ListNotesQuery.php"
<?php

declare(strict_types=1);

namespace App\Notes;

use Keel\Validation\Range;

final readonly class ListNotesQuery
{
    public function __construct(
        #[Range(min: 1, max: 100)] public int $limit = 20,
        public ?string $cursor = null,
    ) {
    }
}
```

```php title="src/Notes/NoteList.php"
<?php

declare(strict_types=1);

namespace App\Notes;

use Keel\Pagination\PageInfo;

final readonly class NoteList
{
    /** @param list<NoteResource> $data */
    public function __construct(public array $data, public PageInfo $page)
    {
    }
}
```

```php title="src/Notes/ListNotes.php"
<?php

declare(strict_types=1);

namespace App\Notes;

use Keel\Auth\Attribute\Authorize;
use Keel\Orm\Orm;
use Keel\Routing\Attribute\Get;

/** List notes, newest first. */
#[Get('/notes', name: 'notes.list')]
#[Authorize(scopes: ['notes:read'])]
final class ListNotes
{
    public function __invoke(ListNotesQuery $query, Orm $orm): NoteList
    {
        $page = $orm->query(Note::class)
            ->orderBy('createdAt', 'desc')
            ->cursorPaginate($query->limit, $query->cursor);

        return new NoteList(array_map(NoteResource::from(...), $page->items), $page->page);
    }
}
```

Notice what is missing: no tenant filter (the ORM adds it), no validation code (it is generated from the
attributes), no serialization code (generated from the output's public properties).

## 5. Try it

Issue a token for a user of team `team-1`, then call the API:

```bash
vendor/bin/keel token:issue --principal=ada --tenant=team-1 --scopes='notes:*'
TOKEN=keel_...   # the token it printed

curl -X POST localhost:8000/notes -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"title":"Ship it","body":"Friday"}'

curl localhost:8000/notes -H "Authorization: Bearer $TOKEN"
curl localhost:8000/notes -H "Authorization: Bearer $TOKEN" -H 'Accept: text/toon'
```

Things to try:

- `{"title": ""}` returns 422 with `errors[0].path = "title"`; an unknown field is rejected too.
- A token without `notes:write` gets 403 with the missing scope.
- The token is bound to the first client that used it (here, curl). The same token sent with
  `-A "Mozilla/5.0"` is refused **and revoked**: a stolen token gets one try.
- `vendor/bin/keel openapi` prints the OpenAPI document; `vendor/bin/keel sdk --out=web/api.ts` writes a typed
  TypeScript client.

## 6. Test it

Tests boot the application in-process, with a frozen clock, seeded randomness and fakes for outbound HTTP:

```php title="tests/NotesTest.php"
<?php

declare(strict_types=1);

namespace App\Tests;

use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;

final class NotesTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot(__DIR__ . '/../app.php', ['database' => ['dsn' => TestDatabase::dsn()]]);
        $this->app->freshDatabase(__DIR__ . '/../migrations');
    }

    public function testNotesBelongToTheirTenant(): void
    {
        $ada = $this->app->tokenFor('ada', 'team-1', ['notes:read', 'notes:write']);
        $bob = $this->app->tokenFor('bob', 'team-2', ['notes:read']);

        $this->app->post('/notes', ['title' => 'Ship it'], $ada)
            ->assertStatus(201)
            ->assertJsonPath('title', 'Ship it');

        $this->app->get('/notes', $ada)->assertJsonCount('data', 1);
        $this->app->get('/notes', $bob)->assertJsonCount('data', 0);
    }

    public function testInputIsValidatedAndEndpointsAreProtected(): void
    {
        $ada = $this->app->tokenFor('ada', 'team-1', ['notes:write']);

        $this->app->post('/notes', ['title' => ''], $ada)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.path', 'title');
        $this->app->post('/notes', ['title' => 'x'])->assertStatus(401);
        $this->app->get('/notes', $ada)->assertStatus(403);
    }
}
```

```bash
vendor/bin/phpunit             # OK (3 tests)
vendor/bin/phpstan analyse     # level max, no errors
```

`TestDatabase::dsn()` is an in-memory SQLite database unless `KEEL_TEST_DATABASE_DSN` points somewhere else,
so the same tests run on PostgreSQL or MySQL in CI.

## 7. Ship it

```bash
composer install --no-dev --classmap-authoritative
KEEL_ENV=prod vendor/bin/keel build --env=prod
KEEL_ENV=prod APP_KEY=... METRICS_TOKEN=... vendor/bin/keel check --env=prod
KEEL_ENV=prod vendor/bin/keel migrate
frankenphp run                 # the generated Caddyfile runs public/index.php in worker mode
```

`keel check` refuses to let a production worker start with debug on, without `app.key`, with stores that only
work on one node, or with a public `/metrics`. Each failure says what to change.

## Where to go next

- [Endpoints and types](guide/endpoints-and-types.md): constraints, enums, dates, query inputs.
- [The ORM](guide/orm.md): relations without N+1, `whereHas`, upserts, soft deletes, schema diffs.
- [Authentication](guide/auth.md): login endpoints, JWTs from your identity provider, policies.
- [Background work](guide/background-work.md): jobs, the outbox, async listeners, scheduled tasks, webhooks.
- [Production](guide/production.md): workers, Redis stores, load balancers, queue and scheduler processes.
