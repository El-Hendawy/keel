<?php

declare(strict_types=1);

namespace Keel\Vector\Polyfill;

use Keel\Vector\LengthMismatchException;

/**
 * Checks and messages shared by the pure-PHP vectors, worded exactly like the extension's.
 *
 * @internal
 */
final class Support
{
    /** Whether `$offset` is an int index into a list of `$count` elements (no string coercion). */
    public static function isIndex(mixed $offset, int $count): bool
    {
        return \is_int($offset) && $offset >= 0 && $offset < $count;
    }

    /** `$a + $b`, or null when it does not fit in an int. */
    public static function add(int $a, int $b): ?int
    {
        return ($b > 0 ? $a > PHP_INT_MAX - $b : $a < PHP_INT_MIN - $b) ? null : $a + $b;
    }

    /** `$a - $b`, or null when it does not fit in an int. */
    public static function sub(int $a, int $b): ?int
    {
        return ($b < 0 ? $a > PHP_INT_MAX + $b : $a < PHP_INT_MIN + $b) ? null : $a - $b;
    }

    /** `$a * $b`, or null when it does not fit in an int. */
    public static function mul(int $a, int $b): ?int
    {
        $overflows = match (true) {
            $a === 0 || $b === 0 => false,
            $a > 0 => $b > 0 ? $a > intdiv(PHP_INT_MAX, $b) : $b < intdiv(PHP_INT_MIN, $a),
            default => $b > 0 ? $a < intdiv(PHP_INT_MIN, $b) : $b < intdiv(PHP_INT_MAX, $a),
        };

        return $overflows ? null : $a * $b;
    }

    /**
     * The sum, or null when it, or any partial sum, does not fit in an int.
     *
     * @param list<int> $values
     */
    public static function sum(array $values): ?int
    {
        $sum = 0;
        foreach ($values as $v) {
            if ($v > 0 ? $sum > PHP_INT_MAX - $v : $sum < PHP_INT_MIN - $v) {
                return null;
            }
            $sum += $v;
        }

        return $sum;
    }

    /** The PHP name of a value's type, as the extension prints it. */
    public static function typeName(mixed $value): string
    {
        return match (true) {
            \is_object($value) => $value::class,
            \is_resource($value) || get_debug_type($value) === 'resource (closed)' => 'resource',
            default => get_debug_type($value),
        };
    }

    public static function typeError(string $class, string $method, string $argument, string $expected, mixed $value): \TypeError
    {
        return new \TypeError("$class::$method(): Argument $argument must be of type $expected, " . self::typeName($value) . ' given');
    }

    public static function badIndex(string $class, string $method, int $index, int $length): \OutOfRangeException
    {
        $valid = $length === 0 ? 'the vector is empty' : 'valid indices are 0 to ' . ($length - 1);

        return new \OutOfRangeException("$class::$method(): index $index is out of range; $valid");
    }

    public static function position(string $class, string $method, int $index, int $length): int
    {
        if ($index < 0 || $index >= $length) {
            throw self::badIndex($class, $method, $index, $length);
        }

        return $index;
    }

    /** The integer offset given to `$vector[...]`. */
    public static function offset(string $class, string $method, mixed $offset): int
    {
        if (!\is_int($offset)) {
            throw new \TypeError("$class::$method(): the offset must be of type int, " . self::typeName($offset) . ' given');
        }

        return $offset;
    }

    public static function lengthMismatch(string $class, string $method, int $left, int $right): LengthMismatchException
    {
        return new LengthMismatchException(
            "$class::$method(): the vectors have $left and $right elements; element-wise operations need equal lengths (use slice() to align them)",
        );
    }

    public static function underflow(string $class, string $method): \UnderflowException
    {
        return new \UnderflowException("$class::$method(): the vector is empty; check isEmpty() first");
    }

    public static function negativeLength(string $class): \ValueError
    {
        return new \ValueError("$class::filled(): Argument #1 (\$length) must be greater than or equal to 0");
    }

    public static function cannotUnset(string $class): \Keel\Vector\VectorException
    {
        return new \Keel\Vector\VectorException(
            "$class::offsetUnset(): a vector has no holes, so its elements cannot be unset; use slice(), filter() or pop()",
        );
    }

    /**
     * The bytes a packed PHP array of `$count` elements holds: 16 per slot, slots growing in powers
     * of two from 8, plus the table header. An estimate: PHP does not report it per array.
     */
    public static function arrayBytes(int $count): int
    {
        if ($count === 0) {
            return 0;
        }
        $slots = 8;
        while ($slots < $count) {
            $slots *= 2;
        }

        return 56 + 16 * $slots;
    }
}
