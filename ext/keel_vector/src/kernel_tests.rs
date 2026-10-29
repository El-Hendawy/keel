//! Every kernel, compiled for every instruction set this CPU runs, against a plain scalar reference
//! written here independently: lengths around each SIMD width, misaligned slices, negative values,
//! i64 extremes, NaN, infinities and signed zeros.

use crate::kernels::*;
use crate::simd::{self, Level};

/// Lengths around 1, 4 and 8 lanes and around a page of elements.
const LENGTHS: [usize; 12] = [0, 1, 3, 7, 8, 9, 15, 16, 17, 1023, 1024, 1025];

/// A small deterministic generator (xorshift64*), so failures reproduce without a seed file.
struct Rng(u64);

impl Rng {
    fn next(&mut self) -> u64 {
        self.0 ^= self.0 >> 12;
        self.0 ^= self.0 << 25;
        self.0 ^= self.0 >> 27;
        self.0.wrapping_mul(0x2545_F491_4F6C_DD1D)
    }

    fn below(&mut self, n: u64) -> u64 {
        self.next() % n
    }
}

/// Integer inputs: small (never overflow), mixed signs, extremes sprinkled in, and full range.
fn int_profiles(rng: &mut Rng, len: usize) -> Vec<Vec<i64>> {
    const EXTREMES: [i64; 6] = [i64::MIN, i64::MIN + 1, -1, 0, i64::MAX - 1, i64::MAX];
    let small = (0..len).map(|_| rng.below(201) as i64 - 100).collect();
    let mid = (0..len).map(|_| (rng.next() >> 33) as i64 - (1 << 30)).collect();
    let extreme = (0..len)
        .map(|_| match rng.below(4) {
            0 => EXTREMES[rng.below(6) as usize],
            _ => rng.below(2001) as i64 - 1000,
        })
        .collect();
    let full = (0..len).map(|_| rng.next() as i64).collect();
    let all_max = vec![i64::MAX; len];
    let all_min = vec![i64::MIN; len];
    vec![small, mid, extreme, full, all_max, all_min]
}

/// Float inputs: ordinary values, then specials (NaN, ±INF, ±0, subnormals, huge) sprinkled in.
fn float_profiles(rng: &mut Rng, len: usize) -> Vec<Vec<f64>> {
    const SPECIALS: [f64; 9] = [f64::NAN, f64::INFINITY, f64::NEG_INFINITY, 0.0, -0.0, f64::MIN_POSITIVE / 4.0, f64::MAX, f64::MIN, 1e-300];
    let normal: Vec<f64> = (0..len).map(|_| (rng.next() >> 11) as f64 / (1u64 << 40) as f64 - 4096.0).collect();
    let special = normal
        .iter()
        .map(|&v| if rng.below(5) == 0 { SPECIALS[rng.below(9) as usize] } else { v })
        .collect();
    let zeros = (0..len).map(|_| if rng.below(2) == 0 { 0.0 } else { -0.0 }).collect();
    let bits = (0..len).map(|_| f64::from_bits(rng.next())).collect();
    vec![normal, special, zeros, bits]
}

/// Runs `check` for every level and every input, on an aligned slice and on one starting one
/// element into its allocation (so vector loads are misaligned).
fn each<T: Copy + Default>(profiles: fn(&mut Rng, usize) -> Vec<Vec<T>>, check: impl Fn(&[T], &[T], Level)) {
    let mut rng = Rng(0x9E37_79B9_7F4A_7C15);
    for level in simd::supported() {
        for &len in &LENGTHS {
            let left = profiles(&mut rng, len);
            let right = profiles(&mut rng, len);
            for (a, b) in left.iter().zip(&right) {
                simd::with_level(level, || check(a, b, level));
                let mut shifted_a = vec![T::default()];
                shifted_a.extend_from_slice(a);
                let mut shifted_b = vec![T::default()];
                shifted_b.extend_from_slice(b);
                simd::with_level(level, || check(&shifted_a[1..], &shifted_b[1..], level));
            }
        }
    }
}

/// Bit-identical, except that any two NaNs match (their payloads are not part of the contract).
fn same(x: f64, y: f64) -> bool {
    (x.is_nan() && y.is_nan()) || x.to_bits() == y.to_bits()
}

fn same_all(x: &[f64], y: &[f64]) -> bool {
    x.len() == y.len() && x.iter().zip(y).all(|(&a, &b)| same(a, b))
}

/// The exact sum of products in 256 bits (`hi * 2^128 + lo`), when it fits in an i64.
fn reference_dot(a: &[i64], b: &[i64]) -> Option<i64> {
    let (mut hi, mut lo): (i128, u128) = (0, 0);
    for (&x, &y) in a.iter().zip(b) {
        let p = x as i128 * y as i128;
        let (next, carry) = lo.overflowing_add(p as u128);
        hi += carry as i128 + if p < 0 { -1 } else { 0 };
        lo = next;
    }
    let low = lo as i128;
    let fits_i128 = hi == if low < 0 { -1 } else { 0 };
    if fits_i128 { i64::try_from(low).ok() } else { None }
}

fn reference_lanes(values: impl Iterator<Item = f64>) -> f64 {
    let mut t = [0.0f64; LANES];
    for (i, v) in values.enumerate() {
        t[i % LANES] += v;
    }
    ((t[0] + t[1]) + (t[2] + t[3])) + ((t[4] + t[5]) + (t[6] + t[7]))
}

fn reference_checked(op: IntOp, a: &[i64], b: impl Fn(usize) -> i64) -> Result<Vec<i64>, usize> {
    a.iter()
        .enumerate()
        .map(|(i, &x)| {
            let y = b(i);
            match op {
                IntOp::Add => x.checked_add(y),
                IntOp::Sub => x.checked_sub(y),
                IntOp::Mul => x.checked_mul(y),
            }
            .ok_or(i)
        })
        .collect()
}

fn reference_float_op(op: FloatOp, x: f64, y: f64) -> f64 {
    match op {
        FloatOp::Add => x + y,
        FloatOp::Sub => x - y,
        FloatOp::Mul => x * y,
        FloatOp::Div => x / y,
    }
}

fn positions<T: Copy>(a: &[T], keep: impl Fn(T) -> bool) -> Vec<i64> {
    (0..a.len()).filter(|&i| keep(a[i])).map(|i| i as i64).collect()
}

#[test]
fn integer_reductions_match_the_reference() {
    each(int_profiles, |a, b, level| {
        let exact: i128 = a.iter().map(|&v| v as i128).sum();
        assert_eq!(sum_i128(a), exact, "sum_i128 {level:?} len {}", a.len());
        assert_eq!(sum_i64(a), i64::try_from(exact).ok(), "sum_i64 {level:?} len {}", a.len());
        assert_eq!(dot_i64(a, b), reference_dot(a, b), "dot_i64 {level:?} len {}", a.len());
        assert_eq!(min_i64(a), a.iter().copied().min(), "min_i64 {level:?}");
        assert_eq!(max_i64(a), a.iter().copied().max(), "max_i64 {level:?}");
        let mean = (!a.is_empty()).then(|| exact as f64 / a.len() as f64);
        assert_eq!(mean_i64(a).map(f64::to_bits), mean.map(f64::to_bits), "mean_i64 {level:?}");
    });
}

#[test]
fn integer_element_wise_matches_the_reference() {
    each(int_profiles, |a, b, level| {
        for op in [IntOp::Add, IntOp::Sub, IntOp::Mul] {
            assert_eq!(zip_i64(op, a, b), reference_checked(op, a, |i| b[i]), "zip_i64 {op:?} {level:?} len {}", a.len());
            let s = b.first().copied().unwrap_or(7);
            assert_eq!(scalar_i64(op, a, s), reference_checked(op, a, |_| s), "scalar_i64 {op:?} {s} {level:?}");
        }
        let abs: Result<Vec<i64>, usize> = a.iter().enumerate().map(|(i, &x)| x.checked_abs().ok_or(i)).collect();
        assert_eq!(abs_i64(a), abs, "abs_i64 {level:?}");
    });
}

#[test]
fn integer_comparisons_sort_and_unique_match_the_reference() {
    each(int_profiles, |a, b, level| {
        for &v in b.iter().take(3).chain(&[i64::MIN, 0, i64::MAX]) {
            assert_eq!(where_i64(a, Cmp::Greater(v)), positions(a, |x| x > v), "where > {v} {level:?}");
            assert_eq!(where_i64(a, Cmp::Less(v)), positions(a, |x| x < v), "where < {v} {level:?}");
            assert_eq!(where_i64(a, Cmp::Equal(v)), positions(a, |x| x == v), "where == {v} {level:?}");
            let (lo, hi) = (v.min(0), v.max(0));
            assert_eq!(where_i64(a, Cmp::Between(lo, hi)), positions(a, |x| x >= lo && x <= hi), "between {level:?}");
        }
        let mut sorted = a.to_vec();
        sorted.sort();
        assert_eq!(sort_i64(a), sorted);
        let mut unique: Vec<i64> = Vec::new();
        for &v in a {
            if !unique.contains(&v) {
                unique.push(v);
            }
        }
        assert_eq!(unique_i64(a), unique);
        let indices: Vec<i64> = b.iter().map(|&v| v.rem_euclid(a.len().max(1) as i64)).collect();
        let expected = if a.is_empty() && !indices.is_empty() { Err((0, indices[0])) } else { Ok(indices.iter().map(|&i| a[i as usize]).collect()) };
        assert_eq!(take(a, &indices), expected);
    });
}

#[test]
fn float_reductions_match_the_reference_bit_for_bit() {
    each(float_profiles, |a, b, level| {
        let sum = reference_lanes(a.iter().copied());
        assert!(same(sum_f64(a), sum), "sum_f64 {level:?} len {}: {} vs {}", a.len(), sum_f64(a), sum);
        let dot = reference_lanes(a.iter().zip(b).map(|(&x, &y)| x * y));
        assert!(same(dot_f64(a, b), dot), "dot_f64 {level:?} len {}", a.len());
        let mean = (!a.is_empty()).then(|| sum / a.len() as f64);
        assert_eq!(mean_f64(a).is_some(), mean.is_some());
        if let (Some(x), Some(y)) = (mean_f64(a), mean) {
            assert!(same(x, y), "mean_f64 {level:?}");
        }

        let has_nan = a.iter().any(|v| v.is_nan());
        let min = a.iter().copied().min_by(f64::total_cmp).map(|m| if has_nan { f64::NAN } else { m });
        let max = a.iter().copied().max_by(f64::total_cmp).map(|m| if has_nan { f64::NAN } else { m });
        assert_eq!(min_f64(a).is_some(), min.is_some());
        if let (Some(x), Some(y), Some(p), Some(q)) = (min_f64(a), min, max_f64(a), max) {
            assert!(same(x, y), "min_f64 {level:?}: {x} vs {y}");
            assert!(same(p, q), "max_f64 {level:?}: {p} vs {q}");
        }
    });
}

#[test]
fn float_element_wise_matches_the_reference_bit_for_bit() {
    each(float_profiles, |a, b, level| {
        for op in [FloatOp::Add, FloatOp::Sub, FloatOp::Mul, FloatOp::Div] {
            let zipped: Vec<f64> = a.iter().zip(b).map(|(&x, &y)| reference_float_op(op, x, y)).collect();
            assert!(same_all(&zip_f64(op, a, b), &zipped), "zip_f64 {op:?} {level:?} len {}", a.len());
            for s in [b.first().copied().unwrap_or(2.5), 0.0, -0.0, f64::NAN, f64::INFINITY] {
                let scaled: Vec<f64> = a.iter().map(|&x| reference_float_op(op, x, s)).collect();
                assert!(same_all(&scalar_f64(op, a, s), &scaled), "scalar_f64 {op:?} {s} {level:?}");
            }
        }
        let abs: Vec<f64> = a.iter().map(|&x| f64::from_bits(x.to_bits() & !(1 << 63))).collect();
        assert!(same_all(&abs_f64(a), &abs), "abs_f64 {level:?}");
    });
}

#[test]
fn float_comparisons_sort_and_unique_match_the_reference() {
    each(float_profiles, |a, b, level| {
        for &v in b.iter().take(2).chain(&[0.0, -0.0, f64::NAN, f64::INFINITY]) {
            assert_eq!(where_f64(a, Cmp::Greater(v)), positions(a, |x| x > v), "where > {v} {level:?}");
            assert_eq!(where_f64(a, Cmp::Less(v)), positions(a, |x| x < v), "where < {v} {level:?}");
            assert_eq!(where_f64(a, Cmp::Equal(v)), positions(a, |x| x == v), "where == {v} {level:?}");
            assert_eq!(where_f64(a, Cmp::Between(v, 1.0)), positions(a, |x| x >= v && x <= 1.0), "between {v} {level:?}");
        }

        // NaNs last; everything else in total order, so -0.0 sorts before 0.0.
        let mut sorted: Vec<f64> = a.iter().copied().filter(|v| !v.is_nan()).collect();
        sorted.sort_by(f64::total_cmp);
        sorted.extend(a.iter().copied().filter(|v| v.is_nan()));
        assert!(same_all(&sort_f64(a), &sorted), "sort_f64 {level:?} len {}", a.len());

        let mut unique: Vec<f64> = Vec::new();
        for &v in a {
            if !unique.iter().any(|&u| u == v || (u.is_nan() && v.is_nan())) {
                unique.push(v);
            }
        }
        assert!(same_all(&unique_f64(a), &unique), "unique_f64 {level:?} len {}", a.len());
    });
}

#[test]
fn every_level_is_exercised() {
    let levels = simd::supported();
    assert_eq!(levels.last(), Some(&Level::Scalar));
    assert_eq!(levels.first(), Some(&simd::level()));
}
