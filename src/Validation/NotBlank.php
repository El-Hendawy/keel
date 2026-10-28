<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class NotBlank implements Constraint
{
    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "\\trim($value) !== ''";
    }

    public function code(): string
    {
        return 'not_blank';
    }

    public function message(): string
    {
        return 'Must not be blank.';
    }

    public function schema(): array
    {
        return ['minLength' => 1];
    }
}
