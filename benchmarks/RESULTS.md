# Vector benchmarks

`benchmarks/vectors.php` times plain PHP arrays, the pure-PHP vectors (`src/Vector/Polyfill`) and the
`keel_vector` extension on the same data, at 1,000, 100,000 and 10,000,000 elements. Re-run it on your
own hardware before relying on these numbers:

```bash
ext/keel_vector/build.sh
php benchmarks/vectors.php --extension=ext/keel_vector/target/release/keel_vector.so --markdown
php benchmarks/vectors.php --extension=ext/keel_vector/target/release/keel_vector.so --markdown --jit
```

## Machine

| | |
| --- | --- |
| CPU | Apple M4 (4 performance, 6 efficiency cores), 16 GB |
| OS | macOS 26.5, arm64 |
| PHP | 8.3.21 (Homebrew), CLI, opcache on |
| Extension | keel_vector 0.1.0, release build, Rust 1.99.1 |
| SIMD level | `neon` (`VectorInfo::simdLevel()`) |

No x86_64 numbers yet: the AVX2 and SSE4.2 paths are tested in CI, but not benchmarked here.

## Method

- Each implementation and size runs in its own PHP process, so memory does not mix.
- Every operation runs at least 3 times, and up to 50 times within 0.3 s. The table shows the median.
  The time includes allocating and freeing the result.
- Each result is compared with the plain-array result, bit for bit, so a fast wrong answer fails the
  run. Float sums compare in the vectors' fixed order.
- The data is random floats in [0, 1) and [-0.5, 0.5), with seed 1. `sum (int)` adds `0..n-1`.
- The "PHP array" column uses the best plain-PHP way to do each operation:
  - `array_fill`, `array_sum` and `sort` where PHP has a built-in;
  - a `foreach` loop for `dot`, `add` and `filter`.
- `fromArray` and `toArray` are the cost of crossing between arrays and vectors. Plain arrays have
  nothing to cross, so that column says n/a.
- Memory is one container of N floats:
  - for arrays and pure-PHP vectors, the growth of `memory_get_usage()`;
  - for the extension, its buffer (`memoryBytes()`), which PHP's allocator does not see.

## JIT off (opcache on)

| Operation | Elements | PHP array | Pure-PHP vector | Extension | vs array | vs pure |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| fromArray | 1,000 | n/a | 9.8 µs | 0.7 µs |  | 14× |
| fromArray | 100,000 | n/a | 953.8 µs | 134.6 µs |  | 7.1× |
| fromArray | 10,000,000 | n/a | 118.66 ms | 12.38 ms |  | 9.6× |
| toArray | 1,000 | n/a | <0.1 µs | 0.8 µs |  | <0.1× |
| toArray | 100,000 | n/a | <0.1 µs | 144.0 µs |  | <0.1× |
| toArray | 10,000,000 | n/a | <0.1 µs | 17.90 ms |  | <0.1× |
| fill | 1,000 | 1.1 µs | 1.2 µs | 0.2 µs | 6.7× | 7.5× |
| fill | 100,000 | 200.8 µs | 205.0 µs | 10.5 µs | 19× | 19× |
| fill | 10,000,000 | 23.49 ms | 23.25 ms | 1.09 ms | 21× | 21× |
| sum (int) | 1,000 | 1.6 µs | 15.9 µs | 0.2 µs | 7.6× | 76× |
| sum (int) | 100,000 | 123.7 µs | 1.30 ms | 14.8 µs | 8.4× | 88× |
| sum (int) | 10,000,000 | 12.29 ms | 160.63 ms | 1.67 ms | 7.4× | 96× |
| sum (float) | 1,000 | 1.9 µs | 10.2 µs | 0.1 µs | 15× | 82× |
| sum (float) | 100,000 | 148.4 µs | 1.08 ms | 9.3 µs | 16× | 116× |
| sum (float) | 10,000,000 | 14.68 ms | 100.56 ms | 1.15 ms | 13× | 87× |
| dot | 1,000 | 12.6 µs | 16.7 µs | 0.2 µs | 75× | 100× |
| dot | 100,000 | 1.30 ms | 1.66 ms | 14.8 µs | 88× | 112× |
| dot | 10,000,000 | 131.40 ms | 162.24 ms | 2.29 ms | 57× | 71× |
| add | 1,000 | 11.8 µs | 32.8 µs | 0.2 µs | 57× | 158× |
| add | 100,000 | 1.15 ms | 3.24 ms | 20.1 µs | 57× | 161× |
| add | 10,000,000 | 137.08 ms | 386.74 ms | 2.80 ms | 49× | 138× |
| filter (x > 0.5) | 1,000 | 10.6 µs | 35.8 µs | 0.5 µs | 20× | 66× |
| filter (x > 0.5) | 100,000 | 1.07 ms | 3.42 ms | 30.8 µs | 35× | 111× |
| filter (x > 0.5) | 10,000,000 | 122.05 ms | 379.16 ms | 5.11 ms | 24× | 74× |
| sort | 1,000 | 36.8 µs | 56.9 µs | 7.9 µs | 4.7× | 7.2× |
| sort | 100,000 | 9.32 ms | 11.68 ms | 1.26 ms | 7.4× | 9.2× |
| sort | 10,000,000 | 1.34 s | 1.63 s | 176.43 ms | 7.6× | 9.2× |

| Memory | Elements | PHP array | Pure-PHP vector | Extension | vs array | vs pure |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| one float container | 1,000 | 20.1 KiB | 21.1 KiB | 7.8 KiB | 2.6× | 2.7× |
| one float container | 100,000 | 2.0 MiB | 2.0 MiB | 781.2 KiB | 2.6× | 2.6× |
| one float container | 10,000,000 | 256.0 MiB | 256.0 MiB | 76.3 MiB | 3.4× | 3.4× |

## JIT on (`opcache.jit=tracing`)

The JIT speeds up the plain-PHP loops (`dot`, `add`, `filter`) and the pure-PHP vectors. Built-in
functions and the extension stay the same.

| Operation | Elements | PHP array | Pure-PHP vector | Extension | vs array | vs pure |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| fromArray | 1,000 | n/a | 3.4 µs | 0.7 µs |  | 4.8× |
| fromArray | 100,000 | n/a | 439.7 µs | 137.9 µs |  | 3.2× |
| fromArray | 10,000,000 | n/a | 70.63 ms | 14.34 ms |  | 4.9× |
| toArray | 1,000 | n/a | <0.1 µs | 0.8 µs |  | <0.1× |
| toArray | 100,000 | n/a | <0.1 µs | 139.2 µs |  | <0.1× |
| toArray | 10,000,000 | n/a | <0.1 µs | 19.31 ms |  | <0.1× |
| fill | 1,000 | 1.2 µs | 1.2 µs | 0.2 µs | 6.0× | 5.6× |
| fill | 100,000 | 200.6 µs | 201.4 µs | 9.1 µs | 22× | 22× |
| fill | 10,000,000 | 23.92 ms | 24.20 ms | 1.09 ms | 22× | 22× |
| sum (int) | 1,000 | 1.1 µs | 1.7 µs | 0.2 µs | 6.7× | 10.0× |
| sum (int) | 100,000 | 123.7 µs | 178.7 µs | 14.8 µs | 8.4× | 12× |
| sum (int) | 10,000,000 | 12.48 ms | 18.43 ms | 1.62 ms | 7.7× | 11× |
| sum (float) | 1,000 | 1.5 µs | 2.1 µs | 0.1 µs | 18× | 25× |
| sum (float) | 100,000 | 148.5 µs | 222.8 µs | 9.2 µs | 16× | 24× |
| sum (float) | 10,000,000 | 14.93 ms | 22.87 ms | 1.28 ms | 12× | 18× |
| dot | 1,000 | 3.0 µs | 4.3 µs | 0.1 µs | 24× | 34× |
| dot | 100,000 | 300.8 µs | 464.4 µs | 15.1 µs | 20× | 31× |
| dot | 10,000,000 | 31.02 ms | 48.05 ms | 2.34 ms | 13× | 21× |
| add | 1,000 | 4.8 µs | 10.4 µs | 0.2 µs | 23× | 50× |
| add | 100,000 | 538.5 µs | 1.16 ms | 19.9 µs | 27× | 58× |
| add | 10,000,000 | 78.32 ms | 142.77 ms | 2.81 ms | 28× | 51× |
| filter (x > 0.5) | 1,000 | 2.9 µs | 26.2 µs | 0.5 µs | 5.4× | 48× |
| filter (x > 0.5) | 100,000 | 633.5 µs | 2.78 ms | 30.7 µs | 21× | 91× |
| filter (x > 0.5) | 10,000,000 | 80.95 ms | 325.24 ms | 5.51 ms | 15× | 59× |
| sort | 1,000 | 37.7 µs | 43.6 µs | 7.8 µs | 4.8× | 5.6× |
| sort | 100,000 | 9.50 ms | 10.34 ms | 1.28 ms | 7.4× | 8.1× |
| sort | 10,000,000 | 1.39 s | 1.49 s | 176.00 ms | 7.9× | 8.4× |

## Reading the numbers

- **The extension runs the arithmetic 5–90× faster than plain arrays.**
  - Without the JIT, sums are 7–16× faster, element-wise work and dot products 50–90×, `filter` 20–35×
    and sort 5–8×.
  - With the JIT the loops speed up, but the extension stays 13–28× ahead on `dot` and `add`, and
    5–21× on `filter`.
- **Memory is 2.6–3.4× smaller.** A packed PHP array spends 16 bytes per element and grows in powers
  of two; a vector spends 8 bytes.
- **Crossing between arrays and vectors costs about as much as one cheap operation.**
  - At 10M elements, `fromArray` takes about 12 ms and `toArray` about 18 ms.
  - A single `array_sum` on data that arrives as an array is about as fast as converting and summing.
  - Vectors pay off when the data stays a vector across several operations, or when one operation is
    heavy (`dot`, `add`, `filter`, `sort`). API inputs are converted once, during hydration.
- **The pure-PHP vectors are for portability, not speed.**
  - Without the JIT they are 1.2–3× slower than hand-written loops (`dot`, `add`, `filter`, `sort`).
    Their sums are 7–13× slower than the built-in `array_sum`, because they check every integer
    addition for overflow and add floats in the fixed order.
  - With the JIT, their sums come within 1.5× of `array_sum`.
  - Their `toArray()` returns their own array without copying, hence the "<0.1" entries.
