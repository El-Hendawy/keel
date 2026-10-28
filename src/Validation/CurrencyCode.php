<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/** An ISO 4217 currency code with a minor unit, e.g. EUR, USD, JPY. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class CurrencyCode implements Constraint
{
    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "\\Keel\\Money\\Currency::exists($value)";
    }

    public function code(): string
    {
        return 'currency';
    }

    public function message(): string
    {
        return 'Must be an ISO 4217 currency code, like EUR.';
    }

    public function schema(): array
    {
        return ['pattern' => '^[A-Z]{3}$', 'examples' => ['EUR']];
    }
}
