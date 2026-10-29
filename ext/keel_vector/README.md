# keel_vector

A PHP extension, written in Rust with [ext-php-rs](https://github.com/davidcole1340/ext-php-rs), that
gives Keel's `Keel\Vector\IntVector` and `FloatVector` contiguous storage (8 bytes per element) and SIMD
loops. The SIMD level (AVX2, SSE4.2, NEON or scalar) is chosen when the process starts.

The extension is optional. Without it, Keel loads a pure-PHP implementation (`src/Vector/Polyfill`) with
the same classes, results, exceptions and messages, so code never checks which one is loaded. The guide
is `docs/guide/vectors.md`; `benchmarks/RESULTS.md` shows what the extension gains.

## Build

You need:
- Rust stable 1.85 or newer, from [rustup](https://rustup.rs);
- the PHP development files (`php-config` and the headers) for PHP 8.3 or newer;
- clang, which ext-php-rs uses to read the PHP headers.

### Linux (x86_64 and aarch64)

```bash
sudo apt-get install php8.3-dev clang      # Debian/Ubuntu; php-devel and clang on Fedora/RHEL
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal
ext/keel_vector/build.sh
```

The same commands work on x86_64 and aarch64 (Graviton, Ampere, Raspberry Pi 4/5). With several PHP
versions installed, choose one with `PHP_CONFIG`:

```bash
PHP_CONFIG=/usr/bin/php-config8.4 ext/keel_vector/build.sh
```

If the build stops with `Unable to generate bindings for PHP` and errors in `xmmintrin.h` or
`emmintrin.h`, the libclang that bindgen loaded comes from a different LLVM version than the clang headers
it found. Point both at one version:

```bash
LIBCLANG_PATH=/usr/lib/llvm-18/lib CLANG_PATH=/usr/bin/clang-18 ext/keel_vector/build.sh
```

### macOS (Apple silicon and Intel)

```bash
xcode-select --install                      # clang
brew install php@8.3
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal
PHP_CONFIG="$(brew --prefix php@8.3)/bin/php-config" ext/keel_vector/build.sh
```

PHP resolves the Zend symbols when it loads the extension; `.cargo/config.toml` passes the linker flag
that allows this.

### What `build.sh` does

1. Runs `cargo build --release`.
2. Copies the library to `target/release/keel_vector.so`.
3. Loads it once with `php -n` to check that it works with that PHP.
4. Prints the `extension=` line to add to php.ini.

Build the extension with the same `php-config` as the PHP that will load it. An extension built for
another PHP version, or a thread-safe (ZTS) build loaded by a non-thread-safe PHP, refuses to load.

## Install

Add the printed line to php.ini, or to a file in PHP's scan directory (`php --ini` lists it):

```ini
; /etc/php/8.3/mods-available/keel_vector.ini, enabled with `phpenmod keel_vector` on Debian/Ubuntu
extension=/opt/keel/ext/keel_vector/target/release/keel_vector.so
```

Copy the `.so` somewhere stable first if `target/` gets cleaned on deploy. Then check it:

```bash
php -m | grep keel_vector
php bin/keel vector:info        # implementation, SIMD level and PHP build
```

Every process that should use the extension must start again, because PHP loads extensions only at
startup:

| Runtime | How to restart |
| --- | --- |
| PHP-FPM | `sudo systemctl reload php8.3-fpm`. A reload restarts the master process, which loads extensions. |
| `keel run` | Restart the server. A code reload does not load extensions. |
| FrankenPHP, RoadRunner | Restart the server process. |
| CLI, queue workers, scheduler | Restart them. |

To make production refuse to boot without the extension, set `'vector' => ['require_native' => true]`.
`keel check` then reports a missing extension.

## API

`stubs/keel_vector.stub.php` lists every class and method with its types. `tests/Vector/StubTest.php`
checks that both implementations match it.

| Class | Purpose |
| --- | --- |
| `IntVector` | A list of 64-bit integers |
| `FloatVector` | A list of 64-bit floats |
| `VectorIterator` | What `foreach` walks: a snapshot of the vector |
| `VectorInfo` | `isNative()`, `simdLevel()` (`avx2`, `sse4.2`, `neon`, `scalar`, or `php`), `version()` |
| `VectorException` | Base class (extends `RuntimeException`) |
| `OverflowException` | An integer result does not fit in 64 bits |
| `LengthMismatchException` | Two vectors combined element by element have different lengths |

Both vector classes have:

| Group | Methods |
| --- | --- |
| Construct | `new`, `fromArray`, `filled`, `range`, `empty` |
| Convert | `toArray`, `jsonSerialize`, `__serialize` / `__unserialize`, `getIterator` |
| Size and access | `count`, `isEmpty`, `memoryBytes`, `get`, `set`, `push`, `pop`, `slice`, `$v[$i]`, `$v[] = $x`, `isset($v[$i])` |
| Reduce | `sum`, `min`, `max`, `mean`, `dot` |
| Element-wise | `add`, `sub`, `mul`, `abs`, plus `div` on `FloatVector`. Each takes a vector of the same length or a scalar. |
| Compare | `whereGreaterThan`, `whereLessThan`, `whereEquals`, `whereBetween`; each returns an `IntVector` of indices |
| Reorder | `take` (gather by an `IntVector` of indices), `sort`, `unique` |
| Callbacks | `map`, `filter`. These make one PHP call per element, so they are slow. |

## Semantics

Both implementations follow these rules, and the contract and property tests check them.

**Types**
- An `IntVector` accepts only `int`. A `FloatVector` accepts `int` and `float`, and converts ints to
  floats.
- Strings, booleans and null throw `TypeError`, naming the key. Numeric strings are not converted.
- `fromArray()` ignores keys and skips holes left by `unset()`.

**Integers are exact**
- `sum()` and `dot()` keep 128-bit (or wider) intermediate totals. They throw `OverflowException` only
  when the final result does not fit in an `int`.
- `add`, `sub`, `mul` and `abs` throw `OverflowException` naming the first index that overflows.
- `mean()` rounds the exact sum to a float once and never overflows.

**Floats add in a fixed order**
- `sum()`, `dot()` and `mean()` add element `i` into running total `i % 8`, then combine the totals as
  `((t0+t1)+(t2+t3))+((t4+t5)+(t6+t7))`.
- There are no fused multiply-adds.
- So results are bit-identical on every CPU, at every SIMD level, and in both implementations. They can
  differ from `array_sum()` in the last bits.

**NaN and signed zero**
- `min()` and `max()` return NaN if any element is NaN, and treat -0.0 as smaller than 0.0.
- `sort()` puts -0.0 before 0.0 and NaN last.
- `unique()` treats -0.0 as a repeat of 0.0, and every NaN as a repeat of the first NaN.
- The `where*` methods never match NaN.

**Division**
- `div()` follows IEEE 754, like `fdiv()`: dividing by zero gives INF, -INF or NaN and never throws.

**Copies**
- Vectors are values. `clone`, `slice()` and every operation return a new vector.
- Cloning is O(1): the buffer is shared and copied only on the first write.
- `foreach` walks a snapshot, so writes inside the loop don't affect it.

**Errors**
- Bad indices throw `OutOfRangeException`.
- `min()`, `max()`, `mean()` and `pop()` on an empty vector throw `UnderflowException`.
- Bad arguments throw `ValueError`.
- Every message names the class, the method and the fix.

## Limitations

- **`memory_limit` does not cover vectors.** The extension stores elements outside PHP's memory
  manager, so `memory_limit`, `memory_get_usage()` and `memory_get_peak_usage()` don't see them. A
  request can hold far more vector data than `memory_limit` suggests.
  - Limit sizes where data comes in: `#[Count(max: ...)]` on vector fields in API inputs, and checks on
    `count()` elsewhere.
  - `memoryBytes()` reports a vector's buffer.
  - The process's resident memory (container limits, `pm.max_requests`, the OOM killer) is the real
    ceiling.
  - The pure-PHP implementation stores PHP arrays, which `memory_limit` does count, at about 2.6× the
    size.
- **Windows is not supported.** ext-php-rs needs nightly Rust on Windows. Windows uses the pure-PHP
  implementation.
- **PHP versions:** 8.3, 8.4 and 8.5 are built and tested in CI on Linux x86_64 and aarch64, and 8.4 on
  macOS. 32-bit PHP is not supported.
- **Thread-safe PHP (ZTS)** is built and tested in CI on Linux x86_64 with PHP 8.4. Each vector belongs to
  the thread that made it, like any PHP value.
- **Sizes:** a vector converts to a PHP array with `toArray()` only below 2³² elements, PHP's own limit.
- **Benchmarks** exist only for Apple silicon (NEON). The x86 SIMD paths are tested but not benchmarked.
- **Upgrades:** the extension and the PHP code ship together. Rebuild the extension after updating Keel
  or PHP; `VectorInfo::version()` shows which build is loaded.

## Development

```bash
cd ext/keel_vector
make test        # Rust: every kernel at every SIMD level the CPU runs, against a scalar reference
make test-php    # the PHP contract and property suites, with the pure-PHP implementation, then the extension
make valgrind    # Linux: the PHP suites under valgrind
make asan        # macOS, nightly Rust: the PHP suites with an AddressSanitizer build
cargo clippy --all-targets -- -D warnings
```

`target/` and the built `.so` are never committed. `Cargo.lock` pins ext-php-rs; upgrade it on purpose,
then run all of the above.
