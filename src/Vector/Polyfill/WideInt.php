<?php

declare(strict_types=1);

namespace Keel\Vector\Polyfill;

/**
 * Exact sums of int products, wider than 64 bits, for the pure-PHP IntVector. Used only when the
 * fast path (plain int arithmetic) overflows somewhere, so results match the extension's 128-bit
 * arithmetic exactly.
 *
 * A total is a little-endian list of 24-bit limbs; every limb but the last is in [0, 2^24), the last
 * carries the sign. Each int splits into limbs [0, 2^24), [0, 2^24), [-2^15, 2^15), so limb products
 * stay below 2^49 and thousands of them can be added before carrying.
 *
 * @internal
 */
final class WideInt
{
    private const MASK = 0xFFFFFF;

    /** Products added between carries: 4096 * 3 * 2^49 < 2^63. */
    private const BATCH = 4096;

    /**
     * The exact sum of `$a[i] * $b[i]`, or of `$a[i]` alone when `$b` is null.
     *
     * @param list<int> $a
     * @param list<int>|null $b same length as $a
     *
     * @return array<int, int>
     */
    public static function sum(array $a, ?array $b = null): array
    {
        $acc = [0, 0, 0, 0, 0];
        foreach ($a as $i => $x) {
            $x0 = $x & self::MASK;
            $x1 = ($x >> 24) & self::MASK;
            $x2 = $x >> 48;
            if ($b === null) {
                $acc[0] += $x0;
                $acc[1] += $x1;
                $acc[2] += $x2;
            } else {
                $y = $b[$i];
                $y0 = $y & self::MASK;
                $y1 = ($y >> 24) & self::MASK;
                $y2 = $y >> 48;
                $acc[0] += $x0 * $y0;
                $acc[1] += $x0 * $y1 + $x1 * $y0;
                $acc[2] += $x0 * $y2 + $x1 * $y1 + $x2 * $y0;
                $acc[3] += $x1 * $y2 + $x2 * $y1;
                $acc[4] += $x2 * $y2;
            }
            if (($i + 1) % self::BATCH === 0) {
                self::carry($acc);
            }
        }
        self::carry($acc);

        return $acc;
    }

    /**
     * The total as an int, or null when it does not fit in 64 bits.
     *
     * @param array<int, int> $acc carried
     */
    public static function toInt(array $acc): ?int
    {
        [$l0, $l1, $l2, $l3, $l4] = $acc;
        // value = low + high * 2^72, with low = l0 + l1 * 2^24 + l2 * 2^48 in [0, 2^72).
        if ($l3 === 0 && $l4 === 0) {
            return $l2 < 0x8000 ? ($l2 << 48) | ($l1 << 24) | $l0 : null;
        }
        if ($l3 === self::MASK && $l4 === -1) {
            return $l2 >= 0x1000000 - 0x8000 ? (($l2 - 0x1000000) << 48) + ($l1 << 24) + $l0 : null;
        }

        return null;
    }

    /**
     * The total rounded once to the nearest float (ties to even), like converting an i128.
     *
     * @param array<int, int> $acc carried
     */
    public static function toFloat(array $acc): float
    {
        $negative = $acc[\count($acc) - 1] < 0;
        if ($negative) {
            $acc = array_map(static fn (int $limb): int => -$limb, $acc);
            self::carry($acc);
        }
        // Top limb below 2^24 too, so every limb fits the base for the decimal conversion.
        while ($acc[\count($acc) - 1] > self::MASK) {
            $top = \count($acc) - 1;
            $acc[] = $acc[$top] >> 24;
            $acc[$top] &= self::MASK;
        }
        // Repeated division by 10^9, most significant limb first.
        $digits = '';
        $limbs = array_reverse($acc);
        while ($limbs !== [] && $limbs !== [0]) {
            $remainder = 0;
            $quotient = [];
            foreach ($limbs as $limb) {
                $value = $remainder * 0x1000000 + $limb;
                $q = intdiv($value, 1_000_000_000);
                $remainder = $value - $q * 1_000_000_000;
                if ($quotient !== [] || $q !== 0) {
                    $quotient[] = $q;
                }
            }
            $digits = str_pad((string) $remainder, 9, '0', STR_PAD_LEFT) . $digits;
            $limbs = $quotient;
        }
        $digits = ltrim($digits, '0');

        // PHP's string-to-float conversion is correctly rounded, as is Rust's i128-to-f64.
        return (float) (($negative ? '-' : '') . ($digits === '' ? '0' : $digits));
    }

    /**
     * Moves each limb's excess into the next one; the last limb keeps the sign.
     *
     * @param array<int, int> $acc
     */
    private static function carry(array &$acc): void
    {
        $last = \count($acc) - 1;
        for ($k = 0; $k < $last; $k++) {
            $acc[$k + 1] += $acc[$k] >> 24;
            $acc[$k] &= self::MASK;
        }
    }
}
