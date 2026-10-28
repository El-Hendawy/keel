<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Uuid implements Constraint
{
    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return "\\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1";
    }

    public function code(): string
    {
        return 'uuid';
    }

    public function message(): string
    {
        return 'Must be a UUID.';
    }

    public function schema(): array
    {
        return ['format' => 'uuid'];
    }
}
