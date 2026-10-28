<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Url implements Constraint
{
    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "(\\filter_var($value, \\FILTER_VALIDATE_URL) !== false && \\preg_match('#^https?://#i', $value) === 1)";
    }

    public function code(): string
    {
        return 'url';
    }

    public function message(): string
    {
        return 'Must be an http(s) URL.';
    }

    public function schema(): array
    {
        return ['format' => 'uri'];
    }
}
