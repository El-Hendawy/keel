<?php

declare(strict_types=1);

namespace Keel\Tests\Vector;

use Keel\Vector\FloatVector;
use Keel\Vector\IntVector;
use Keel\Vector\OverflowException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Random vectors checked against PHP's own functions (`array_sum`, `min`, `max`, `sort`,
 * `array_unique`, `array_filter`) or a plain reference written here. Runs against whichever
 * implementation is loaded. A failure prints its seed; `KEEL_VECTOR_SEED=<seed>` replays it.
 */
final class VectorPropertyTest extends TestCase
{
    private const CASES = 150;

    /** Lengths around 1, 4 and 8 lanes and around a page of elements; others are drawn at random. */
    private const LENGTHS = [0, 1, 3, 7, 8, 9, 15, 16, 17, 1023, 1024, 1025];

    private const EXTREMES = [PHP_INT_MIN, PHP_INT_MIN + 1, -1, 0, 1, PHP_INT_MAX - 1, PHP_INT_MAX];

    private int $seed;

    private Randomizer $random;

    protected function setUp(): void
    {
        $seed = getenv('KEEL_VECTOR_SEED');
        $this->seed = \is_string($seed) && $seed !== '' ? (int) $seed : random_int(0, PHP_INT_MAX);
        $this->random = new Randomizer(new Mt19937($this->seed));
    }

    public function testIntegerReductionsMatchPhp(): void
    {
        foreach ($this->intCases() as $case => $values) {
            $v = IntVector::fromArray($values);
            $label = $this->label($case, $values);
            $exact = self::exactSum($values);
            if ($exact !== null) {
                self::assertSame($exact, $v->sum(), $label);
                if (\is_int(array_sum($values))) {
                    self::assertSame(array_sum($values), $v->sum(), $label);
                }
            } else {
                $this->assertThrows(OverflowException::class, static fn () => $v->sum(), $label);
            }
            if ($values === []) {
                continue;
            }
            self::assertSame(min($values), $v->min(), $label);
            self::assertSame(max($values), $v->max(), $label);
            if ($exact !== null) {
                self::assertSame((float) $exact / \count($values), $v->mean(), $label);
            } else {
                $approx = array_sum(array_map(static fn (int $x): float => (float) $x, $values)) / \count($values);
                self::assertEqualsWithDelta($approx, $v->mean(), abs($approx) * 1e-12, $label);
            }
        }
    }

    public function testIntegerOrderingMatchesPhp(): void
    {
        foreach ($this->intCases() as $case => $values) {
            $v = IntVector::fromArray($values);
            $label = $this->label($case, $values);
            $sorted = $values;
            sort($sorted);
            self::assertSame($sorted, $v->sort()->toArray(), $label);
            self::assertSame(array_values(array_unique($values)), $v->unique()->toArray(), $label);

            $pivot = $values === [] ? 0 : $values[$this->random->getInt(0, \count($values) - 1)];
            self::assertSame(array_keys(array_filter($values, static fn (int $x): bool => $x > $pivot)), $v->whereGreaterThan($pivot)->toArray(), $label);
            self::assertSame(array_keys(array_filter($values, static fn (int $x): bool => $x < $pivot)), $v->whereLessThan($pivot)->toArray(), $label);
            self::assertSame(array_keys($values, $pivot, true), $v->whereEquals($pivot)->toArray(), $label);
            [$lo, $hi] = [min($pivot, 0), max($pivot, 0)];
            self::assertSame(array_keys(array_filter($values, static fn (int $x): bool => $x >= $lo && $x <= $hi)), $v->whereBetween($lo, $hi)->toArray(), $label);
            self::assertSame($values, $v->take($v->whereBetween(PHP_INT_MIN, PHP_INT_MAX))->toArray(), $label);
        }
    }

    public function testIntegerArithmeticOverflowsExactlyWherePhpWould(): void
    {
        // Every pair of extremes first (one pair per vector, so an early overflow hides nothing).
        $cases = [];
        foreach (self::EXTREMES as $x) {
            foreach (self::EXTREMES as $y) {
                $cases["$x and $y"] = [[$x], [$y]];
            }
        }
        foreach ($this->intCases() as $case => $values) {
            $cases[$case] = [$values, $this->ints(\count($values), $this->random->getInt(0, 3))];
        }
        foreach ($cases as $case => [$values, $other]) {
            $label = $this->label((string) $case, $values);
            $operations = [
                'add' => static fn (int $x, int $y): int|float => array_sum([$x, $y]),
                'sub' => static fn (int $x, int $y): int|float => $y === PHP_INT_MIN ? ($x < 0 ? $x - $y : 1.0) : array_sum([$x, -$y]),
                'mul' => static fn (int $x, int $y): int|float => array_product([$x, $y]),
            ];
            foreach ($operations as $method => $reference) {
                $expected = [];
                $overflow = null;
                foreach ($values as $i => $x) {
                    $r = $reference($x, $other[$i]);
                    if (!\is_int($r)) {
                        $overflow = $i;
                        break;
                    }
                    $expected[] = $r;
                }
                $call = static fn (): IntVector => IntVector::fromArray($values)->{$method}(IntVector::fromArray($other));
                if ($overflow === null) {
                    self::assertSame($expected, $call()->toArray(), "$method $label");
                } else {
                    $this->assertThrows(OverflowException::class, $call, "$method $label", "at index $overflow ");
                }
                if (\count($other) === 1) {
                    $scalar = static fn (): IntVector => IntVector::fromArray($values)->{$method}($other[0]);
                    $overflow === null
                        ? self::assertSame($expected, $scalar()->toArray(), "$method scalar $label")
                        : $this->assertThrows(OverflowException::class, $scalar, "$method scalar $label");
                }
            }

            $small = array_map(static fn (int $x): int => $x % 1_000_000, $values);
            $weights = array_map(static fn (int $x): int => $x % 1_000_000, $other);
            $dot = 0;
            foreach ($small as $i => $x) {
                $dot += $x * $weights[$i];
            }
            self::assertSame($dot, IntVector::fromArray($small)->dot(IntVector::fromArray($weights)), $label);
        }
    }

    public function testFloatReductionsMatchPhp(): void
    {
        foreach ($this->floatCases() as $case => $values) {
            $v = FloatVector::fromArray($values);
            $label = $this->label($case, $values);
            self::assertSame(self::bits(self::laneSum($values)), self::bits($v->sum()), "fixed-order sum $label");
            $finite = array_filter($values, static fn (float $x): bool => is_finite($x)) === $values;
            if ($finite) {
                $scale = array_sum(array_map(abs(...), $values));
                if (is_finite($scale)) {
                    self::assertEqualsWithDelta(array_sum($values), $v->sum(), $scale * 1e-15 * \count($values), $label);
                }
            }
            $weights = $this->floats(\count($values), 0);
            $products = array_map(static fn (float $x, float $y): float => $x * $y, $values, $weights);
            self::assertSame(self::bits(self::laneSum($products)), self::bits($v->dot(FloatVector::fromArray($weights))), "dot $label");
            if ($values === []) {
                continue;
            }

            $numbers = array_values(array_filter($values, static fn (float $x): bool => !is_nan($x)));
            if (\count($numbers) < \count($values)) {
                self::assertNan($v->min(), $label);
                self::assertNan($v->max(), $label);
            } else {
                self::assertSame(min($values), $v->min(), $label);
                self::assertSame(max($values), $v->max(), $label);
            }
        }
    }

    public function testFloatOrderingMatchesPhp(): void
    {
        foreach ($this->floatCases() as $case => $values) {
            $v = FloatVector::fromArray($values);
            $label = $this->label($case, $values);
            $numbers = array_values(array_filter($values, static fn (float $x): bool => !is_nan($x)));
            $sorted = $numbers;
            sort($sorted);
            $actual = $v->sort()->toArray();
            // PHP's sort() keeps -0.0 and 0.0 in input order; vectors put -0.0 first.
            self::assertSame(array_map(self::zero(...), $sorted), array_map(self::zero(...), \array_slice($actual, 0, \count($numbers))), $label);
            self::assertSame(\count($values) - \count($numbers), \count(array_filter($actual, is_nan(...))), "NaN last $label");
            foreach (\array_slice($actual, 1, max(0, \count($numbers) - 1)) as $i => $x) {
                self::assertTrue(self::before($actual[$i], $x), "-0.0 before 0.0 $label");
            }

            // array_unique() cannot compare NaN, so check it on the numbers alone.
            self::assertSame(
                array_map(self::bits(...), array_values(array_unique($numbers, SORT_REGULAR))),
                array_map(self::bits(...), FloatVector::fromArray($numbers)->unique()->toArray()),
                $label,
            );

            $pivot = $numbers === [] ? 0.0 : $numbers[$this->random->getInt(0, \count($numbers) - 1)];
            self::assertSame(array_keys(array_filter($values, static fn (float $x): bool => $x > $pivot)), $v->whereGreaterThan($pivot)->toArray(), $label);
            self::assertSame(array_keys(array_filter($values, static fn (float $x): bool => $x === $pivot)), $v->whereEquals($pivot)->toArray(), $label);
            self::assertSame(
                array_map(self::bits(...), array_map(static fn (float $x): float => $x / 3.0, $values)),
                array_map(self::bits(...), $v->div(3)->toArray()),
                $label,
            );
        }
    }

    /** @return \Generator<string, list<int>> */
    private function intCases(): \Generator
    {
        for ($case = 0; $case < self::CASES; $case++) {
            $length = $case < \count(self::LENGTHS) * 2 ? self::LENGTHS[$case % \count(self::LENGTHS)] : $this->random->getInt(0, 2000);
            $profile = $case % 4;
            yield "#$case length $length profile $profile" => $this->ints($length, $profile);
        }
    }

    /** @return \Generator<string, list<float>> */
    private function floatCases(): \Generator
    {
        for ($case = 0; $case < self::CASES; $case++) {
            $length = $case < \count(self::LENGTHS) * 2 ? self::LENGTHS[$case % \count(self::LENGTHS)] : $this->random->getInt(0, 2000);
            $profile = $case % 3;
            yield "#$case length $length profile $profile" => $this->floats($length, $profile);
        }
    }

    /**
     * Profile 0: small values; 1: about ±2^40; 2: extremes sprinkled in; 3: the whole int range.
     *
     * @return list<int>
     */
    private function ints(int $length, int $profile): array
    {
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $out[] = match ($profile) {
                0 => $this->random->getInt(-100, 100),
                1 => $this->random->getInt(-(1 << 40), 1 << 40),
                2 => $this->random->getInt(0, 3) === 0 ? self::EXTREMES[$this->random->getInt(0, 6)] : $this->random->getInt(-1000, 1000),
                default => $this->random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            };
        }

        return $out;
    }

    /**
     * Profile 0: ordinary values; 1: with NaN, ±INF, ±0.0 and subnormals; 2: only ±0.0 and a few values.
     *
     * @return list<float>
     */
    private function floats(int $length, int $profile): array
    {
        $specials = [NAN, INF, -INF, 0.0, -0.0, PHP_FLOAT_MIN / 4, PHP_FLOAT_MAX, -PHP_FLOAT_MAX, 1e-300];
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $value = ($this->random->nextFloat() - 0.5) * 1e6;
            $out[] = match ($profile) {
                0 => $value,
                1 => $this->random->getInt(0, 4) === 0 ? $specials[$this->random->getInt(0, 8)] : $value,
                default => [0.0, -0.0, 1.0, -1.0][$this->random->getInt(0, 3)],
            };
        }

        return $out;
    }

    /**
     * The exact sum, or null when it does not fit in an int: the high and low 32 bits are added
     * separately, so neither total can overflow for fewer than 2^31 values.
     *
     * @param list<int> $values
     */
    private static function exactSum(array $values): ?int
    {
        $high = 0;
        $low = 0;
        foreach ($values as $x) {
            $high += $x >> 32;
            $low += $x & 0xFFFFFFFF;
        }
        $high += $low >> 32;
        $low &= 0xFFFFFFFF;

        return $high >= -(1 << 31) && $high < (1 << 31) ? ($high << 32) | $low : null;
    }

    /** @param list<float> $values */
    private static function laneSum(array $values): float
    {
        $t = array_fill(0, 8, 0.0);
        foreach ($values as $i => $x) {
            $t[$i % 8] += $x;
        }

        return (($t[0] + $t[1]) + ($t[2] + $t[3])) + (($t[4] + $t[5]) + ($t[6] + $t[7]));
    }

    /** The bits of a float, with every NaN the same, so results compare with assertSame(). */
    private static function bits(float $x): string
    {
        return is_nan($x) ? 'NaN' : bin2hex(pack('E', $x));
    }

    /** Folds -0.0 into 0.0, for comparing with PHP's sort(), which does not order them. */
    private static function zero(float $x): float
    {
        return $x === 0.0 ? 0.0 : $x;
    }

    private static function before(float $a, float $b): bool
    {
        return $a < $b || ($a === $b && (fdiv(1, $a) <= fdiv(1, $b) || $a !== 0.0));
    }

    /** @param list<int|float> $values */
    private function label(string $case, array $values): string
    {
        $head = implode(', ', array_map(static fn (int|float $x): string => var_export($x, true), \array_slice($values, 0, 6)));

        return "case $case [$head" . (\count($values) > 6 ? ', ...' : '') . "] (replay with KEEL_VECTOR_SEED={$this->seed})";
    }

    /** @param class-string<\Throwable> $class */
    private function assertThrows(string $class, callable $call, string $label, string $message = ''): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, "$label: {$e->getMessage()}");
            self::assertStringContainsString($message, $e->getMessage(), $label);

            return;
        }
        self::fail("Expected $class: $label");
    }
}
