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

# The PHP suites under valgrind (Linux), with the release build. Fails on any memory error, and on a
# leak whose allocation passed through the extension; PHP's own exit-time leaks are only reported.
# Opcache and the PCRE JIT are off: valgrind cannot follow JIT-compiled code and reports its 16-byte
# string reads as errors.
valgrind: build
	KEEL_VECTOR_BACKEND=native USE_ZEND_ALLOC=0 ZEND_DONT_UNLOAD_MODULES=1 valgrind --error-exitcode=1 \
		--leak-check=full --errors-for-leak-kinds=none --num-callers=40 --log-file=target/valgrind.log \
		$(PHP) -d opcache.enable_cli=0 -d opcache.jit=disable -d pcre.jit=0 -d extension=$(EXTENSION) $(SUITE)
	@grep -E 'ERROR SUMMARY|definitely lost:|indirectly lost:' target/valgrind.log
	@if grep -A40 'are definitely lost in' target/valgrind.log | grep -q keel_vector; then \
		echo 'valgrind: memory allocated through keel_vector leaked; see target/valgrind.log' >&2; exit 1; fi

clean:
	cargo clean
