<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/**
 * Maps a class to a table. Constructor-promoted properties are the columns (snake_case
 * by default); everything is analysed and compiled at build time.
 *
 *     #[Entity('orders')]
 *     final class Order
 *     {
 *         public function __construct(
 *             #[Id] public string $id,
 *             #[TenantKey] public string $tenantId,
 *             public OrderStatus $status,
 *             #[Column(type: 'decimal', precision: 12, scale: 2)] public string $total,
 *             #[HasMany(OrderLine::class, foreignKey: 'orderId', orderBy: 'position')] public Many $lines = new Many(),
 *             #[CreatedAt] public ?\DateTimeImmutable $createdAt = null,
 *             #[Version] public int $version = 0,
 *         ) {}
 *     }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    public function __construct(public string $table)
    {
    }
}
