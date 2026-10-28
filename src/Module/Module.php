<?php

declare(strict_types=1);

namespace Keel\Module;

use Attribute;

/**
 * Declares a module: every class in this class's namespace (and below) belongs to it.
 *
 * Other modules may only use the classes listed in `exports`, and only if they list
 * this module in their `dependsOn`. Anything else fails the build.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Module
{
    /**
     * @param list<class-string> $exports
     * @param list<string> $dependsOn module names
     */
    public function __construct(
        public string $name,
        public array $exports = [],
        public array $dependsOn = [],
    ) {
    }
}
