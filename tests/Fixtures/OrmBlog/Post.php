<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\OrmBlog;

use Keel\Orm\Attribute\BelongsTo;
use Keel\Orm\Attribute\BelongsToMany;
use Keel\Orm\Attribute\Column;
use Keel\Orm\Attribute\CreatedAt;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\HasMany;
use Keel\Orm\Attribute\Id;
use Keel\Orm\Attribute\Index;
use Keel\Orm\Attribute\SoftDelete;
use Keel\Orm\Attribute\TenantKey;
use Keel\Orm\Attribute\UpdatedAt;
use Keel\Orm\Attribute\Version;
use Keel\Orm\Many;
use Keel\Orm\One;

#[Entity('posts')]
#[Index(['tenantId', 'createdAt'])]
final class Post
{
    /**
     * @param array<mixed> $meta
     * @param One<Author> $author
     * @param Many<Comment> $comments
     * @param Many<Tag> $tags
     */
    public function __construct(
        public string $authorId,
        public string $title,
        #[Column(type: 'text')] public string $body = '',
        public PostStatus $status = PostStatus::Draft,
        public bool $featured = false,
        public ?float $rating = null,
        #[Column(type: 'decimal', precision: 10, scale: 2)] public string $price = '0',
        public array $meta = [],
        #[Id(generated: 'auto')] public ?int $id = null,
        #[TenantKey] public string $tenantId = '',
        #[Version] public int $version = 0,
        #[CreatedAt] public ?\DateTimeImmutable $createdAt = null,
        #[UpdatedAt] public ?\DateTimeImmutable $updatedAt = null,
        #[SoftDelete] public ?\DateTimeImmutable $deletedAt = null,
        #[BelongsTo(Author::class, foreignKey: 'authorId')] public One $author = new One(),
        #[HasMany(Comment::class, foreignKey: 'postId', orderBy: 'position')] public Many $comments = new Many(),
        #[BelongsToMany(Tag::class, pivot: 'post_tags', localColumn: 'post_id', relatedColumn: 'tag_id')] public Many $tags = new Many(),
    ) {
    }
}
