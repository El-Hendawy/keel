//! keel_vector: typed, contiguous vectors with SIMD-accelerated operations for Keel.
//!
//! Every class lives in the `Keel\Vector` namespace. When the extension is not loaded, Keel uses a
//! pure-PHP implementation with the same API.

use ext_php_rs::prelude::*;

mod simd;

/// Reports how the extension was built and which SIMD path it uses on this CPU.
#[php_class]
#[php(name = "Keel\\Vector\\VectorInfo")]
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
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<VectorInfo>()
}
