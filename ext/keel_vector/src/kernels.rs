//! The numeric work, on plain slices, so it can be tested without PHP.
//!
//! Each public kernel is compiled once per instruction set and picked at run time (`dispatch!`). The
//! bodies are written so the compiler vectorizes them: independent accumulators, no early exits in
//! the hot loop, overflow flags folded with `|`. Results never depend on the instruction set chosen:
//! integer results are exact, and float reductions add in one fixed order (see `LANES`), so the
//! pure-PHP implementation computes bit-identical values.

use std::collections::HashSet;

/// Float sums and dot products keep this many running totals: element `i` goes into total `i % 8`,
/// then the totals are combined as `((t0 + t1) + (t2 + t3)) + ((t4 + t5) + (t6 + t7))`.
pub const LANES: usize = 8;

/// Compiles `$generic` (an `#[inline(always)]` function) once per instruction set and calls the
/// one this CPU supports.
macro_rules! dispatch {
    ($(#[$meta:meta])* pub fn $name:ident$(<$lt:lifetime>)?($($arg:ident: $ty:ty),*) -> $ret:ty = $generic:ident;) => {
        $(#[$meta])*
        pub fn $name$(<$lt>)?($($arg: $ty),*) -> $ret {
            #[cfg(target_arch = "x86_64")]
            {
                #[target_feature(enable = "avx2")]
                fn avx2$(<$lt>)?($($arg: $ty),*) -> $ret {
                    $generic($($arg),*)
                }
                #[target_feature(enable = "sse4.2")]
                fn sse42$(<$lt>)?($($arg: $ty),*) -> $ret {
                    $generic($($arg),*)
                }
                match crate::simd::level() {
                    // SAFETY: `simd::level()` reports AVX2 only when the CPU supports it.
                    crate::simd::Level::Avx2 => return unsafe { avx2($($arg),*) },
                    // SAFETY: `simd::level()` reports SSE4.2 only when the CPU supports it.
                    crate::simd::Level::Sse42 => return unsafe { sse42($($arg),*) },
                    _ => {}
                }
            }
            // aarch64 always has NEON, so the baseline build already uses it.
            $generic($($arg),*)
        }
    };
}

// ---------------------------------------------------------------------------------------------
// Integers
// ---------------------------------------------------------------------------------------------

/// The exact sum, or `None` when it does not fit in an i64.
pub fn sum_i64(values: &[i64]) -> Option<i64> {
    i64::try_from(sum_i128(values)).ok()
}

dispatch! {
    /// The exact sum as an i128, which cannot overflow: a slice holds fewer than 2^61 i64s.
    pub fn sum_i128(values: &[i64]) -> i128 = sum_i128_body;
}

#[inline(always)]
fn sum_i128_body(values: &[i64]) -> i128 {
    let mut total: i128 = 0;
    for &v in values {
        total += v as i128;
    }
    total
}

dispatch! {
    /// The exact dot product, or `None` when it does not fit in an i64. The slices have equal length.
    pub fn dot_i64(a: &[i64], b: &[i64]) -> Option<i64> = dot_i64_body;
}

#[inline(always)]
fn dot_i64_body(a: &[i64], b: &[i64]) -> Option<i64> {
    // Each product fits in an i128, but a run of them may not. Count the wraps: the exact value is
    // `wraps * 2^128 + total`, which fits in an i64 only when `wraps` is 0 and `total` does.
    let mut total: i128 = 0;
    let mut wraps: i64 = 0;
    for (&x, &y) in a.iter().zip(b) {
        let (next, overflowed) = total.overflowing_add(x as i128 * y as i128);
        if overflowed {
            wraps += if next < total { 1 } else { -1 };
        }
        total = next;
    }
    if wraps != 0 {
        return None;
    }
    i64::try_from(total).ok()
}

dispatch! {
    pub fn min_i64(values: &[i64]) -> Option<i64> = min_i64_body;
}

#[inline(always)]
fn min_i64_body(values: &[i64]) -> Option<i64> {
    let (&first, rest) = values.split_first()?;
    Some(rest.iter().fold(first, |m, &v| m.min(v)))
}

dispatch! {
    pub fn max_i64(values: &[i64]) -> Option<i64> = max_i64_body;
}

#[inline(always)]
fn max_i64_body(values: &[i64]) -> Option<i64> {
    let (&first, rest) = values.split_first()?;
    Some(rest.iter().fold(first, |m, &v| m.max(v)))
}

/// The arithmetic mean: the exact sum, rounded once to the nearest f64, divided by the length.
pub fn mean_i64(values: &[i64]) -> Option<f64> {
    if values.is_empty() {
        return None;
    }
    Some(sum_i128(values) as f64 / values.len() as f64)
}

/// The element-wise operations that can overflow.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum IntOp {
    Add,
    Sub,
    Mul,
}

impl IntOp {
    pub fn symbol(self) -> &'static str {
        match self {
            IntOp::Add => "+",
            IntOp::Sub => "-",
            IntOp::Mul => "*",
        }
    }

    #[inline(always)]
    fn overflowing(self, a: i64, b: i64) -> (i64, bool) {
        match self {
            IntOp::Add => a.overflowing_add(b),
            IntOp::Sub => a.overflowing_sub(b),
            IntOp::Mul => a.overflowing_mul(b),
        }
    }
}

dispatch! {
    /// `a[i] op b[i]`; on overflow, `Err` with the first index that overflowed. Equal lengths.
    pub fn zip_i64(op: IntOp, a: &[i64], b: &[i64]) -> Result<Vec<i64>, usize> = zip_i64_body;
}

#[inline(always)]
fn zip_i64_body(op: IntOp, a: &[i64], b: &[i64]) -> Result<Vec<i64>, usize> {
    let mut overflow = false;
    let out: Vec<i64> = match op {
        IntOp::Add => a.iter().zip(b).map(|(&x, &y)| { let (v, o) = x.overflowing_add(y); overflow |= o; v }).collect(),
        IntOp::Sub => a.iter().zip(b).map(|(&x, &y)| { let (v, o) = x.overflowing_sub(y); overflow |= o; v }).collect(),
        IntOp::Mul => a.iter().zip(b).map(|(&x, &y)| { let (v, o) = x.overflowing_mul(y); overflow |= o; v }).collect(),
    };
    if overflow {
        return Err(a.iter().zip(b).position(|(&x, &y)| op.overflowing(x, y).1).unwrap_or(0));
    }
    Ok(out)
}

dispatch! {
    /// `a[i] op scalar`; on overflow, `Err` with the first index that overflowed.
    pub fn scalar_i64(op: IntOp, a: &[i64], scalar: i64) -> Result<Vec<i64>, usize> = scalar_i64_body;
}

#[inline(always)]
fn scalar_i64_body(op: IntOp, a: &[i64], s: i64) -> Result<Vec<i64>, usize> {
    let mut overflow = false;
    let out: Vec<i64> = match op {
        IntOp::Add => a.iter().map(|&x| { let (v, o) = x.overflowing_add(s); overflow |= o; v }).collect(),
        IntOp::Sub => a.iter().map(|&x| { let (v, o) = x.overflowing_sub(s); overflow |= o; v }).collect(),
        IntOp::Mul => a.iter().map(|&x| { let (v, o) = x.overflowing_mul(s); overflow |= o; v }).collect(),
    };
    if overflow {
        return Err(a.iter().position(|&x| op.overflowing(x, s).1).unwrap_or(0));
    }
    Ok(out)
}

dispatch! {
    /// `|a[i]|`; `Err(index)` for `i64::MIN`, whose absolute value is not an i64.
    pub fn abs_i64(a: &[i64]) -> Result<Vec<i64>, usize> = abs_i64_body;
}

#[inline(always)]
fn abs_i64_body(a: &[i64]) -> Result<Vec<i64>, usize> {
    let mut overflow = false;
    let out: Vec<i64> = a.iter().map(|&x| { overflow |= x == i64::MIN; x.wrapping_abs() }).collect();
    if overflow {
        return Err(a.iter().position(|&x| x == i64::MIN).unwrap_or(0));
    }
    Ok(out)
}

/// A comparison against one value or a closed range, answered with the matching indices.
#[derive(Clone, Copy, Debug)]
pub enum Cmp<T> {
    Greater(T),
    Less(T),
    Equal(T),
    Between(T, T),
}

dispatch! {
    pub fn where_i64(a: &[i64], cmp: Cmp<i64>) -> Vec<i64> = where_i64_body;
}

#[inline(always)]
fn where_i64_body(a: &[i64], cmp: Cmp<i64>) -> Vec<i64> {
    match cmp {
        Cmp::Greater(v) => indices(a, |x| x > v),
        Cmp::Less(v) => indices(a, |x| x < v),
        Cmp::Equal(v) => indices(a, |x| x == v),
        Cmp::Between(lo, hi) => indices(a, |x| x >= lo && x <= hi),
    }
}

/// Without a branch per element: every index is written, and the write position only moves on a
/// match, so random data costs no mispredictions.
#[inline(always)]
fn indices<T: Copy>(a: &[T], keep: impl Fn(T) -> bool) -> Vec<i64> {
    let mut out = vec![0i64; a.len()];
    let mut n = 0;
    for (i, &x) in a.iter().enumerate() {
        out[n] = i as i64;
        n += keep(x) as usize;
    }
    out.truncate(n);
    out.shrink_to_fit();
    out
}

/// `values[indices[k]]` for each k; `Err((k, index))` for the first index outside `values`.
pub fn take<T: Copy>(values: &[T], indices: &[i64]) -> Result<Vec<T>, (usize, i64)> {
    let mut out = Vec::with_capacity(indices.len());
    for (k, &i) in indices.iter().enumerate() {
        match usize::try_from(i).ok().and_then(|i| values.get(i)) {
            Some(&v) => out.push(v),
            None => return Err((k, i)),
        }
    }
    Ok(out)
}

pub fn sort_i64(values: &[i64]) -> Vec<i64> {
    let mut out = values.to_vec();
    out.sort_unstable();
    out
}

/// The values in the order they first appear, without repeats.
pub fn unique_i64(values: &[i64]) -> Vec<i64> {
    let mut seen = HashSet::with_capacity(values.len().min(1 << 16));
    values.iter().copied().filter(|v| seen.insert(*v)).collect()
}

// ---------------------------------------------------------------------------------------------
// Floats
// ---------------------------------------------------------------------------------------------

#[inline(always)]
fn combine(t: [f64; LANES]) -> f64 {
    ((t[0] + t[1]) + (t[2] + t[3])) + ((t[4] + t[5]) + (t[6] + t[7]))
}

dispatch! {
    /// The sum in the fixed order described at `LANES`.
    pub fn sum_f64(values: &[f64]) -> f64 = sum_f64_body;
}

#[inline(always)]
fn sum_f64_body(values: &[f64]) -> f64 {
    let mut t = [0.0f64; LANES];
    let mut chunks = values.chunks_exact(LANES);
    for c in &mut chunks {
        for l in 0..LANES {
            t[l] += c[l];
        }
    }
    for (l, &v) in chunks.remainder().iter().enumerate() {
        t[l] += v;
    }
    combine(t)
}

dispatch! {
    /// The dot product in the fixed order described at `LANES`; each product is rounded before it is
    /// added (no fused multiply-add). Equal lengths.
    pub fn dot_f64(a: &[f64], b: &[f64]) -> f64 = dot_f64_body;
}

#[inline(always)]
fn dot_f64_body(a: &[f64], b: &[f64]) -> f64 {
    let mut t = [0.0f64; LANES];
    let mut ca = a.chunks_exact(LANES);
    let mut cb = b.chunks_exact(LANES);
    for (x, y) in (&mut ca).zip(&mut cb) {
        for l in 0..LANES {
            t[l] += x[l] * y[l];
        }
    }
    for (l, (&x, &y)) in ca.remainder().iter().zip(cb.remainder()).enumerate() {
        t[l] += x * y;
    }
    combine(t)
}

/// The smallest value. NaN wins over everything; -0.0 counts as smaller than 0.0.
pub fn min_f64(values: &[f64]) -> Option<f64> {
    let (&first, rest) = values.split_first()?;
    let mut m = first;
    for &v in rest {
        if v.is_nan() || m.is_nan() {
            return Some(f64::NAN);
        }
        if v < m || (v == m && v.is_sign_negative()) {
            m = v;
        }
    }
    Some(if m.is_nan() { f64::NAN } else { m })
}

/// The largest value. NaN wins over everything; 0.0 counts as larger than -0.0.
pub fn max_f64(values: &[f64]) -> Option<f64> {
    let (&first, rest) = values.split_first()?;
    let mut m = first;
    for &v in rest {
        if v.is_nan() || m.is_nan() {
            return Some(f64::NAN);
        }
        if v > m || (v == m && v.is_sign_positive()) {
            m = v;
        }
    }
    Some(if m.is_nan() { f64::NAN } else { m })
}

pub fn mean_f64(values: &[f64]) -> Option<f64> {
    if values.is_empty() {
        return None;
    }
    Some(sum_f64(values) / values.len() as f64)
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum FloatOp {
    Add,
    Sub,
    Mul,
    Div,
}

dispatch! {
    /// `a[i] op b[i]` with IEEE 754 semantics (division by zero gives ±INF or NaN). Equal lengths.
    pub fn zip_f64(op: FloatOp, a: &[f64], b: &[f64]) -> Vec<f64> = zip_f64_body;
}

#[inline(always)]
fn zip_f64_body(op: FloatOp, a: &[f64], b: &[f64]) -> Vec<f64> {
    let it = a.iter().zip(b);
    match op {
        FloatOp::Add => it.map(|(&x, &y)| x + y).collect(),
        FloatOp::Sub => it.map(|(&x, &y)| x - y).collect(),
        FloatOp::Mul => it.map(|(&x, &y)| x * y).collect(),
        FloatOp::Div => it.map(|(&x, &y)| x / y).collect(),
    }
}

dispatch! {
    pub fn scalar_f64(op: FloatOp, a: &[f64], s: f64) -> Vec<f64> = scalar_f64_body;
}

#[inline(always)]
fn scalar_f64_body(op: FloatOp, a: &[f64], s: f64) -> Vec<f64> {
    match op {
        FloatOp::Add => a.iter().map(|&x| x + s).collect(),
        FloatOp::Sub => a.iter().map(|&x| x - s).collect(),
        FloatOp::Mul => a.iter().map(|&x| x * s).collect(),
        FloatOp::Div => a.iter().map(|&x| x / s).collect(),
    }
}

dispatch! {
    pub fn abs_f64(a: &[f64]) -> Vec<f64> = abs_f64_body;
}

#[inline(always)]
fn abs_f64_body(a: &[f64]) -> Vec<f64> {
    a.iter().map(|&x| x.abs()).collect()
}

dispatch! {
    /// Indices where the comparison holds. NaN compares false with everything.
    pub fn where_f64(a: &[f64], cmp: Cmp<f64>) -> Vec<i64> = where_f64_body;
}

#[inline(always)]
fn where_f64_body(a: &[f64], cmp: Cmp<f64>) -> Vec<i64> {
    match cmp {
        Cmp::Greater(v) => indices(a, |x| x > v),
        Cmp::Less(v) => indices(a, |x| x < v),
        Cmp::Equal(v) => indices(a, |x| x == v),
        Cmp::Between(lo, hi) => indices(a, |x| x >= lo && x <= hi),
    }
}

/// Ascending; -0.0 before 0.0, NaN last.
pub fn sort_f64(values: &[f64]) -> Vec<f64> {
    let mut out = values.to_vec();
    out.sort_unstable_by(|a, b| match (a.is_nan(), b.is_nan()) {
        (false, false) => a.total_cmp(b),
        (a_nan, b_nan) => a_nan.cmp(&b_nan),
    });
    out
}

/// The values in the order they first appear, without repeats. Values that compare equal are
/// repeats (so -0.0 repeats 0.0), and every NaN repeats the first NaN.
pub fn unique_f64(values: &[f64]) -> Vec<f64> {
    let key = |v: f64| {
        if v.is_nan() {
            f64::NAN.to_bits()
        } else if v == 0.0 {
            0
        } else {
            v.to_bits()
        }
    };
    let mut seen = HashSet::with_capacity(values.len().min(1 << 16));
    values.iter().copied().filter(|&v| seen.insert(key(v))).collect()
}

/// The values of an inclusive range, like PHP's `range()`: the direction comes from `start` and
/// `end`, the step's sign is ignored. `None` for a zero step or a range too long to allocate.
pub fn range_i64(start: i64, end: i64, step: i64) -> Option<Vec<i64>> {
    if step == 0 {
        return None;
    }
    let step = step.unsigned_abs() as u128;
    let span = (end as i128 - start as i128).unsigned_abs();
    let count = usize::try_from(span / step + 1).ok()?;
    let mut out = Vec::new();
    out.try_reserve_exact(count).ok()?;
    let mut v = start as i128;
    let delta = if end >= start { step as i128 } else { -(step as i128) };
    for _ in 0..count {
        out.push(v as i64);
        v += delta;
    }
    Some(out)
}

/// An inclusive float range: `start + k * step` for each k, with the step's sign taken from the
/// direction. The element count is `floor(|end - start| / |step| + 1e-9) + 1`, so `range(0, 1, 0.1)`
/// has 11 elements despite rounding. `None` for a zero or non-finite input or a range too long.
pub fn range_f64(start: f64, end: f64, step: f64) -> Option<Vec<f64>> {
    if step == 0.0 || !start.is_finite() || !end.is_finite() || !step.is_finite() {
        return None;
    }
    let count = ((end - start).abs() / step.abs() + 1e-9).floor() + 1.0;
    if count.is_nan() || count >= isize::MAX as f64 / 8.0 {
        return None;
    }
    let count = count as usize;
    let step = if end >= start { step.abs() } else { -step.abs() };
    let mut out = Vec::new();
    out.try_reserve_exact(count).ok()?;
    for k in 0..count {
        out.push(start + k as f64 * step);
    }
    Some(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn integer_sums_are_exact_and_checked() {
        assert_eq!(sum_i64(&[]), Some(0));
        assert_eq!(sum_i64(&[1, 2, 3]), Some(6));
        assert_eq!(sum_i64(&[i64::MAX, 1, -1]), Some(i64::MAX));
        assert_eq!(sum_i64(&[i64::MAX, 1]), None);
        assert_eq!(sum_i64(&[i64::MIN, -1]), None);
    }

    #[test]
    fn integer_dot_counts_i128_wraps() {
        assert_eq!(dot_i64(&[1, 2, 3], &[4, 5, 6]), Some(32));
        assert_eq!(dot_i64(&[i64::MAX], &[1]), Some(i64::MAX));
        let (m, x) = (i64::MIN, i64::MAX);
        // 4 * 2^126 = 2^128: the i128 total wraps to exactly 0, which must not be reported as 0.
        assert_eq!(dot_i64(&[m; 4], &[m; 4]), None);
        // 2^126 + 2^126 + 2 * (m * MAX) + m * 2 is exactly 0, though the running total wraps twice.
        assert_eq!(dot_i64(&[m, m, m, m, m], &[m, m, x, x, 2]), Some(0));
        assert_eq!(dot_i64(&[m, 1], &[1, -1]), None);
    }

    #[test]
    fn checked_element_wise_reports_the_first_overflow() {
        assert_eq!(zip_i64(IntOp::Add, &[1, i64::MAX, i64::MAX], &[1, 1, 1]), Err(1));
        assert_eq!(scalar_i64(IntOp::Mul, &[2, 3], 4), Ok(vec![8, 12]));
        assert_eq!(scalar_i64(IntOp::Sub, &[0, i64::MIN], 1), Err(1));
        assert_eq!(abs_i64(&[-3, i64::MIN]), Err(1));
    }

    #[test]
    fn float_reductions_use_the_fixed_order() {
        let v: Vec<f64> = (0..37).map(|i| (i as f64) * 0.1).collect();
        let mut t = [0.0; LANES];
        for (i, &x) in v.iter().enumerate() {
            t[i % LANES] += x;
        }
        assert_eq!(sum_f64(&v).to_bits(), combine(t).to_bits());
        assert_eq!(sum_f64(&[]), 0.0);
    }

    #[test]
    fn float_min_max_order_nan_and_signed_zero() {
        assert!(min_f64(&[1.0, f64::NAN, -1.0]).unwrap().is_nan());
        assert!(min_f64(&[0.0, -0.0]).unwrap().is_sign_negative());
        assert!(max_f64(&[-0.0, 0.0]).unwrap().is_sign_positive());
        assert_eq!(min_f64(&[]), None);
    }

    #[test]
    fn sort_and_unique() {
        let s = sort_f64(&[f64::NAN, 1.0, 0.0, -0.0, -2.0]);
        assert_eq!(s[0], -2.0);
        assert!(s[1].is_sign_negative() && s[1] == 0.0);
        assert!(s[4].is_nan());
        assert_eq!(unique_i64(&[3, 1, 3, 2, 1]), vec![3, 1, 2]);
        let u = unique_f64(&[0.0, -0.0, f64::NAN, f64::NAN, 1.0]);
        assert_eq!(u.len(), 3);
    }

    #[test]
    fn ranges() {
        assert_eq!(range_i64(0, 10, 3), Some(vec![0, 3, 6, 9]));
        assert_eq!(range_i64(3, 0, 1), Some(vec![3, 2, 1, 0]));
        assert_eq!(range_i64(i64::MAX - 1, i64::MAX, 5), Some(vec![i64::MAX - 1]));
        assert_eq!(range_i64(0, 1, 0), None);
        assert_eq!(range_f64(0.0, 1.0, 0.1).unwrap().len(), 11);
        assert_eq!(range_f64(1.0, 0.0, 0.5), Some(vec![1.0, 0.5, 0.0]));
    }

    #[test]
    fn take_checks_bounds() {
        assert_eq!(take(&[10, 20, 30], &[2, 0]), Ok(vec![30, 10]));
        assert_eq!(take(&[10], &[0, -1]), Err((1, -1)));
    }
}
