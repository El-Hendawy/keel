<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/**
 * One-to-many: `foreignKey` is the property on the target that holds this entity's id.
 * The property is a `Many` (`= new Many()`), filled only by eager loading.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class HasMany
{
    /** @param class-string $target */
    public function __construct(public string $target, public string $foreignKey, public ?string $orderBy = null)
    {
    }
}
