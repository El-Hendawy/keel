<?php

declare(strict_types=1);

namespace Keel\Fix\Attribute;

use Attribute;

/**
 * Maps a constructor parameter to a FIX tag. Types: string, int, float, bool (Y/N),
 * DateTimeImmutable (UTCTimestamp) and backed enums (the enum value is the wire value).
 * Use string for prices and quantities when you need exact decimals.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Field
{
    public function __construct(public int $tag)
    {
    }
}
