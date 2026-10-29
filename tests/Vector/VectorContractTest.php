<?php

declare(strict_types=1);

namespace Keel\Tests\Vector;

use Keel\Vector\FloatVector;
use Keel\Vector\IntVector;
use Keel\Vector\LengthMismatchException;
use Keel\Vector\OverflowException;
use Keel\Vector\VectorException;
use Keel\Vector\VectorInfo;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every implementation of the vectors must share. Runs against whichever is loaded:
 * the pure-PHP one by default, the extension with `php -d extension=keel_vector.so vendor/bin/phpunit`.
 */
final class VectorContractTest extends TestCase
{
    public function testReportsTheBackend(): void
    {
        self::assertSame(\extension_loaded('keel_vector'), VectorInfo::isNative());
        self::assertContains(VectorInfo::simdLevel(), VectorInfo::isNative() ? ['avx2', 'sse4.2', 'neon', 'scalar'] : ['php']);
        self::assertSame('0.1.0', VectorInfo::version());
    }

    public function testConstructionAndConversion(): void
    {
        self::assertSame([5, -3, 8], IntVector::fromArray(['a' => 5, 'b' => -3, 'c' => 8])->toArray(), 'keys are ignored');
        self::assertSame([1, 2], (new IntVector([1, 2]))->toArray());
        self::assertSame([], (new IntVector())->toArray());
        self::assertSame([1.0, 2.5], FloatVector::fromArray([1, 2.5])->toArray(), 'ints become floats');
        self::assertSame([7, 7, 7], IntVector::filled(3, 7)->toArray());
        self::assertSame([3.0, 3.0], FloatVector::filled(2, 3)->toArray());
        self::assertSame([0, 3, 6, 9], IntVector::range(0, 10, 3)->toArray());
        self::assertSame([3, 2, 1, 0], IntVector::range(3, 0)->toArray());
        self::assertSame([0.0, 0.25, 0.5, 0.75, 1.0], FloatVector::range(0, 1, 0.25)->toArray());
        self::assertCount(11, FloatVector::range(0, 1, 0.1), 'the end is kept despite rounding');
        self::assertTrue(IntVector::empty()->isEmpty());
        self::assertGreaterThanOrEqual(80, IntVector::filled(10, 0)->memoryBytes());
        self::assertSame(0, IntVector::empty()->memoryBytes());

        $this->throws(\TypeError::class, 'at key 1 is float', fn () => IntVector::fromArray([1, 2.0]));
        $this->throws(\TypeError::class, 'at key b is string', fn () => FloatVector::fromArray(['a' => 1, 'b' => '2']));
        $this->throws(\TypeError::class, 'bool given', fn () => IntVector::filled(1, true));
        $this->throws(\ValueError::class, 'greater than or equal to 0', fn () => IntVector::filled(-1, 0));
        $this->throws(\ValueError::class, 'cannot be 0', fn () => IntVector::range(0, 1, 0));
        $this->throws(\ValueError::class, 'finite', fn () => FloatVector::range(0, INF));
    }

    public function testAccessAndMutation(): void
    {
        $v = IntVector::fromArray([1, 2, 3]);
        $v->push(4);
        $v->set(0, 10);
        $v[] = 5;
        $v[1] += 20;
        self::assertSame([10, 22, 3, 4, 5], $v->toArray());
        self::assertSame(5, $v->pop());
        self::assertSame([10, 22, 3, 4], $v->toArray());
        self::assertSame([3, 4], $v->slice(-2)->toArray());
        self::assertSame([22, 3], $v->slice(1, -1)->toArray());
        self::assertSame([], $v->slice(10)->toArray());
        self::assertTrue(isset($v[3]));
        self::assertFalse(isset($v[4]));
        self::assertFalse(isset($v[-1]));
        self::assertFalse(isset($v['0']));
        self::assertCount(4, $v);

        $this->throws(\OutOfRangeException::class, 'index 4 is out of range; valid indices are 0 to 3', fn () => $v->get(4));
        $this->throws(\OutOfRangeException::class, 'index -1', fn () => $v[-1]);
        $this->throws(\OutOfRangeException::class, 'index 9', function () use ($v): void { $v[9] = 1; });
        $this->throws(\TypeError::class, 'string given', fn () => $v['x']);
        $this->throws(\TypeError::class, 'must be of type int, float given', fn () => $v->push(1.5));
        $this->throws(VectorException::class, 'cannot be unset', function () use ($v): void { unset($v[0]); });
        $this->throws(\UnderflowException::class, 'empty', fn () => IntVector::empty()->pop());
    }

    public function testCopiesAreIndependent(): void
    {
        $a = IntVector::fromArray([1, 2, 3]);
        $b = clone $a;
        $b[0] = 99;
        self::assertSame([1, 2, 3], $a->toArray());

        $seen = [];
        foreach ($a as $i => $value) {
            $seen[$i] = $value;
            $a[0] = 42;
        }
        self::assertSame([1, 2, 3], $seen, 'foreach walks the values as they were when it started');
        self::assertSame(42, $a[0]);

        $f = FloatVector::fromArray([0.1, -0.0, NAN]);
        $copy = unserialize(serialize($f));
        self::assertInstanceOf(FloatVector::class, $copy);
        self::assertSame(0.1, $copy[0]);
        self::assertTrue(is_nan($copy[2]));
        self::assertSame('[1,2]', json_encode(IntVector::fromArray([1, 2])));
        self::assertSame([1, 2], unserialize(serialize(IntVector::fromArray([1, 2])))->toArray());
        $this->throws(\TypeError::class, 'IntVector::__unserialize()', fn () => unserialize('O:21:"Keel\Vector\IntVector":1:{i:0;s:1:"x";}'));
    }

    public function testIntegerReductionsAreExact(): void
    {
        $v = IntVector::fromArray([5, -3, 8, 8, 1]);
        self::assertSame([19, -3, 8, 3.8], [$v->sum(), $v->min(), $v->max(), $v->mean()]);
        self::assertSame(38, $v->dot(IntVector::filled(5, 2)));
        self::assertSame(0, IntVector::empty()->sum());
        self::assertSame(PHP_INT_MAX - 4, IntVector::fromArray([PHP_INT_MAX, 1, -5])->sum(), 'intermediate overflow is fine');
        self::assertSame(9.223372036854776E18, IntVector::fromArray([PHP_INT_MAX, PHP_INT_MAX])->mean());
        self::assertSame(0, IntVector::fromArray([PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN])
            ->dot(IntVector::fromArray([PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX, 2])), 'exact through 128-bit wraps');

        $this->throws(OverflowException::class, 'sum does not fit', fn () => IntVector::fromArray([PHP_INT_MAX, 1])->sum());
        $this->throws(OverflowException::class, 'dot product does not fit', fn () => IntVector::filled(4, PHP_INT_MIN)->dot(IntVector::filled(4, PHP_INT_MIN)));
        $this->throws(\UnderflowException::class, 'IntVector::min()', fn () => IntVector::empty()->min());
        $this->throws(\UnderflowException::class, 'IntVector::mean()', fn () => IntVector::empty()->mean());
        $this->throws(LengthMismatchException::class, 'have 5 and 2 elements', fn () => $v->dot(IntVector::filled(2, 1)));
        $this->throws(\TypeError::class, 'must be of type Keel\Vector\IntVector, Keel\Vector\FloatVector given', fn () => $v->dot(FloatVector::filled(5, 1)));
    }

    public function testIntegerElementWiseOperationsAreChecked(): void
    {
        $v = IntVector::fromArray([5, -3, 8]);
        self::assertSame([6, -2, 9], $v->add(IntVector::filled(3, 1))->toArray());
        self::assertSame([4, -4, 7], $v->sub(1)->toArray());
        self::assertSame([15, -9, 24], $v->mul(3)->toArray());
        self::assertSame([5, 3, 8], $v->abs()->toArray());

        $this->throws(OverflowException::class, '9223372036854775807 + 1 at index 1', fn () => IntVector::fromArray([0, PHP_INT_MAX])->add(1));
        $this->throws(OverflowException::class, 'at index 0', fn () => IntVector::fromArray([PHP_INT_MIN])->sub(IntVector::fromArray([1])));
        $this->throws(OverflowException::class, 'at index 0', fn () => IntVector::fromArray([PHP_INT_MIN])->abs());
        $this->throws(LengthMismatchException::class, 'have 3 and 2 elements', fn () => $v->add(IntVector::filled(2, 1)));
        $this->throws(\TypeError::class, 'IntVector|int, float given', fn () => $v->add(1.5));
    }

    public function testFloatOperations(): void
    {
        $f = FloatVector::fromArray([1.5, -2.0, 4.0]);
        self::assertSame([3.5, 2.0, 1.5], [$f->sum(), $f->max() - $f->min() - 4.0, $f->abs()->min()]);
        self::assertSame([3.0, -4.0, 8.0], $f->mul(2)->toArray());
        self::assertSame([0.75, -1.0, 2.0], $f->div(2.0)->toArray());
        self::assertSame([2.5, -1.0, 5.0], $f->add(FloatVector::filled(3, 1))->toArray());
        self::assertSame(['INF', '-INF', 'NAN'], array_map(strval(...), FloatVector::fromArray([1, -1, 0])->div(0)->toArray()));
        self::assertSame(11.0, FloatVector::fromArray([1, 2])->dot(FloatVector::fromArray([3, 4])));

        $values = array_map(static fn (int $i): float => $i * 0.1, range(0, 36));
        $lanes = array_fill(0, 8, 0.0);
        foreach ($values as $i => $x) {
            $lanes[$i % 8] += $x;
        }
        $expected = (($lanes[0] + $lanes[1]) + ($lanes[2] + $lanes[3])) + (($lanes[4] + $lanes[5]) + ($lanes[6] + $lanes[7]));
        self::assertSame($expected, FloatVector::fromArray($values)->sum(), 'sums use the documented fixed order');
        self::assertEqualsWithDelta(array_sum($values), FloatVector::fromArray($values)->sum(), 1e-9 * abs(array_sum($values)));

        $special = FloatVector::fromArray([1.5, -0.0, 0.0, NAN, 3]);
        self::assertTrue(is_nan($special->min()) && is_nan($special->max()));
        self::assertSame(-INF, fdiv(1, FloatVector::fromArray([0.0, -0.0])->min()));
        self::assertSame(INF, fdiv(1, FloatVector::fromArray([-0.0, 0.0])->max()));
        $sorted = $special->sort()->toArray();
        self::assertSame([-INF, INF, 1.5, 3.0], [fdiv(1, $sorted[0]), fdiv(1, $sorted[1]), $sorted[2], $sorted[3]]);
        self::assertTrue(is_nan($sorted[4]));
        self::assertCount(3, FloatVector::fromArray([0.0, -0.0, NAN, NAN, 1])->unique(), '-0.0 repeats 0.0 and NaN repeats NaN');
        self::assertSame([1.5, -0.0, 3.0], array_values(array_filter($special->unique()->toArray(), static fn (float $x): bool => !is_nan($x))));
        $this->throws(\TypeError::class, 'FloatVector|float|int, string given', fn () => $f->add('1'));
    }

    public function testComparisonsGatherSortUnique(): void
    {
        $v = IntVector::fromArray([5, -3, 8, 8, 1]);
        self::assertSame([0, 2, 3], $v->whereGreaterThan(1)->toArray());
        self::assertSame([1], $v->whereLessThan(1)->toArray());
        self::assertSame([2, 3], $v->whereEquals(8)->toArray());
        self::assertSame([0, 4], $v->whereBetween(1, 5)->toArray());
        self::assertSame([1, 5, 5], $v->take(IntVector::fromArray([4, 0, 0]))->toArray());
        self::assertSame([-3, 1, 5, 8, 8], $v->sort()->toArray());
        self::assertSame([5, -3, 8, 1], $v->unique()->toArray());

        $f = FloatVector::fromArray([0.5, NAN, 2.0, -1.0]);
        self::assertSame([2], $f->whereGreaterThan(1)->toArray(), 'NaN never matches');
        self::assertSame([0, 2], $f->whereBetween(0, 2)->toArray());
        self::assertSame([2.0, 0.5], $f->take(IntVector::fromArray([2, 0]))->toArray());

        $this->throws(\OutOfRangeException::class, 'index 5', fn () => $v->take(IntVector::fromArray([0, 5])));
        $this->throws(\TypeError::class, 'must be of type int, float given', fn () => $v->whereGreaterThan(1.5));
    }

    public function testCallbacks(): void
    {
        $v = IntVector::fromArray([1, 2, 3, 4]);
        self::assertSame([2, 4, 6, 8], $v->map(static fn (int $x): int => $x * 2)->toArray());
        self::assertSame([2, 4], $v->filter(static fn (int $x): bool => $x % 2 === 0)->toArray());
        self::assertSame([0.5, 1.0], FloatVector::fromArray([1, 2])->map(static fn (float $x): float => $x / 2)->toArray());

        $this->throws(\TypeError::class, 'callback must return int, string returned', fn () => $v->map(static fn (int $x): string => 'x'));
        $this->throws(\LogicException::class, 'boom', fn () => $v->map(static function (): never { throw new \LogicException('boom'); }));
    }

    public function testHierarchy(): void
    {
        self::assertTrue(is_subclass_of(VectorException::class, \RuntimeException::class));
        self::assertTrue(is_subclass_of(OverflowException::class, VectorException::class));
        self::assertTrue(is_subclass_of(LengthMismatchException::class, VectorException::class));
        $class = new \ReflectionClass(IntVector::class);
        self::assertTrue($class->isFinal());
        foreach ([\Countable::class, \ArrayAccess::class, \IteratorAggregate::class, \JsonSerializable::class] as $interface) {
            self::assertTrue($class->implementsInterface($interface), $interface);
            self::assertTrue((new \ReflectionClass(FloatVector::class))->implementsInterface($interface), $interface);
        }
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function throws(string $class, string $message, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }
        self::fail("Expected $class");
    }
}
