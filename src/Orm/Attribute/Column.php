<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/**
 * Optional column details. The type is inferred from the property: string, int, float,
 * bool, DateTimeImmutable, backed enum, or array (stored as JSON). Override with
 * `type: 'text'` (long strings) or `type: 'decimal'` (exact numbers held in a string).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public int $length = 255,
        public int $precision = 18,
        public int $scale = 4,
        public bool $unique = false,
        public bool $index = false,
    ) {
    }
}
