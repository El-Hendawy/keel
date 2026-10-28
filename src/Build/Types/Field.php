<?php

declare(strict_types=1);

namespace Keel\Build\Types;

use Keel\Validation\Constraint;

final readonly class Field
{
    /** @param list<Constraint> $constraints */
    public function __construct(
        public string $name,
        public TypeInfo $type,
        public array $constraints = [],
        public bool $hasDefault = false,
        public mixed $default = null,
        public ?string $description = null,
    ) {
    }
}
