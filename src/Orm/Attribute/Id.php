<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/**
 * The primary key. `uuid` (default): a UUIDv7 string, assigned on insert when empty;
 * `auto`: a database-generated integer (the property must not be readonly); `none`: you
 * always set it.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Id
{
    public function __construct(public string $generated = 'uuid')
    {
    }
}
