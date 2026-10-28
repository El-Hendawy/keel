<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/** A (multi-column) index for the generated schema: #[Index(['tenantId', 'createdAt'])]. Properties, not columns. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Index
{
    /** @param list<string> $properties */
    public function __construct(public array $properties, public bool $unique = false)
    {
    }
}
