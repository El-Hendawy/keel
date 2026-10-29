//! keel_vector: typed, contiguous vectors with SIMD-accelerated operations for Keel.
//!
//! Every class lives in the `Keel\Vector` namespace. When the extension is not loaded, Keel uses a
//! pure-PHP implementation with the same API.

use std::sync::Once;

use ext_php_rs::{
    boxed::ZBox,
    class::RegisteredClass,
    prelude::*,
    types::ZendHashTable,
    zend::{ce, ClassEntry},
};

mod errors;
mod float_vector;
mod int_vector;
mod iterator;
mod kernels;
#[cfg(test)]
mod kernel_tests;
mod simd;

/// The values of a packed array (a list built by appending), read in place, or `None` for a hash.
/// Slots left by `unset()` are UNDEF zvals, which callers skip.
pub(crate) fn packed(values: &ZendHashTable) -> Option<&[ext_php_rs::types::Zval]> {
    // Zend/zend_types.h; ext-php-rs does not export it.
    const HASH_FLAG_PACKED: u32 = 1 << 2;
    // SAFETY: `u.flags` is always initialised. With the packed flag set, `arPacked` points at
    // `nNumUsed` initialised zvals owned by the array, which the borrow keeps alive and unchanged.
    unsafe {
        if values.u.flags & HASH_FLAG_PACKED == 0 {
            return None;
        }
        if values.nNumUsed == 0 {
            return Some(&[]);
        }
        Some(std::slice::from_raw_parts(values.__bindgen_anon_1.arPacked, values.nNumUsed as usize))
    }
}

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

/// A value a vector holds, as the zval PHP stores for it.
pub(crate) trait Element: Copy {
    fn zval(self) -> ext_php_rs::types::Zval;
}

impl Element for i64 {
    fn zval(self) -> ext_php_rs::types::Zval {
        let mut z = ext_php_rs::types::Zval::new();
        z.set_long(self);
        z
    }
}

impl Element for f64 {
    fn zval(self) -> ext_php_rs::types::Zval {
        let mut z = ext_php_rs::types::Zval::new();
        z.set_double(self);
        z
    }
}

extern "C" {
    // Zend/zend_hash.h (ZEND_API); ext-php-rs does not bind it.
    fn zend_hash_real_init_packed(ht: *mut ZendHashTable);
}

/// The values as a PHP list, written straight into a packed array (what Zend's
/// `ZEND_HASH_FILL_PACKED` does) instead of one insert call per element.
pub(crate) fn list<T: Element>(values: &[T]) -> PhpResult<ZBox<ZendHashTable>> {
    let length = u32::try_from(values.len())
        .map_err(|_| PhpException::from_message(format!("{} elements do not fit in a PHP array", values.len())))?;
    let mut array = ZendHashTable::with_capacity(length);
    if length == 0 {
        return Ok(array);
    }
    // SAFETY: the array is new and uninitialised with room for `length` elements; initialising it as
    // packed allocates `nTableSize >= length` slots. Each slot is written once with a scalar zval
    // (no reference count to take), then the counts are set as Zend's fill macros set them.
    unsafe {
        let ht: *mut ZendHashTable = &mut *array;
        zend_hash_real_init_packed(ht);
        let slots = (*ht).__bindgen_anon_1.arPacked;
        for (i, &v) in values.iter().enumerate() {
            slots.add(i).write(v.zval());
        }
        (*ht).nNumUsed = length;
        (*ht).nNumOfElements = length;
        (*ht).nNextFreeElement = i64::from(length);
        (*ht).nInternalPointer = 0;
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
