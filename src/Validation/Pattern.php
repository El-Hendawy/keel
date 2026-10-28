<?php

declare(strict_types=1);

namespace Keel\Validation;

use Attribute;

/** Matches a PCRE pattern, for example `#[Pattern('/^[A-Z]{3}$/')]`. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Pattern implements Constraint
{
    public function __construct(public string $regex, public string $description = '')
    {
    }

    public function supports(string $kind): bool
    {
        return $kind === 'string';
    }

    public function compile(string $value): string
    {
        return '\\preg_match(' . var_export($this->regex, true) . ", $value) === 1";
    }

    public function code(): string
    {
        return 'pattern';
    }

    public function message(): string
    {
        return $this->description !== '' ? $this->description : 'Has an invalid format.';
    }

    public function schema(): array
    {
        // Strip PCRE delimiters and flags for the JSON Schema regex dialect.
        $body = (string) preg_replace('/^(.)(.*)\1[a-zA-Z]*$/s', '$2', $this->regex);

        return ['pattern' => $body];
    }
}
