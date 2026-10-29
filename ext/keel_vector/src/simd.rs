//! Which SIMD instruction set the kernels use, detected once per process at run time.

use std::sync::OnceLock;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Level {
    #[cfg_attr(not(target_arch = "x86_64"), allow(dead_code))]
    Avx2,
    #[cfg_attr(not(target_arch = "x86_64"), allow(dead_code))]
    Sse42,
    #[cfg_attr(not(target_arch = "aarch64"), allow(dead_code))]
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
    #[cfg(test)]
    if let Some(forced) = FORCED.with(std::cell::Cell::get) {
        return forced;
    }
    *LEVEL.get_or_init(detect)
}

#[cfg(test)]
thread_local! {
    static FORCED: std::cell::Cell<Option<Level>> = const { std::cell::Cell::new(None) };
}

/// Every level this CPU can run, best first, so tests can compare each compiled variant.
#[cfg(test)]
pub fn supported() -> Vec<Level> {
    let all = [Level::Avx2, Level::Sse42, Level::Neon, Level::Scalar];
    let best = all.iter().position(|&l| l == *LEVEL.get_or_init(detect)).unwrap_or(3);
    all[best..]
        .iter()
        .copied()
        .filter(|&l| match l {
            Level::Neon => cfg!(target_arch = "aarch64"),
            Level::Avx2 | Level::Sse42 => cfg!(target_arch = "x86_64"),
            Level::Scalar => true,
        })
        .collect()
}

/// Runs `f` on this thread with the kernels dispatched as if the CPU's best level were `level`.
#[cfg(test)]
pub fn with_level<R>(level: Level, f: impl FnOnce() -> R) -> R {
    assert!(supported().contains(&level), "{level:?} is not supported by this CPU");
    let previous = FORCED.with(|c| c.replace(Some(level)));
    let result = f();
    FORCED.with(|c| c.set(previous));
    result
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
