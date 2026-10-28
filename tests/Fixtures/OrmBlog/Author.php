<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\OrmBlog;

use Keel\Orm\Attribute\Column;
use Keel\Orm\Attribute\CreatedAt;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\HasMany;
use Keel\Orm\Attribute\Id;
use Keel\Orm\Attribute\TenantKey;
use Keel\Orm\Many;

#[Entity('authors')]
final class Author
{
    /** @param Many<Post> $posts */
    public function __construct(
        public string $name,
        #[Column(unique: true)] public string $email,
        #[Id] public string $id = '',
        #[TenantKey] public string $tenantId = '',
        #[CreatedAt] public ?\DateTimeImmutable $createdAt = null,
        #[HasMany(Post::class, foreignKey: 'authorId')] public Many $posts = new Many(),
    ) {
    }
}
