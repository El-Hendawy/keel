<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Patch extends Route
{
    public function __construct(string $path, ?string $name = null)
    {
        parent::__construct('PATCH', $path, $name);
    }
}
