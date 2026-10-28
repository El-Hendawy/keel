<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/** A BIC (SWIFT code, ISO 9362): 8 or 11 characters, e.g. DEUTDEFF or DEUTDEFF500. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Bic implements Constraint
{
    public const string PATTERN = '^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$';

    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "\\preg_match('/" . self::PATTERN . "/', $value) === 1";
    }

    public function code(): string
    {
        return 'bic';
    }

    public function message(): string
    {
        return 'Must be a BIC (SWIFT code) of 8 or 11 characters.';
    }

    public function schema(): array
    {
        return ['pattern' => self::PATTERN, 'examples' => ['DEUTDEFF']];
    }
}
