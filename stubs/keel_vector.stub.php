<?php

// Signatures of the keel_vector extension's classes, for IDEs and static analysis. Never loaded:
// without the extension, Keel loads the pure-PHP implementation in src/Vector/Polyfill, which has the
// same API. The extension's own reflection is the reference (tests/Vector checks both against it).

namespace Keel\Vector;

final class VectorInfo
{
    /** "avx2", "sse4.2", "neon" or "scalar" for the extension; "php" for the pure-PHP implementation. */
    public static function simdLevel(): string {}

    public static function version(): string {}

    public static function isNative(): bool {}
}

class VectorException extends \RuntimeException {}

/** An integer result that does not fit in 64 bits. */
final class OverflowException extends VectorException {}

/** Two vectors combined element by element have different lengths. */
final class LengthMismatchException extends VectorException {}

/**
 * Iterates over a snapshot of a vector, with indices as keys.
 *
 * @implements \Iterator<int, int|float>
 */
final class VectorIterator implements \Iterator
{
    public function current(): mixed {}

    public function key(): ?int {}

    public function next(): void {}

    public function rewind(): void {}

    public function valid(): bool {}
}

/**
 * A contiguous list of 64-bit integers. Integer results are exact: sums, dot products and
 * element-wise arithmetic throw OverflowException instead of wrapping or turning into floats.
 *
 * @implements \ArrayAccess<int, int>
 * @implements \IteratorAggregate<int, int>
 */
final class IntVector implements \Countable, \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    /** @param array<int>|null $values */
    public function __construct(?array $values = null) {}

    /**
     * The values in order, keys ignored. Only ints: floats, numeric strings and booleans throw TypeError.
     *
     * @param array<int> $values
     */
    public static function fromArray(array $values): IntVector {}

    public static function filled(int $length, int $value): IntVector {}

    /** Inclusive, like range(): counts down when $end < $start; the sign of $step is ignored. */
    public static function range(int $start, int $end, int $step = 1): IntVector {}

    public static function empty(): IntVector {}

    /** @return list<int> */
    public function toArray(): array {}

    public function count(): int {}

    public function isEmpty(): bool {}

    /** Bytes held by the element buffer (an estimate for the pure-PHP implementation). */
    public function memoryBytes(): int {}

    /** @throws \OutOfRangeException */
    public function get(int $index): int {}

    /** @throws \OutOfRangeException */
    public function set(int $index, int $value): void {}

    public function push(int $value): void {}

    /** @throws \UnderflowException */
    public function pop(): int {}

    /** A copy, with array_slice()'s rules for negative offsets and lengths. */
    public function slice(int $offset, ?int $length = null): IntVector {}

    public function offsetExists(mixed $offset): bool {}

    public function offsetGet(mixed $offset): int {}

    /** `$v[] = $x` appends; `$v[$i] = $x` replaces an existing element. */
    public function offsetSet(mixed $offset, mixed $value): void {}

    /** @throws VectorException always: a vector has no holes. */
    public function offsetUnset(mixed $offset): void {}

    public function getIterator(): VectorIterator {}

    /** @return list<int> */
    public function jsonSerialize(): array {}

    /** @return list<int> */
    public function __serialize(): array {}

    /** @param list<int> $data */
    public function __unserialize(array $data): void {}

    /** @throws OverflowException when the exact sum does not fit in an int */
    public function sum(): int {}

    /** @throws \UnderflowException */
    public function min(): int {}

    /** @throws \UnderflowException */
    public function max(): int {}

    /** The exact sum rounded once to a float, divided by the count. @throws \UnderflowException */
    public function mean(): float {}

    /** @throws OverflowException|LengthMismatchException */
    public function dot(IntVector $other): int {}

    /** @throws OverflowException|LengthMismatchException */
    public function add(IntVector|int $other): IntVector {}

    /** @throws OverflowException|LengthMismatchException */
    public function sub(IntVector|int $other): IntVector {}

    /** @throws OverflowException|LengthMismatchException */
    public function mul(IntVector|int $other): IntVector {}

    /** @throws OverflowException for PHP_INT_MIN */
    public function abs(): IntVector {}

    /** Indices of the elements greater than $value. */
    public function whereGreaterThan(int $value): IntVector {}

    public function whereLessThan(int $value): IntVector {}

    public function whereEquals(int $value): IntVector {}

    /** Indices of the elements in [$min, $max]. */
    public function whereBetween(int $min, int $max): IntVector {}

    /** The elements at $indices, in that order. @throws \OutOfRangeException */
    public function take(IntVector $indices): IntVector {}

    public function sort(): IntVector {}

    /** First occurrences, in order. */
    public function unique(): IntVector {}

    /**
     * Slow: one PHP call per element.
     *
     * @param callable(int): int $callback
     */
    public function map(callable $callback): IntVector {}

    /**
     * Slow: one PHP call per element.
     *
     * @param callable(int): mixed $callback
     */
    public function filter(callable $callback): IntVector {}
}

/**
 * A contiguous list of 64-bit floats. Sums and dot products add in one fixed order (element i into
 * running total i % 8, then a fixed tree), so results are identical on every machine and in both
 * implementations, and may differ from a left-to-right sum in the last bits.
 *
 * @implements \ArrayAccess<int, float>
 * @implements \IteratorAggregate<int, float>
 */
final class FloatVector implements \Countable, \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    /** @param array<int|float>|null $values */
    public function __construct(?array $values = null) {}

    /**
     * The values in order, keys ignored. Ints become floats; strings and booleans throw TypeError.
     *
     * @param array<int|float> $values
     */
    public static function fromArray(array $values): FloatVector {}

    public static function filled(int $length, int|float $value): FloatVector {}

    /** $start + k * $step up to $end inclusive (within 1e-9 steps); the sign of $step is ignored. */
    public static function range(int|float $start, int|float $end, int|float|null $step = null): FloatVector {}

    public static function empty(): FloatVector {}

    /** @return list<float> */
    public function toArray(): array {}

    public function count(): int {}

    public function isEmpty(): bool {}

    /** Bytes held by the element buffer (an estimate for the pure-PHP implementation). */
    public function memoryBytes(): int {}

    /** @throws \OutOfRangeException */
    public function get(int $index): float {}

    /** @throws \OutOfRangeException */
    public function set(int $index, int|float $value): void {}

    public function push(int|float $value): void {}

    /** @throws \UnderflowException */
    public function pop(): float {}

    /** A copy, with array_slice()'s rules for negative offsets and lengths. */
    public function slice(int $offset, ?int $length = null): FloatVector {}

    public function offsetExists(mixed $offset): bool {}

    public function offsetGet(mixed $offset): float {}

    /** `$v[] = $x` appends; `$v[$i] = $x` replaces an existing element. */
    public function offsetSet(mixed $offset, mixed $value): void {}

    /** @throws VectorException always: a vector has no holes. */
    public function offsetUnset(mixed $offset): void {}

    public function getIterator(): VectorIterator {}

    /** @return list<float> */
    public function jsonSerialize(): array {}

    /** @return list<float> */
    public function __serialize(): array {}

    /** @param list<int|float> $data */
    public function __unserialize(array $data): void {}

    public function sum(): float {}

    /** NaN if any value is NaN; -0.0 is smaller than 0.0. @throws \UnderflowException */
    public function min(): float {}

    /** NaN if any value is NaN; 0.0 is larger than -0.0. @throws \UnderflowException */
    public function max(): float {}

    /** @throws \UnderflowException */
    public function mean(): float {}

    /** @throws LengthMismatchException */
    public function dot(FloatVector $other): float {}

    /** @throws LengthMismatchException */
    public function add(FloatVector|int|float $other): FloatVector {}

    /** @throws LengthMismatchException */
    public function sub(FloatVector|int|float $other): FloatVector {}

    /** @throws LengthMismatchException */
    public function mul(FloatVector|int|float $other): FloatVector {}

    /** IEEE 754 division like fdiv(): dividing by zero gives INF, -INF or NaN. @throws LengthMismatchException */
    public function div(FloatVector|int|float $other): FloatVector {}

    public function abs(): FloatVector {}

    /** Indices of the elements greater than $value; NaN never matches. */
    public function whereGreaterThan(int|float $value): IntVector {}

    public function whereLessThan(int|float $value): IntVector {}

    public function whereEquals(int|float $value): IntVector {}

    /** Indices of the elements in [$min, $max]. */
    public function whereBetween(int|float $min, int|float $max): IntVector {}

    /** The elements at $indices, in that order. @throws \OutOfRangeException */
    public function take(IntVector $indices): FloatVector {}

    /** Ascending; -0.0 before 0.0, NaN last. */
    public function sort(): FloatVector {}

    /** First occurrences, in order; -0.0 repeats 0.0, every NaN repeats the first. */
    public function unique(): FloatVector {}

    /**
     * Slow: one PHP call per element.
     *
     * @param callable(float): (int|float) $callback
     */
    public function map(callable $callback): FloatVector {}

    /**
     * Slow: one PHP call per element.
     *
     * @param callable(float): mixed $callback
     */
    public function filter(callable $callback): FloatVector {}
}
