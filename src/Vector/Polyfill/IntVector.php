<?php

declare(strict_types=1);

namespace Keel\Vector;

use Keel\Vector\Polyfill\Support;
use Keel\Vector\Polyfill\WideInt;

/**
 * A list of 64-bit integers. Pure-PHP twin of the keel_vector class, loaded only when the extension
 * is not: same API, same results, same exceptions and messages, backed by a PHP array.
 *
 * @implements \ArrayAccess<int, int>
 * @implements \IteratorAggregate<int, int>
 */
final class IntVector implements \Countable, \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    private const CLASS_NAME = 'Keel\Vector\IntVector';

    /** @var list<int> */
    private array $values;

    /**
     * Same as `fromArray()`; no argument makes an empty vector.
     *
     * @param array<mixed>|null $values
     */
    public function __construct(?array $values = null)
    {
        $this->values = $values === null ? [] : self::collect($values, '__construct');
    }

    /**
     * The array's values in order (keys are ignored). Every value must be an int: floats, numeric
     * strings and booleans are refused, not converted.
     *
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return self::of(self::collect($values, 'fromArray'));
    }

    public static function filled(int $length, mixed $value): self
    {
        $value = self::int($value, 'filled', '#2 ($value)');
        if ($length < 0) {
            throw Support::negativeLength(self::CLASS_NAME);
        }

        return self::of($length === 0 ? [] : array_fill(0, $length, $value));
    }

    /**
     * `start` to `end` inclusive, like PHP's `range()`: counts down when `end < start`, and the sign
     * of `step` is ignored.
     */
    public static function range(int $start, int $end, int $step = 1): self
    {
        if ($step === 0) {
            throw new \ValueError(self::CLASS_NAME . '::range(): Argument #3 ($step) cannot be 0');
        }
        $up = $end >= $start;
        $values = [];
        $v = $start;
        while (true) {
            $values[] = $v;
            // Subtracting a negative step instead of negating it keeps PHP_INT_MIN exact.
            $next = $up === $step > 0 ? Support::add($v, $step) : Support::sub($v, $step);
            if ($next === null || ($up ? $next > $end : $next < $end)) {
                break;
            }
            $v = $next;
        }

        return self::of($values);
    }

    public static function empty(): self
    {
        return self::of([]);
    }

    /** @return list<int> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function count(): int
    {
        return \count($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** An estimate of the bytes the backing PHP array holds; the extension reports 8 per element. */
    public function memoryBytes(): int
    {
        return Support::arrayBytes(\count($this->values));
    }

    public function get(int $index): int
    {
        return $this->values[Support::position(self::CLASS_NAME, 'get', $index, \count($this->values))];
    }

    public function set(int $index, mixed $value): void
    {
        $value = self::int($value, 'set', '#2 ($value)');
        if (!isset($this->values[$index])) {
            throw Support::badIndex(self::CLASS_NAME, 'set', $index, \count($this->values));
        }
        $this->values[$index] = $value;
    }

    public function push(mixed $value): void
    {
        $this->values[] = self::int($value, 'push', '#1 ($value)');
    }

    public function pop(): int
    {
        if ($this->values === []) {
            throw Support::underflow(self::CLASS_NAME, 'pop');
        }

        return array_pop($this->values);
    }

    /** A copy of part of the vector, with `array_slice()`'s rules for negative offsets and lengths. */
    public function slice(int $offset, ?int $length = null): self
    {
        return self::of(\array_slice($this->values, $offset, $length));
    }

    public function offsetExists(mixed $offset): bool
    {
        return Support::isIndex($offset, \count($this->values));
    }

    public function offsetGet(mixed $offset): int
    {
        $index = Support::offset(self::CLASS_NAME, 'offsetGet', $offset);

        return $this->values[Support::position(self::CLASS_NAME, 'offsetGet', $index, \count($this->values))];
    }

    /** `$vector[] = $value` appends; `$vector[$i] = $value` replaces an existing element. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $value = self::int($value, 'offsetSet', '#2 ($value)');
        if ($offset === null) {
            $this->values[] = $value;

            return;
        }
        $index = Support::offset(self::CLASS_NAME, 'offsetSet', $offset);
        if (!isset($this->values[$index])) {
            throw Support::badIndex(self::CLASS_NAME, 'offsetSet', $index, \count($this->values));
        }
        $this->values[$index] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        throw Support::cannotUnset(self::CLASS_NAME);
    }

    public function getIterator(): VectorIterator
    {
        return VectorIterator::of($this->values);
    }

    /** @return list<int> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    /** @return list<int> */
    public function __serialize(): array
    {
        return $this->values;
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->values = self::collect($data, '__unserialize');
    }

    /** The exact sum. Throws `OverflowException` when it does not fit in an int. */
    public function sum(): int
    {
        // A partial sum may overflow even when a later value brings the total back.
        $exact = Support::sum($this->values) ?? WideInt::toInt(WideInt::sum($this->values));

        return $exact ?? throw new OverflowException(self::CLASS_NAME . '::sum(): the sum does not fit in an int; use mean(), or a FloatVector');
    }

    public function min(): int
    {
        return $this->values === [] ? throw Support::underflow(self::CLASS_NAME, 'min') : min($this->values);
    }

    public function max(): int
    {
        return $this->values === [] ? throw Support::underflow(self::CLASS_NAME, 'max') : max($this->values);
    }

    /** The exact sum rounded once to a float, divided by the count. Never overflows. */
    public function mean(): float
    {
        if ($this->values === []) {
            throw Support::underflow(self::CLASS_NAME, 'mean');
        }
        $sum = Support::sum($this->values);
        $total = $sum !== null ? (float) $sum : WideInt::toFloat(WideInt::sum($this->values));

        return $total / \count($this->values);
    }

    /** The exact dot product. Throws `OverflowException` when it does not fit in an int. */
    public function dot(mixed $other): int
    {
        $other = self::vector($other, 'dot', '#1 ($other)');
        if (\count($other->values) !== \count($this->values)) {
            throw Support::lengthMismatch(self::CLASS_NAME, 'dot', \count($this->values), \count($other->values));
        }
        $sum = 0;
        foreach ($this->values as $i => $x) {
            $product = Support::mul($x, $other->values[$i]);
            $next = $product === null ? null : Support::add($sum, $product);
            if ($next === null) {
                $sum = WideInt::toInt(WideInt::sum($this->values, $other->values));
                break;
            }
            $sum = $next;
        }

        return $sum ?? throw new OverflowException(self::CLASS_NAME . '::dot(): the dot product does not fit in an int; use FloatVector');
    }

    public function add(mixed $other): self
    {
        return $this->elementWise('+', $other, 'add');
    }

    public function sub(mixed $other): self
    {
        return $this->elementWise('-', $other, 'sub');
    }

    public function mul(mixed $other): self
    {
        return $this->elementWise('*', $other, 'mul');
    }

    public function abs(): self
    {
        $out = [];
        foreach ($this->values as $i => $x) {
            if ($x === PHP_INT_MIN) {
                throw new OverflowException(self::CLASS_NAME . "::abs(): the absolute value of $x at index $i does not fit in an int");
            }
            $out[] = abs($x);
        }

        return self::of($out);
    }

    /** The indices of the elements greater than `value`. */
    public function whereGreaterThan(mixed $value): self
    {
        $value = self::int($value, 'whereGreaterThan', '#1 ($value)');

        return self::of(array_keys(array_filter($this->values, static fn (int $x): bool => $x > $value)));
    }

    public function whereLessThan(mixed $value): self
    {
        $value = self::int($value, 'whereLessThan', '#1 ($value)');

        return self::of(array_keys(array_filter($this->values, static fn (int $x): bool => $x < $value)));
    }

    public function whereEquals(mixed $value): self
    {
        $value = self::int($value, 'whereEquals', '#1 ($value)');

        return self::of(array_keys($this->values, $value, true));
    }

    /** The indices of the elements in `[min, max]`, both ends included. */
    public function whereBetween(mixed $min, mixed $max): self
    {
        $min = self::int($min, 'whereBetween', '#1 ($min)');
        $max = self::int($max, 'whereBetween', '#2 ($max)');

        return self::of(array_keys(array_filter($this->values, static fn (int $x): bool => $x >= $min && $x <= $max)));
    }

    /** The elements at the given indices, in that order (repeats allowed). */
    public function take(mixed $indices): self
    {
        $indices = self::vector($indices, 'take', '#1 ($indices)');
        $out = [];
        foreach ($indices->values as $i) {
            $out[] = $this->values[$i] ?? throw Support::badIndex(self::CLASS_NAME, 'take', $i, \count($this->values));
        }

        return self::of($out);
    }

    public function sort(): self
    {
        $values = $this->values;
        sort($values);

        return self::of($values);
    }

    /** The first occurrence of each value, in order. */
    public function unique(): self
    {
        return self::of(array_keys(array_flip($this->values)));
    }

    /**
     * Calls `$fn($value)` for each element, which must return an int. Slow: one PHP call per element.
     */
    public function map(callable $callback): self
    {
        $out = [];
        foreach ($this->values as $v) {
            $result = $callback($v);
            if (!\is_int($result)) {
                throw new \TypeError(self::CLASS_NAME . '::map(): the callback must return int, ' . Support::typeName($result) . ' returned');
            }
            $out[] = $result;
        }

        return self::of($out);
    }

    /** The elements for which `$fn($value)` is truthy. Slow: one PHP call per element. */
    public function filter(callable $callback): self
    {
        $out = [];
        foreach ($this->values as $v) {
            if ($callback($v)) {
                $out[] = $v;
            }
        }

        return self::of($out);
    }

    /** @param list<int> $values */
    private static function of(array $values): self
    {
        $vector = new self();
        $vector->values = $values;

        return $vector;
    }

    private static function int(mixed $value, string $method, string $argument): int
    {
        return \is_int($value) ? $value : throw Support::typeError(self::CLASS_NAME, $method, $argument, 'int', $value);
    }

    private static function vector(mixed $value, string $method, string $argument): self
    {
        return $value instanceof self ? $value : throw Support::typeError(self::CLASS_NAME, $method, $argument, self::CLASS_NAME, $value);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<int>
     */
    private static function collect(array $values, string $method): array
    {
        foreach ($values as $key => $value) {
            if (!\is_int($value)) {
                throw new \TypeError(
                    self::CLASS_NAME . "::$method(): the value at key $key is " . Support::typeName($value)
                    . ', but an IntVector holds only int values; convert them first, or use FloatVector',
                );
            }
        }

        /** @var list<int> */
        return array_values($values);
    }

    private function elementWise(string $op, mixed $other, string $method): self
    {
        if ($other instanceof self) {
            if (\count($other->values) !== \count($this->values)) {
                throw Support::lengthMismatch(self::CLASS_NAME, $method, \count($this->values), \count($other->values));
            }
            $right = $other->values;
        } elseif (\is_int($other)) {
            $right = null;
        } else {
            throw new \TypeError(
                self::CLASS_NAME . "::$method(): Argument #1 (\$other) must be of type " . self::CLASS_NAME . '|int, '
                . Support::typeName($other) . ' given',
            );
        }
        $out = [];
        foreach ($this->values as $i => $x) {
            $y = $right === null ? $other : $right[$i];
            $r = match ($op) {
                '+' => Support::add($x, $y),
                '-' => Support::sub($x, $y),
                default => Support::mul($x, $y),
            };
            if ($r === null) {
                throw new OverflowException(
                    self::CLASS_NAME . "::$method(): $x $op $y at index $i does not fit in an int; use FloatVector for values beyond ±9.2e18",
                );
            }
            $out[] = $r;
        }

        return self::of($out);
    }
}
