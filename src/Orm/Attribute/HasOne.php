<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/** One-to-one owned by the target: `foreignKey` is the target's property holding this id. The property is a `One`. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class HasOne
{
    /** @param class-string $target */
    public function __construct(public string $target, public string $foreignKey)
    {
    }
}
