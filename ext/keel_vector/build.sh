#!/usr/bin/env sh
# Builds the release extension and prints the php.ini line that loads it.
#   ./build.sh                       uses the php-config on PATH
#   PHP_CONFIG=/path/to/php-config ./build.sh
set -eu

cd "$(dirname "$0")"

if ! command -v cargo >/dev/null 2>&1; then
    echo "cargo not found: install Rust with https://rustup.rs (stable is enough)" >&2
    exit 1
fi

PHP_CONFIG="${PHP_CONFIG:-$(command -v php-config || true)}"
if [ -z "$PHP_CONFIG" ]; then
    echo "php-config not found: install the PHP development package (php-dev / php-devel) or set PHP_CONFIG" >&2
    exit 1
fi
export PHP_CONFIG
PHP="${PHP:-$(dirname "$PHP_CONFIG")/php}"
[ -x "$PHP" ] || PHP=php
export PHP

cargo build --release

case "$(uname -s)" in
    Darwin) built=target/release/libkeel_vector.dylib ;;
    *) built=target/release/libkeel_vector.so ;;
esac
cp "$built" target/release/keel_vector.so
so="$(pwd)/target/release/keel_vector.so"

"$PHP" -n -d "extension=$so" -r 'echo "keel_vector ", Keel\Vector\VectorInfo::version(), " (", Keel\Vector\VectorInfo::simdLevel(), ") for PHP ", PHP_VERSION, "\n";'
echo
echo "Add to php.ini (or a file in $("$PHP" -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')):"
echo "extension=$so"
