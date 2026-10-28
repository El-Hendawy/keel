<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/** Inclusive numeric bounds. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Range implements Constraint
{
    public function __construct(public int|float|null $min = null, public int|float|null $max = null)
    {
    }

    public function supports(string $kind): bool
    {
        return $kind === 'int' || $kind === 'float';
    }

    public function compile(string $value): string
    {
        $checks = [];
        if ($this->min !== null) {
            $checks[] = "$value >= " . var_export($this->min, true);
        }
        if ($this->max !== null) {
            $checks[] = "$value <= " . var_export($this->max, true);
        }

        return $checks === [] ? 'true' : '(' . implode(' && ', $checks) . ')';
    }

    public function code(): string
    {
        return 'range';
    }

    public function message(): string
    {
        return match (true) {
            $this->max === null => "Must be at least {$this->min}.",
            $this->min === null => "Must be at most {$this->max}.",
            default => "Must be between {$this->min} and {$this->max}.",
        };
    }

    public function schema(): array
    {
        return array_filter(['minimum' => $this->min, 'maximum' => $this->max], static fn ($v) => $v !== null);
    }
}
