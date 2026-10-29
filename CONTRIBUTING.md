# Contributing to Keel

Thank you for helping. Keel says no often, so please read this before writing code.

## How decisions are made

- **Bug fixes, docs, tests, performance**: open a pull request.
- **Anything user-facing** (a new API, a config key, an error code, a deprecation, a breaking change): open an
  RFC first, using [docs/rfcs/0000-template.md](docs/rfcs/0000-template.md). It stays open for comments for at
  least two weeks; the core team records the decision and the reasoning in the RFC.
- Every feature must earn its place against the [twelve principles](docs/manifesto.md#the-twelve-principles).
  "Laravel/Symfony has it" is not a reason; "a production application needed it, and here is the evidence" is.

## Definition of done

A pull request is finished when:

- [ ] `composer check` passes: PHPStan at level max on `src` and the example (no baseline, no ignores) and the
      full test suite.
- [ ] Generated code still passes PHPStan max (CI analyses a fresh build of the example).
- [ ] New behaviour has tests, including the failure path.
- [ ] Docs are updated in the same pull request.
- [ ] Public API changes have an approved RFC and a `CHANGELOG.md` entry; breaking changes include an upgrade
      note (and, from 1.0, a Rector rule).
- [ ] `php -d opcache.enable_cli=1 benchmarks/kernel.php` shows no regression over 5% on the same machine.

## Code style

- `declare(strict_types=1)`, `final` by default, `readonly` where possible.
- No static state outside `Keel\Async\Loop`. No reflection on the hot path: if you need it, it belongs in the
  compiler.
- Error messages say what is wrong *and* how to fix it.
- Comments explain why, not what.

## Running the checks

```bash
composer install
composer check
```

Changes to the vector extension (`ext/keel_vector`, Rust) also need, from that directory:

```bash
make test                                  # Rust tests at every SIMD level the CPU runs
make test-php                              # the PHP vector suites, without and with the extension
cargo clippy --all-targets -- -D warnings
```

`make valgrind` (Linux) and `make asan` (macOS, nightly Rust) check memory safety; CI runs valgrind.

If your checkout is on a filesystem Composer cannot extract to, put the vendor directory elsewhere with
`COMPOSER_VENDOR_DIR=/path/to/vendor composer install`; the test bootstrap and `bin/keel` honour it.

## Credit

Every release note names the people who contributed to it. Add yourself to the changelog entry.

## Conduct

Participation requires following the [code of conduct](CODE_OF_CONDUCT.md).
