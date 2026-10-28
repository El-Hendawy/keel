<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/**
 * An exact decimal number held in a string ("12.50"), as money and rates should be: JSON
 * numbers become floats in most clients. `scale` caps the decimal places.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Decimal implements Constraint
{
    public function __construct(public ?int $scale = null, public bool $positive = false)
    {
    }

    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return '\\preg_match(' . var_export($this->pattern(), true) . ", $value) === 1" . ($this->positive ? " && \\trim($value, '0.') !== ''" : '');
    }

    public function code(): string
    {
        return 'decimal';
    }

    public function message(): string
    {
        return 'Must be a ' . ($this->positive ? 'positive ' : '') . 'decimal number as a string, like "12.50"'
            . ($this->scale === null ? '' : ", with at most {$this->scale} decimal place(s)") . '.';
    }

    public function schema(): array
    {
        return ['type' => 'string', 'pattern' => substr($this->pattern(), 1, -1), 'examples' => ['12.50']];
    }

    private function pattern(): string
    {
        $fraction = match ($this->scale) {
            null => '(\.\d+)?',
            0 => '',
            default => '(\.\d{1,' . $this->scale . '})?',
        };

        return '/^' . ($this->positive ? '' : '-?') . '\d{1,18}' . $fraction . '$/';
    }
}
