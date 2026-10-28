<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\OrmBlog;

use Keel\Orm\Attribute\BelongsTo;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\Id;
use Keel\Orm\Attribute\TenantKey;
use Keel\Orm\One;

#[Entity('comments')]
final class Comment
{
    /** @param One<Author> $author */
    public function __construct(
        public int $postId,
        public string $authorId,
        public int $position,
        public string $body,
        #[Id] public string $id = '',
        #[TenantKey] public string $tenantId = '',
        #[BelongsTo(Author::class, foreignKey: 'authorId')] public One $author = new One(),
    ) {
    }
}
