//! Which SIMD instruction set the kernels use, detected once per process at run time.

use std::sync::OnceLock;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
#[cfg_attr(not(target_arch = "x86_64"), allow(dead_code))]
pub enum Level {
    Avx2,
    Sse42,
    Neon,
    Scalar,
}

impl Level {
    pub fn name(self) -> &'static str {
        match self {
            Level::Avx2 => "avx2",
            Level::Sse42 => "sse4.2",
            Level::Neon => "neon",
            Level::Scalar => "scalar",
        }
    }
}

static LEVEL: OnceLock<Level> = OnceLock::new();

pub fn level() -> Level {
    *LEVEL.get_or_init(detect)
}

#[cfg(target_arch = "x86_64")]
fn detect() -> Level {
    if std::arch::is_x86_feature_detected!("avx2") {
        Level::Avx2
    } else if std::arch::is_x86_feature_detected!("sse4.2") {
        Level::Sse42
    } else {
        Level::Scalar
    }
}

#[cfg(target_arch = "aarch64")]
fn detect() -> Level {
    if std::arch::is_aarch64_feature_detected!("neon") {
        Level::Neon
    } else {
        Level::Scalar
    }
}

#[cfg(not(any(target_arch = "x86_64", target_arch = "aarch64")))]
fn detect() -> Level {
    Level::Scalar
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn detection_is_stable_and_named() {
        let first = level();
        assert_eq!(first, level());
        assert!(["avx2", "sse4.2", "neon", "scalar"].contains(&first.name()));
    }

    #[cfg(target_arch = "aarch64")]
    #[test]
    fn aarch64_has_neon() {
        assert_eq!(level(), Level::Neon);
    }
}
