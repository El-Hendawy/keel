<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

use Attribute;

/**
 * Maps an HTTP method and path to an invokable endpoint class.
 *
 * Path parameters are written `{name}` and bound by name to `__invoke` parameters.
 * An `int` parameter only matches digits; `{name:regex}` sets an explicit pattern.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $name = null,
    ) {
    }
}
