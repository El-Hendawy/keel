<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Orm\Attribute\Column;
use Keel\Orm\Attribute\CreatedAt;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\Id;
use Keel\Orm\Attribute\Index;
use Keel\Orm\Attribute\SoftDelete;
use Keel\Orm\Attribute\TenantKey;
use Keel\Orm\Attribute\UpdatedAt;
use Keel\Orm\Attribute\Version;

#[Entity('products')]
#[Index(['tenantId', 'sku'], unique: true)]
final class Product
{
    public function __construct(
        #[Column(length: 64)] public string $sku,
        public string $name,
        public int $priceCents,
        public bool $active = true,
        #[Id] public string $id = '',
        #[TenantKey] public string $tenantId = '',
        #[Version] public int $version = 0,
        #[CreatedAt] public ?\DateTimeImmutable $createdAt = null,
        #[UpdatedAt] public ?\DateTimeImmutable $updatedAt = null,
        #[SoftDelete] public ?\DateTimeImmutable $deletedAt = null,
    ) {
    }
}
