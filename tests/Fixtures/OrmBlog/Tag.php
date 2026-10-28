<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\OrmBlog;

use Keel\Orm\Attribute\Column;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\Id;

/** Shared by all tenants; the id is chosen by the caller and readonly. */
#[Entity('tags')]
final readonly class Tag
{
    public function __construct(
        #[Id(generated: 'none')] public string $id,
        #[Column(unique: true, length: 64)] public string $name,
    ) {
    }
}
