<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/** Number of items in a list. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Count implements Constraint
{
    public function __construct(public int $min = 0, public ?int $max = null)
    {
    }

    public function supports(string $kind): bool
    {
        return $kind === 'list';
    }

    public function compile(string $value): string
    {
        $checks = [];
        if ($this->min > 0) {
            $checks[] = "\\count($value) >= {$this->min}";
        }
        if ($this->max !== null) {
            $checks[] = "\\count($value) <= {$this->max}";
        }

        return $checks === [] ? 'true' : '(' . implode(' && ', $checks) . ')';
    }

    public function code(): string
    {
        return 'count';
    }

    public function message(): string
    {
        return match (true) {
            $this->max === null => "Must contain at least {$this->min} item(s).",
            $this->min === 0 => "Must contain at most {$this->max} item(s).",
            default => "Must contain between {$this->min} and {$this->max} items.",
        };
    }

    public function schema(): array
    {
        return array_filter(['minItems' => $this->min ?: null, 'maxItems' => $this->max], static fn ($v) => $v !== null);
    }
}
