<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\OrmBroken;

use Keel\Orm\Attribute\BelongsToMany;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\HasMany;
use Keel\Orm\Attribute\Version;
use Keel\Orm\Many;

/** Every mapping mistake the compiler must catch. */
#[Entity('broken')]
final class Broken
{
    /** @param list<object> $lines */
    public function __construct(
        public string $name,
        #[Version] public readonly int $stamp,
        public \DateTime $when,
        #[HasMany(Broken::class, foreignKey: 'name')] public array $lines = [],
        #[BelongsToMany(\stdClass::class, pivot: 'p', localColumn: 'a', relatedColumn: 'b')] public Many $tags = new Many(),
    ) {
    }
}
