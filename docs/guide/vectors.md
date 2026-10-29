# Numeric vectors

`Keel\Vector\IntVector` and `FloatVector` hold large lists of 64-bit integers or floats in contiguous
memory. With the `keel_vector` extension they take 8 bytes per element and run their loops in Rust with
SIMD; without it, Keel uses a pure-PHP implementation with the same classes, results and errors.

```php
use Keel\Vector\FloatVector;
use Keel\Vector\IntVector;

$prices = FloatVector::fromArray($rows);
$prices->mean();
$expensive = $prices->whereGreaterThan(100.0);   // an IntVector of indices
$prices->take($expensive)->sum();

$quantities = IntVector::fromArray([3, 1, 4]);
$quantities->dot(IntVector::fromArray([10, 20, 30]));   // 170, exact
```

- Integer results are exact. `sum()`, `dot()` and `add`/`sub`/`mul`/`abs` throw
  `Keel\Vector\OverflowException` instead of wrapping or turning into floats.
- Float sums and dot products add in one fixed order, so results are identical on every machine and with
  or without the extension. They may differ from a left-to-right `array_sum()` in the last bits.
- Vectors are `Countable`, `ArrayAccess` (`$v[] = 4` appends; out-of-range indices throw
  `OutOfRangeException`), iterable, JSON-serializable and serializable.

The full API is in `stubs/keel_vector.stub.php`.

## As API types

Input and output classes can use vectors. A field takes a JSON array of numbers, validated item by item
(`values[2]`: `type`), and is documented in OpenAPI as an array of `integer`/`int64` or `number`/`double`.
Limit the size with `#[Count]`:

```php
final readonly class ReadingsInput
{
    public function __construct(
        #[Count(min: 1, max: 100_000)] public FloatVector $values,
        public ?IntVector $weights = null,
    ) {}
}
```

## The extension

```bash
ext/keel_vector/build.sh        # needs Rust (https://rustup.rs) and the PHP headers
keel vector:info                # what is loaded, and the SIMD level
```

Add the `extension=` line `build.sh` prints to php.ini, then reload PHP-FPM or restart `keel run`. To make
production refuse to boot without it, set:

```php
'vector' => ['require_native' => true],
```

`ext/keel_vector/README.md` has build steps for Linux (x86_64, aarch64) and macOS, php.ini and restart
instructions, and the complete semantics. Two limitations matter in production:

- **`memory_limit` does not count vector data.** The extension stores elements outside PHP's memory
  manager, so `memory_limit` and `memory_get_usage()` don't see them. Limit sizes where data comes in
  (`#[Count(max: ...)]` on API inputs), and use `memoryBytes()` to measure.
- **Windows is not supported** (ext-php-rs needs nightly Rust there). Windows uses the pure-PHP
  implementation.

## Performance

`benchmarks/vectors.php` compares plain arrays, the pure-PHP vectors and the extension, and checks that
all three give the same results; `benchmarks/RESULTS.md` has one machine's numbers. On an Apple M4, the
extension runs sums 7–16× faster than `array_sum()`, element-wise arithmetic and dot products 50–90×
faster than a `foreach` loop, and sorts 5–8× faster than `sort()`, in a third of the memory.

Converting between arrays and vectors (`fromArray()`, `toArray()`) costs about as much as one cheap
operation. Vectors pay off when data stays a vector across several operations. The pure-PHP vectors
are for portability: they give the same results, but run slower than hand-written array code.

```bash
php benchmarks/vectors.php --extension=ext/keel_vector/target/release/keel_vector.so [--jit] [--markdown]
```

## Testing

Both implementations must give the same results, so the same PHPUnit suites (`tests/Vector`) run against
each: a contract suite, and property tests that compare random vectors with `array_sum()`, `min()`, `max()`,
`sort()`, `array_unique()` and `array_filter()`. A failing property test prints its seed;
`KEEL_VECTOR_SEED=<seed>` replays it. In `ext/keel_vector`:

```bash
make test        # Rust: every kernel at every SIMD level the CPU runs, against a scalar reference
make test-php    # the PHP suites with the pure-PHP implementation, then with the extension
make valgrind    # Linux: the PHP suites under valgrind
make asan        # macOS, nightly Rust: the PHP suites with an AddressSanitizer build
```
