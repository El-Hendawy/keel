<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/** Many-to-one: `foreignKey` is the property on this entity holding the target's id. The property is a `One`. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class BelongsTo
{
    /** @param class-string $target */
    public function __construct(public string $target, public string $foreignKey)
    {
    }
}
