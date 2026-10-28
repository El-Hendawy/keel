<?php

declare(strict_types=1);

namespace Keel\Tests\Orm;

use Keel\Container\Scope;
use Keel\Database\ConnectionManager;
use Keel\Database\Database;
use Keel\Http\Exception\BadRequest;
use Keel\Orm\Criteria;
use Keel\Orm\Dialect\Dialects;
use Keel\Orm\EntityMap;
use Keel\Orm\Many;
use Keel\Orm\Orm;
use Keel\Orm\RelationNotLoaded;
use Keel\Orm\Schema;
use Keel\Orm\StaleEntity;
use Keel\Tenancy\TenantContext;
use Keel\Tenancy\TenantViolation;
use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use Keel\Tests\Fixtures\OrmBlog\Author;
use Keel\Tests\Fixtures\OrmBlog\Comment;
use Keel\Tests\Fixtures\OrmBlog\Post;
use Keel\Tests\Fixtures\OrmBlog\PostStatus;
use Keel\Tests\Fixtures\OrmBlog\Tag;
use PHPUnit\Framework\TestCase;

/** The ORM end to end on the configured database (SQLite by default; see TestDatabase). */
final class OrmTest extends TestCase
{
    private const string TENANT = 'tenant-a';

    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot(__DIR__ . '/../Fixtures/OrmBlog/app.php', [
            'database' => ['dsn' => TestDatabase::dsn(), 'tenant_tables' => ['authors', 'posts', 'comments']],
            'log' => ['stream' => 'php://memory'],
        ]);
        $pdo = $this->app->container()->get(ConnectionManager::class)->writer();
        TestDatabase::reset($pdo);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        foreach ((new Schema($this->app->container()->get(EntityMap::class), Dialects::for(\is_string($driver) ? $driver : '')))->create() as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @template R
     * @param \Closure(Orm, Scope): R $work
     * @return R
     */
    private function as(string $tenant, \Closure $work): mixed
    {
        return $this->app->inScope(static function (Scope $scope) use ($tenant, $work): mixed {
            $scope->get(TenantContext::class)->set($tenant);

            return $work($scope->get(Orm::class), $scope);
        });
    }

    /** @return array{0: Author, 1: Post} */
    private static function seed(Orm $orm, int $posts = 1): array
    {
        $author = new Author('Ada', 'ada-' . bin2hex(random_bytes(3)) . '@example.com');
        $orm->save($author);
        $last = null;
        for ($i = 0; $i < $posts; $i++) {
            $last = new Post($author->id, "Post $i", 'Body', $i % 2 === 0 ? PostStatus::Published : PostStatus::Draft, $i === 0, $i * 1.5, (string) (10 + $i) . '.25', ['n' => $i, 'tags' => ['x']]);
            $orm->save($last);
        }
        \assert($last instanceof Post);

        return [$author, $last];
    }

    public function testInsertFindAndTypes(): void
    {
        $this->as(self::TENANT, function (Orm $orm): void {
            [$author, $post] = self::seed($orm);
            self::assertNotSame('', $author->id, 'uuid ids are assigned on insert');
            self::assertSame(self::TENANT, $author->tenantId, 'the tenant is stamped');
            self::assertIsInt($post->id, 'auto-increment ids are read back');
            self::assertSame(1, $post->version);
            self::assertNotNull($post->createdAt);
            self::assertSame($post, $orm->find(Post::class, $post->id), 'one row, one object per scope');
        });
        $this->as(self::TENANT, function (Orm $orm): void {
            $post = $orm->query(Post::class)->firstOrFail();
            self::assertSame('Post 0', $post->title);
            self::assertSame(PostStatus::Published, $post->status);
            self::assertTrue($post->featured);
            self::assertSame(0.0, $post->rating);
            self::assertSame('10.25', $post->price, 'decimals stay exact strings');
            self::assertSame(['n' => 0, 'tags' => ['x']], $post->meta);
            self::assertInstanceOf(\DateTimeImmutable::class, $post->createdAt);
        });
    }

    public function testChangesAreTrackedAndVersionsPreventLostUpdates(): void
    {
        $id = $this->as(self::TENANT, static fn (Orm $orm): ?int => self::seed($orm)[1]->id);
        \assert(\is_int($id));
        $this->as(self::TENANT, function (Orm $orm, Scope $scope) use ($id): void {
            $post = $orm->get(Post::class, $id);
            $post->title = 'Renamed';
            $orm->save($post);
            self::assertSame(2, $post->version);
            $orm->save($post); // nothing changed: no statement, no version bump
            self::assertSame(2, $post->version);

            // Someone else updates the row meanwhile.
            $scope->get(Database::class)->executeBuilt('UPDATE posts SET version = 3 WHERE id = ? AND tenant_id = ?', [$id, self::TENANT]);
            $post->title = 'Mine';
            try {
                $orm->save($post);
                self::fail('Expected StaleEntity');
            } catch (StaleEntity $e) {
                self::assertSame(409, $e->status);
            }
        });
        $this->as(self::TENANT, static fn (Orm $orm) => self::assertSame('Renamed', $orm->get(Post::class, $id)->title));
    }

    public function testTenantsAreIsolatedAutomatically(): void
    {
        $id = $this->as(self::TENANT, static fn (Orm $orm): ?int => self::seed($orm, 3)[1]->id);
        \assert(\is_int($id));
        $this->as('tenant-b', function (Orm $orm) use ($id): void {
            self::assertNull($orm->find(Post::class, $id));
            self::assertSame(0, $orm->query(Post::class)->count());
            self::assertSame(3, $orm->query(Post::class)->unsafeAcrossTenants()->count());
            $foreign = new Post('x', 'Smuggled', tenantId: self::TENANT);
            $this->expectException(TenantViolation::class);
            $orm->save($foreign);
        });
    }

    public function testSoftDeletesAndRestore(): void
    {
        $this->as(self::TENANT, function (Orm $orm): void {
            [, $post] = self::seed($orm, 2);
            $orm->delete($post);
            self::assertNotNull($post->deletedAt);
            self::assertSame(1, $orm->query(Post::class)->count());
            self::assertSame(2, $orm->query(Post::class)->withTrashed()->count());
            self::assertSame(1, $orm->query(Post::class)->onlyTrashed()->count());
            $orm->restore($post);
            self::assertSame(2, $orm->query(Post::class)->count());
            $orm->delete($post, force: true);
            self::assertSame(1, $orm->query(Post::class)->withTrashed()->count());
        });
    }

    public function testRelationsAreEagerLoadedWithoutNPlusOne(): void
    {
        $this->as(self::TENANT, function (Orm $orm): void {
            [$author] = self::seed($orm, 15);
            $orm->saveAll([new Tag('t1', 'php'), new Tag('t2', 'sql')]);
            foreach ($orm->query(Post::class)->get() as $i => $post) {
                $orm->saveAll([
                    new Comment((int) $post->id, $author->id, 2, "second on $i"),
                    new Comment((int) $post->id, $author->id, 1, "first on $i"),
                ]);
                $orm->attach($post, 'tags', $i % 2 === 0 ? ['t1', 't2'] : ['t2']);
            }
            $orm->clear();

            // 15 posts, their authors, comments, the comments' authors and tags: 5 queries, not 61.
            $posts = $orm->query(Post::class)->with('author', 'comments.author', 'tags')->orderBy('id')->get();
            self::assertCount(15, $posts);
            self::assertSame('Ada', $posts[0]->author->get()?->name);
            self::assertSame(['first on 0', 'second on 0'], array_map(static fn (Comment $c): string => $c->body, $posts[0]->comments->all()));
            self::assertSame('Ada', $posts[0]->comments->all()[0]->author->get()?->name);
            self::assertSame(['php', 'sql'], array_map(static fn (Tag $t): string => $t->name, $posts[0]->tags->all()));
            self::assertSame(['sql'], array_map(static fn (Tag $t): string => $t->name, $posts[1]->tags->all()));

            $author = $orm->query(Author::class)->firstOrFail();
            $this->expectException(RelationNotLoaded::class);
            $author->posts->all();
        });
    }

    public function testCriteria(): void
    {
        $this->as(self::TENANT, function (Orm $orm): void {
            [$author] = self::seed($orm, 6);
            $q = static fn () => $orm->query(Post::class);
            self::assertSame(3, $q()->where('status', PostStatus::Published)->count());
            self::assertSame(1, $q()->where('featured', true)->count());
            self::assertSame(3, $q()->where('rating', '>', 4)->count());
            self::assertSame(['Post 3', 'Post 4', 'Post 5'], $q()->where('rating', '>', 4)->orderBy('rating')->pluck('title'));
            self::assertSame(2, $q()->whereIn('title', ['Post 1', 'Post 2', 'Nope'])->count());
            self::assertSame(2, $q()->where(static fn (Criteria $c) => $c->where('title', 'Post 0')->orWhere('title', 'Post 5'))->count());
            self::assertSame(6, $q()->where('title', 'like', 'Post %')->count());
            self::assertSame(3, $q()->whereBetween('rating', 1.5, 4.5)->count());
            self::assertSame(1, $q()->whereNull('deletedAt')->where('rating', 0.0)->count());
            self::assertEqualsWithDelta(22.5, $q()->sum('rating'), 0.001);
            self::assertTrue($q()->where('title', 'Post 3')->exists());
            self::assertFalse($q()->where('title', 'Post 9')->exists());

            $orm->save(new Comment((int) $q()->where('title', 'Post 2')->firstOrFail()->id, $author->id, 1, 'hi'));
            self::assertSame(['Post 2'], $q()->whereHas('comments')->pluck('title'));
            self::assertSame(['Post 2'], $q()->whereHas('comments', static fn (Criteria $c) => $c->where('body', 'hi'))->pluck('title'));
            self::assertSame(5, $q()->whereDoesntHave('comments')->count());
            self::assertSame(6, $q()->whereHas('author', static fn (Criteria $c) => $c->where('name', 'Ada'))->count());

            try {
                $q()->where('title; DROP TABLE posts', 'x')->get();
                self::fail('Unknown properties must be refused');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('has no column property', $e->getMessage());
            }
        });
    }

    public function testCursorPaginationAndStreaming(): void
    {
        $this->as(self::TENANT, function (Orm $orm): void {
            self::seed($orm, 25);
            $seen = [];
            $cursor = null;
            $pages = 0;
            do {
                $page = $orm->query(Post::class)->orderBy('rating', 'desc')->cursorPaginate(10, $cursor);
                foreach ($page->items as $post) {
                    $seen[] = $post->title;
                }
                $cursor = $page->page->nextCursor;
                $pages++;
            } while ($cursor !== null);
            self::assertSame(3, $pages);
            self::assertCount(25, array_unique($seen));
            self::assertSame('Post 24', $seen[0]);

            $titles = [];
            foreach ($orm->query(Post::class)->lazy(7) as $post) {
                $titles[] = $post->title;
            }
            self::assertCount(25, $titles);

            $first = $orm->query(Post::class)->cursorPaginate(10)->page->nextCursor;
            self::assertIsString($first);
            $this->expectException(BadRequest::class);
            $orm->query(Post::class)->orderBy('title')->cursorPaginate(10, $first);
        });
    }

    public function testBulkOperationsAndUpserts(): void
    {
        $this->as(self::TENANT, function (Orm $orm): void {
            self::seed($orm, 4);
            self::assertSame(2, $orm->query(Post::class)->where('status', PostStatus::Draft)->update(['status' => PostStatus::Published]));
            self::assertSame(4, $orm->query(Post::class)->where('status', PostStatus::Published)->count());
            self::assertSame(1, $orm->query(Post::class)->where('title', 'Post 3')->delete());
            self::assertSame(3, $orm->query(Post::class)->count());

            $orm->upsert([new Tag('t1', 'php'), new Tag('t2', 'go')], ['id']);
            $orm->upsert([new Tag('t1', 'PHP'), new Tag('t3', 'rust')], ['id']);
            $orm->clear();
            self::assertSame(['PHP', 'go', 'rust'], $orm->query(Tag::class)->orderBy('id')->pluck('name'));
        });
    }

    public function testTheTenantGuardAcceptsOrmQueries(): void
    {
        // tenant_tables is configured in setUp: every ORM statement above passed the guard.
        $this->as(self::TENANT, function (Orm $orm, Scope $scope): void {
            self::seed($orm);
            $this->expectException(TenantViolation::class);
            $scope->get(Database::class)->select('SELECT * FROM posts');
        });
    }

    public function testSchemaDiffIsForwardOnly(): void
    {
        $pdo = $this->app->container()->get(ConnectionManager::class)->writer();
        $pdo->exec('DROP TABLE comments');
        $pdo->exec('CREATE TABLE comments (id VARCHAR(36) PRIMARY KEY, legacy VARCHAR(10))');
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $diff = (new Schema($this->app->container()->get(EntityMap::class), Dialects::for(\is_string($driver) ? $driver : '')))->diff($pdo);
        $text = implode("\n", $diff);
        self::assertStringContainsString('post_id', $text);
        self::assertStringContainsString('ADD', $text);
        self::assertStringContainsString('-- comments.legacy is not mapped', $text);
        self::assertStringNotContainsString('DROP', $text);
        foreach ($diff as $statement) {
            if (!str_starts_with($statement, '--')) {
                $pdo->exec($statement);
            }
        }
        self::assertSame([], array_filter((new Schema($this->app->container()->get(EntityMap::class), Dialects::for(\is_string($driver) ? $driver : '')))->diff($pdo), static fn (string $s): bool => !str_starts_with($s, '--')));
    }

    public function testRelationContainersForNewEntities(): void
    {
        $many = Many::of([new Tag('a', 'b')]);
        self::assertCount(1, $many);
        self::assertTrue($many->isLoaded());
    }
}
