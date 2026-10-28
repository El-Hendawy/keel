<?php

declare(strict_types=1);

namespace Keel\Database;

/**
 * One result row with typed accessors. Reading a column as the wrong type, or a column
 * that does not exist, throws immediately instead of propagating `mixed` through the app.
 */
final readonly class Row
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function string(string $column): string
    {
        $value = $this->value($column);

        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            default => throw $this->mismatch($column, 'string'),
        };
    }

    public function nullableString(string $column): ?string
    {
        return $this->value($column) === null ? null : $this->string($column);
    }

    public function int(string $column): int
    {
        $value = $this->value($column);

        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => throw $this->mismatch($column, 'int'),
        };
    }

    public function nullableInt(string $column): ?int
    {
        return $this->value($column) === null ? null : $this->int($column);
    }

    public function float(string $column): float
    {
        $value = $this->value($column);

        return match (true) {
            \is_float($value), \is_int($value) => (float) $value,
            \is_string($value) && is_numeric($value) => (float) $value,
            default => throw $this->mismatch($column, 'float'),
        };
    }

    public function bool(string $column): bool
    {
        $value = $this->value($column);

        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => throw $this->mismatch($column, 'bool'),
        };
    }

    public function dateTime(string $column): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($this->string($column));
        } catch (\Exception $e) {
            throw new \UnexpectedValueException("Column \"$column\" is not a valid date-time.", 0, $e);
        }
    }

    public function isNull(string $column): bool
    {
        return $this->value($column) === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    private function value(string $column): mixed
    {
        if (!\array_key_exists($column, $this->values)) {
            throw new \OutOfBoundsException("Column \"$column\" is not in the result set (have: " . implode(', ', array_keys($this->values)) . ').');
        }

        return $this->values[$column];
    }

    private function mismatch(string $column, string $expected): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('Column "%s" is %s, expected %s.', $column, get_debug_type($this->values[$column]), $expected));
    }
}
