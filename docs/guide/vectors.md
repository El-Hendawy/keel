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
