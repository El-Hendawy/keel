<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/** String length in characters (not bytes). */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Length implements Constraint
{
    public function __construct(public int $min = 0, public ?int $max = null)
    {
    }

    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        $checks = [];
        if ($this->min > 0) {
            $checks[] = "\\mb_strlen($value) >= {$this->min}";
        }
        if ($this->max !== null) {
            $checks[] = "\\mb_strlen($value) <= {$this->max}";
        }

        return $checks === [] ? 'true' : '(' . implode(' && ', $checks) . ')';
    }

    public function code(): string
    {
        return 'length';
    }

    public function message(): string
    {
        return match (true) {
            $this->max === null => "Must be at least {$this->min} characters.",
            $this->min === 0 => "Must be at most {$this->max} characters.",
            default => "Must be between {$this->min} and {$this->max} characters.",
        };
    }

    public function schema(): array
    {
        return array_filter(['minLength' => $this->min ?: null, 'maxLength' => $this->max], static fn ($v) => $v !== null);
    }
}
