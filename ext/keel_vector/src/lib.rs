//! keel_vector: typed, contiguous vectors with SIMD-accelerated operations for Keel.
//!
//! Every class lives in the `Keel\Vector` namespace. When the extension is not loaded, Keel uses a
//! pure-PHP implementation with the same API.

use std::sync::Once;

use ext_php_rs::{
    boxed::ZBox,
    class::RegisteredClass,
    convert::IntoZval,
    prelude::*,
    types::ZendHashTable,
    zend::{ce, ClassEntry},
};

mod errors;
mod float_vector;
mod int_vector;
mod iterator;
mod kernels;
mod simd;

/// Reports how the extension was built and which SIMD path it uses on this CPU.
#[php_class]
#[php(name = "Keel\\Vector\\VectorInfo")]
#[php(flags = ext_php_rs::flags::ClassFlags::Final)]
pub struct VectorInfo;

#[php_impl]
impl VectorInfo {
    /// "avx2", "sse4.2", "neon" or "scalar".
    pub fn simd_level() -> String {
        simd::level().name().to_owned()
    }

    pub fn version() -> String {
        env!("CARGO_PKG_VERSION").to_owned()
    }

    /// True here; false in the pure-PHP implementation.
    pub fn is_native() -> bool {
        true
    }
}

/// Finds a class of PHP's core or SPL by name, also while the module starts, when
/// `ClassEntry::try_find` cannot (the executor's class table does not exist yet).
pub(crate) fn find_class(name: &str) -> Option<&'static ClassEntry> {
    if let Some(class) = ClassEntry::try_find(name) {
        return Some(class);
    }
    // SAFETY: the compiler globals are a static of the engine (per thread under ZTS), and
    // CG(class_table) is the engine's class table, which lives for the whole process; its entries
    // point to class entries that live until shutdown. The lookup reads `name.len()` bytes from a
    // valid `&str` and does not need a terminating NUL. Both pointers are checked for null.
    unsafe {
        let globals = ext_php_rs::ffi::ext_php_rs_compiler_globals().as_ref()?;
        if globals.class_table.is_null() {
            return None;
        }
        let class = ext_php_rs::ffi::zend_hash_str_find_ptr_lc(globals.class_table, name.as_ptr().cast(), name.len());
        class.cast::<ClassEntry>().cast_const().as_ref()
    }
}

/// `JsonSerializable` comes from ext/json, which every PHP 8 build includes.
pub(crate) fn json_serializable() -> &'static ClassEntry {
    find_class("JsonSerializable").unwrap_or_else(ce::exception)
}

/// The values as a PHP list.
pub(crate) fn list<T: IntoZval + Copy>(values: &[T]) -> PhpResult<ZBox<ZendHashTable>> {
    let mut array = ZendHashTable::with_capacity(u32::try_from(values.len()).unwrap_or(u32::MAX));
    for &v in values {
        array.push(v).map_err(|e| PhpException::from_message(e.to_string()))?;
    }
    Ok(array)
}

static SERIALIZABLE: Once = Once::new();

/// ext-php-rs marks every class backed by a Rust struct as not serializable, because objects made
/// without the constructor would hold no struct. The vectors derive `Default`, so such objects hold
/// an empty vector and `__unserialize()` fills it. ext-php-rs registers classes after the module's
/// own startup function, so the flag is cleared at the first request instead.
extern "C" fn request_startup(_type: i32, _module_number: i32) -> i32 {
    SERIALIZABLE.call_once(|| {
        allow_serialization::<int_vector::IntVector>();
        allow_serialization::<float_vector::FloatVector>();
    });
    0
}

fn allow_serialization<T: RegisteredClass>() {
    let class = std::ptr::from_ref(T::get_metadata().ce()).cast_mut();
    // SAFETY: `class` is the registered class entry of an internal class, which lives until
    // shutdown and is not in shared memory; `Once` makes this the only write, before any request
    // uses the class.
    unsafe { (*class).ce_flags &= !ext_php_rs::ffi::ZEND_ACC_NOT_SERIALIZABLE };
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .request_startup_function(request_startup)
        .class::<VectorInfo>()
        .class::<errors::VectorException>()
        .class::<errors::OverflowException>()
        .class::<errors::LengthMismatchException>()
        .class::<iterator::VectorIterator>()
        .class::<int_vector::IntVector>()
        .class::<float_vector::FloatVector>()
}
