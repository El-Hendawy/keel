.PHONY: build test test-php asan valgrind clean

PHP ?= php
ROOT := $(abspath ../..)
VENDOR := $(or $(COMPOSER_VENDOR_DIR),$(ROOT)/vendor)
SUITE := $(VENDOR)/bin/phpunit -c $(ROOT)/phpunit.xml.dist $(ROOT)/tests/Vector
EXTENSION := $(CURDIR)/target/release/keel_vector.so
HOST := $(shell rustc -vV | sed -n 's/^host: //p')

build:
	./build.sh

# Every kernel at every SIMD level this CPU runs, debug and optimized.
test:
	cargo test
	cargo test --release

# The shared PHP contract and property suites, against the pure-PHP implementation, then the extension.
# KEEL_VECTOR_BACKEND makes the run fail if the wrong implementation is loaded.
test-php: build
	KEEL_VECTOR_BACKEND=php $(PHP) $(SUITE)
	KEEL_VECTOR_BACKEND=native $(PHP) -d extension=$(EXTENSION) $(SUITE)

# The PHP suites with an AddressSanitizer build of the extension (macOS, nightly Rust; on Linux use
# `valgrind`). USE_ZEND_ALLOC=0 sends PHP's own allocations through the checked malloc too.
asan:
	RUSTFLAGS="-Zsanitizer=address -C link-arg=-undefined -C link-arg=dynamic_lookup" \
		cargo +nightly build --target $(HOST) --target-dir target/asan
	cp target/asan/$(HOST)/debug/libkeel_vector.dylib target/asan/keel_vector.so
	KEEL_VECTOR_BACKEND=native USE_ZEND_ALLOC=0 ASAN_OPTIONS=halt_on_error=1:abort_on_error=1 \
		DYLD_INSERT_LIBRARIES=$$(rustc +nightly --print sysroot)/lib/rustlib/$(HOST)/lib/librustc-nightly_rt.asan.dylib \
		$(PHP) -d extension=$(CURDIR)/target/asan/keel_vector.so $(SUITE)

# The PHP suites under valgrind (Linux), with the release build.
valgrind: build
	KEEL_VECTOR_BACKEND=native USE_ZEND_ALLOC=0 ZEND_DONT_UNLOAD_MODULES=1 valgrind --error-exitcode=1 --leak-check=full \
		--errors-for-leak-kinds=definite $(PHP) -d extension=$(EXTENSION) $(SUITE)

clean:
	cargo clean
