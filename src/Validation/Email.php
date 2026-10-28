<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Email implements Constraint
{
    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "\\filter_var($value, \\FILTER_VALIDATE_EMAIL) !== false";
    }

    public function code(): string
    {
        return 'email';
    }

    public function message(): string
    {
        return 'Must be a valid email address.';
    }

    public function schema(): array
    {
        return ['format' => 'email'];
    }
}
