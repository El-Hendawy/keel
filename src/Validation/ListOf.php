<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/**
 * Declares the item type of an array property. Equivalent to a `@var list<T>` docblock;
 * use whichever reads better, the compiler accepts both.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /** @param 'string'|'int'|'float'|'bool'|class-string $type */
    public function __construct(public string $type)
    {
    }
}
