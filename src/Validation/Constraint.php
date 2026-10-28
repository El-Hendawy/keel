<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * A validation rule placed on an input property.
 *
 * Constraints are compiled: compile() returns a PHP boolean expression that is
 * inlined into the generated hydrator, so validation costs no reflection or
 * attribute instantiation at runtime. schema() feeds the OpenAPI document.
 */
interface Constraint
{
    /** The kinds of value this constraint applies to: string, int, float, bool, list, enum, datetime, object. */
    public function supports(string $kind): bool;

    /** A PHP expression that is true when $value (itself a PHP expression) is valid. */
    public function compile(string $value): string;

    /** Machine-readable error code, stable across releases. */
    public function code(): string;

    /** Human-readable error message. */
    public function message(): string;

    /** @return array<string, mixed> JSON Schema keywords */
    public function schema(): array;
}
