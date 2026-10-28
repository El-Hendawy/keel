<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/**
 * Many-to-many through a pivot table with two columns: `localColumn` holds this entity's
 * id and `relatedColumn` the target's. Attach and detach with Orm::attach()/detach().
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class BelongsToMany
{
    /** @param class-string $target */
    public function __construct(public string $target, public string $pivot, public string $localColumn, public string $relatedColumn)
    {
    }
}
