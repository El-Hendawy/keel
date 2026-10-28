<?php

declare(strict_types=1);

namespace Keel\Fix\Attribute;

use Attribute;

/**
 * A repeating group: `list<Component>` where Component is a class whose constructor
 * parameters carry #[Field]. The first field of the component delimits instances.
 *
 *     #[Group(count: 453, of: Party::class)] public array $parties = [],
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Group
{
    /** @param class-string $of */
    public function __construct(public int $count, public string $of)
    {
    }
}
