<?php

declare(strict_types=1);

namespace Keel\Vector;

use Keel\Vector\Polyfill\Support;

/**
 * A list of 64-bit floats. Pure-PHP twin of the keel_vector class, loaded only when the extension is
 * not: same API, bit-identical results (sums add in the same fixed order), same exceptions.
 *
 * @implements \ArrayAccess<int, float>
 * @implements \IteratorAggregate<int, float>
 */
final class FloatVector implements \Countable, \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    private const CLASS_NAME = 'Keel\Vector\FloatVector';

    /** @var list<float> */
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
     * The array's values in order (keys are ignored). Ints are converted to floats; numeric strings and
     * booleans are refused.
     *
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return self::of(self::collect($values, 'fromArray'));
    }

    public static function filled(int $length, mixed $value): self
    {
        $value = self::float($value, 'filled', '#2 ($value)');
        if ($length < 0) {
            throw Support::negativeLength(self::CLASS_NAME);
        }

        return self::of($length === 0 ? [] : array_fill(0, $length, $value));
    }

    /**
     * `start + k * step` from `start` to `end` inclusive; counts down when `end < start`, and the sign
     * of `step` is ignored. The end is included when it is within 1e-9 steps of a multiple.
     */
    public static function range(mixed $start, mixed $end, mixed $step = null): self
    {
        $start = self::float($start, 'range', '#1 ($start)');
        $end = self::float($end, 'range', '#2 ($end)');
        $step = $step === null ? 1.0 : self::float($step, 'range', '#3 ($step)');
        if ($step === 0.0 || !is_finite($start) || !is_finite($end) || !is_finite($step)) {
            throw new \ValueError(self::CLASS_NAME . '::range(): start, end and step must be finite, and step cannot be 0');
        }
        $count = floor(abs($end - $start) / abs($step) + 1e-9) + 1.0;
        if (!($count < PHP_INT_MAX / 8)) {
            throw new VectorException(self::CLASS_NAME . "::range(): cannot allocate a range from $start to $end by $step");
        }
        $step = $end >= $start ? abs($step) : -abs($step);
        $values = [];
        for ($k = 0, $n = (int) $count; $k < $n; $k++) {
            $values[] = $start + $k * $step;
        }

        return self::of($values);
    }

    public static function empty(): self
    {
        return self::of([]);
    }

    /** @return list<float> */
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

    public function get(int $index): float
    {
        return $this->values[Support::position(self::CLASS_NAME, 'get', $index, \count($this->values))];
    }

    public function set(int $index, mixed $value): void
    {
        $value = self::float($value, 'set', '#2 ($value)');
        if (!isset($this->values[$index])) {
            throw Support::badIndex(self::CLASS_NAME, 'set', $index, \count($this->values));
        }
        $this->values[$index] = $value;
    }

    public function push(mixed $value): void
    {
        $this->values[] = self::float($value, 'push', '#1 ($value)');
    }

    public function pop(): float
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

    public function offsetGet(mixed $offset): float
    {
        $index = Support::offset(self::CLASS_NAME, 'offsetGet', $offset);

        return $this->values[Support::position(self::CLASS_NAME, 'offsetGet', $index, \count($this->values))];
    }

    /** `$vector[] = $value` appends; `$vector[$i] = $value` replaces an existing element. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $value = self::float($value, 'offsetSet', '#2 ($value)');
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

    /** @return list<float> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    /** @return list<float> */
    public function __serialize(): array
    {
        return $this->values;
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->values = self::collect($data, '__unserialize');
    }

    /**
     * The sum, added in a fixed order (eight running totals, element i into total i % 8), so the
     * result may differ from a left-to-right sum in the last bits but never between machines.
     */
    public function sum(): float
    {
        $t = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        foreach ($this->values as $i => $x) {
            $t[$i & 7] += $x;
        }

        return (($t[0] + $t[1]) + ($t[2] + $t[3])) + (($t[4] + $t[5]) + ($t[6] + $t[7]));
    }

    /** The smallest value; NaN if any value is NaN; -0.0 counts as smaller than 0.0. */
    public function min(): float
    {
        if ($this->values === []) {
            throw Support::underflow(self::CLASS_NAME, 'min');
        }
        $m = $this->values[0];
        foreach ($this->values as $v) {
            if (is_nan($v) || is_nan($m)) {
                return NAN;
            }
            if ($v < $m || ($v == $m && self::signBit($v))) {
                $m = $v;
            }
        }

        return $m;
    }

    /** The largest value; NaN if any value is NaN; 0.0 counts as larger than -0.0. */
    public function max(): float
    {
        if ($this->values === []) {
            throw Support::underflow(self::CLASS_NAME, 'max');
        }
        $m = $this->values[0];
        foreach ($this->values as $v) {
            if (is_nan($v) || is_nan($m)) {
                return NAN;
            }
            if ($v > $m || ($v == $m && !self::signBit($v))) {
                $m = $v;
            }
        }

        return $m;
    }

    public function mean(): float
    {
        if ($this->values === []) {
            throw Support::underflow(self::CLASS_NAME, 'mean');
        }

        return $this->sum() / \count($this->values);
    }

    /** The dot product, in the same fixed order as `sum()`. */
    public function dot(mixed $other): float
    {
        $other = self::vector($other, 'dot', '#1 ($other)');
        if (\count($other->values) !== \count($this->values)) {
            throw Support::lengthMismatch(self::CLASS_NAME, 'dot', \count($this->values), \count($other->values));
        }
        $t = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        foreach ($this->values as $i => $x) {
            $t[$i & 7] += $x * $other->values[$i];
        }

        return (($t[0] + $t[1]) + ($t[2] + $t[3])) + (($t[4] + $t[5]) + ($t[6] + $t[7]));
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

    /** IEEE 754 division, like `fdiv()`: dividing by zero gives INF, -INF or NaN instead of throwing. */
    public function div(mixed $other): self
    {
        return $this->elementWise('/', $other, 'div');
    }

    public function abs(): self
    {
        return self::of(array_map(abs(...), $this->values));
    }

    /** The indices of the elements greater than `value`. NaN is never greater, smaller or equal. */
    public function whereGreaterThan(mixed $value): IntVector
    {
        $value = self::float($value, 'whereGreaterThan', '#1 ($value)');

        return self::indices(array_filter($this->values, static fn (float $x): bool => $x > $value));
    }

    public function whereLessThan(mixed $value): IntVector
    {
        $value = self::float($value, 'whereLessThan', '#1 ($value)');

        return self::indices(array_filter($this->values, static fn (float $x): bool => $x < $value));
    }

    public function whereEquals(mixed $value): IntVector
    {
        $value = self::float($value, 'whereEquals', '#1 ($value)');

        return self::indices(array_filter($this->values, static fn (float $x): bool => $x == $value));
    }

    /** The indices of the elements in `[min, max]`, both ends included. */
    public function whereBetween(mixed $min, mixed $max): IntVector
    {
        $min = self::float($min, 'whereBetween', '#1 ($min)');
        $max = self::float($max, 'whereBetween', '#2 ($max)');

        return self::indices(array_filter($this->values, static fn (float $x): bool => $x >= $min && $x <= $max));
    }

    /** The elements at the given indices, in that order (repeats allowed). */
    public function take(mixed $indices): self
    {
        if (!$indices instanceof IntVector) {
            throw Support::typeError(self::CLASS_NAME, 'take', '#1 ($indices)', 'Keel\Vector\IntVector', $indices);
        }
        $out = [];
        foreach ($indices->toArray() as $i) {
            $out[] = $this->values[$i] ?? throw Support::badIndex(self::CLASS_NAME, 'take', $i, \count($this->values));
        }

        return self::of($out);
    }

    /** Ascending; -0.0 before 0.0, NaN last. */
    public function sort(): self
    {
        $numbers = [];
        $nans = [];
        foreach ($this->values as $v) {
            if (is_nan($v)) {
                $nans[] = $v;
            } else {
                $numbers[] = $v;
            }
        }
        sort($numbers);
        // sort() sees -0.0 and 0.0 as equal; put the negative zeros first.
        $first = null;
        $negative = 0;
        foreach ($numbers as $i => $v) {
            if ($v == 0.0) {
                $first ??= $i;
                $negative += self::signBit($v) ? 1 : 0;
            } elseif ($first !== null) {
                break;
            }
        }
        if ($first !== null) {
            for ($i = $first; isset($numbers[$i]) && $numbers[$i] == 0.0; $i++) {
                $numbers[$i] = $i - $first < $negative ? -0.0 : 0.0;
            }
        }

        return self::of([...$numbers, ...$nans]);
    }

    /** The first occurrence of each value, in order. -0.0 repeats 0.0, and every NaN repeats the first. */
    public function unique(): self
    {
        $seen = [];
        $out = [];
        foreach ($this->values as $v) {
            $key = is_nan($v) ? 'nan' : ($v == 0.0 ? '0' : pack('e', $v));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $v;
            }
        }

        return self::of($out);
    }

    /**
     * Calls `$fn($value)` for each element, which must return a float or an int. Slow: one PHP call per
     * element.
     */
    public function map(callable $callback): self
    {
        $out = [];
        foreach ($this->values as $v) {
            $result = $callback($v);
            if (!\is_float($result) && !\is_int($result)) {
                throw new \TypeError(self::CLASS_NAME . '::map(): the callback must return float or int, ' . Support::typeName($result) . ' returned');
            }
            $out[] = (float) $result;
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

    /** @param list<float> $values */
    private static function of(array $values): self
    {
        $vector = new self();
        $vector->values = $values;

        return $vector;
    }

    /** @param array<int, float> $matches */
    private static function indices(array $matches): IntVector
    {
        return IntVector::fromArray(array_keys($matches));
    }

    private static function signBit(float $v): bool
    {
        return $v < 0 || ($v == 0.0 && fdiv(1.0, $v) < 0);
    }

    private static function float(mixed $value, string $method, string $argument): float
    {
        return match (true) {
            \is_float($value) => $value,
            \is_int($value) => (float) $value,
            default => throw Support::typeError(self::CLASS_NAME, $method, $argument, 'float|int', $value),
        };
    }

    private static function vector(mixed $value, string $method, string $argument): self
    {
        return $value instanceof self ? $value : throw Support::typeError(self::CLASS_NAME, $method, $argument, self::CLASS_NAME, $value);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<float>
     */
    private static function collect(array $values, string $method): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (\is_float($value)) {
                $out[] = $value;
            } elseif (\is_int($value)) {
                $out[] = (float) $value;
            } else {
                throw new \TypeError(
                    self::CLASS_NAME . "::$method(): the value at key $key is " . Support::typeName($value)
                    . ', but a FloatVector holds only float and int values; convert them first',
                );
            }
        }

        return $out;
    }

    private function elementWise(string $op, mixed $other, string $method): self
    {
        if ($other instanceof self) {
            if (\count($other->values) !== \count($this->values)) {
                throw Support::lengthMismatch(self::CLASS_NAME, $method, \count($this->values), \count($other->values));
            }
            $right = $other->values;
            $out = [];
            foreach ($this->values as $i => $x) {
                $out[] = self::apply($op, $x, $right[$i]);
            }

            return self::of($out);
        }
        if (!\is_float($other) && !\is_int($other)) {
            throw new \TypeError(
                self::CLASS_NAME . "::$method(): Argument #1 (\$other) must be of type " . self::CLASS_NAME . '|float|int, '
                . Support::typeName($other) . ' given',
            );
        }
        $s = (float) $other;
        $out = [];
        foreach ($this->values as $x) {
            $out[] = self::apply($op, $x, $s);
        }

        return self::of($out);
    }

    private static function apply(string $op, float $x, float $y): float
    {
        return match ($op) {
            '+' => $x + $y,
            '-' => $x - $y,
            '*' => $x * $y,
            default => fdiv($x, $y),
        };
    }
}
