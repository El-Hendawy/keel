//! `Keel\Vector\FloatVector`: a contiguous list of 64-bit floats.

use std::sync::Arc;

use ext_php_rs::{
    boxed::ZBox,
    flags::ClassFlags,
    prelude::*,
    types::{ZendCallable, ZendClassObject, ZendHashTable, Zval},
    zend::ce,
};

use crate::errors::{self, type_name};
use crate::int_vector::IntVector;
use crate::iterator::{Items, VectorIterator};
use crate::kernels::{self, Cmp, FloatOp};

const CLASS: &str = "Keel\\Vector\\FloatVector";

/// Shares its buffer between clones and iterators like `IntVector`.
#[php_class]
#[php(name = "Keel\\Vector\\FloatVector")]
#[php(flags = ClassFlags::Final)]
#[php(implements(ce = ce::countable, stub = "\\Countable"))]
#[php(implements(ce = ce::arrayaccess, stub = "\\ArrayAccess"))]
#[php(implements(ce = ce::aggregate, stub = "\\IteratorAggregate"))]
#[php(implements(ce = crate::json_serializable, stub = "\\JsonSerializable"))]
#[derive(Clone, Default)]
pub struct FloatVector {
    data: Arc<Vec<f64>>,
}

/// An int or a float as a float (ints beyond 2^53 round to the nearest float, like `(float)`).
fn number(value: &Zval) -> Option<f64> {
    value.double().or_else(|| value.long().map(|v| v as f64))
}

impl FloatVector {
    fn from_vec(values: Vec<f64>) -> Self {
        Self { data: Arc::new(values) }
    }

    fn float(value: &Zval, method: &str, argument: &str) -> PhpResult<f64> {
        number(value).ok_or_else(|| {
            errors::type_error(format!(
                "{CLASS}::{method}(): Argument {argument} must be of type float|int, {} given",
                type_name(value)
            ))
        })
    }

    fn collect(values: &ZendHashTable, method: &str) -> PhpResult<Vec<f64>> {
        let mut out = Vec::new();
        out.try_reserve_exact(values.len())
            .map_err(|_| errors::vector(format!("{CLASS}::{method}(): cannot allocate {} elements", values.len())))?;
        for (key, value) in values.iter() {
            match number(value) {
                Some(v) => out.push(v),
                None => {
                    return Err(errors::type_error(format!(
                        "{CLASS}::{method}(): the value at key {key} is {}, but a FloatVector holds only float and int values; convert them first",
                        type_name(value)
                    )))
                }
            }
        }
        Ok(out)
    }

    fn element_wise(&self, op: FloatOp, other: &Zval, method: &str) -> PhpResult<Self> {
        if let Some(scalar) = number(other) {
            return Ok(Self::from_vec(kernels::scalar_f64(op, &self.data, scalar)));
        }
        if let Some(vector) = other.object().and_then(|o| ZendClassObject::<FloatVector>::from_zend_obj(o)) {
            if vector.data.len() != self.data.len() {
                return Err(errors::length_mismatch(CLASS, method, self.data.len(), vector.data.len()));
            }
            return Ok(Self::from_vec(kernels::zip_f64(op, &self.data, &vector.data)));
        }
        Err(errors::type_error(format!(
            "{CLASS}::{method}(): Argument #1 ($other) must be of type {CLASS}|float|int, {} given",
            type_name(other)
        )))
    }

    fn compare(&self, cmp: Cmp<f64>) -> IntVector {
        IntVector::from_vec(kernels::where_f64(&self.data, cmp))
    }

    fn set_at(&mut self, index: i64, value: f64, method: &str) -> PhpResult<()> {
        let i = errors::position(CLASS, method, index, self.data.len())?;
        Arc::make_mut(&mut self.data)[i] = value;
        Ok(())
    }

    fn append(&mut self, value: f64, method: &str) -> PhpResult<()> {
        let data = Arc::make_mut(&mut self.data);
        data.try_reserve(1)
            .map_err(|_| errors::vector(format!("{CLASS}::{method}(): cannot grow the vector beyond {} elements", data.len())))?;
        data.push(value);
        Ok(())
    }
}

#[php_impl]
impl FloatVector {
    /// Same as `fromArray()`; no argument makes an empty vector.
    #[php(optional = values)]
    pub fn __construct(values: Option<&ZendHashTable>) -> PhpResult<Self> {
        match values {
            Some(values) => Ok(Self::from_vec(Self::collect(values, "__construct")?)),
            None => Ok(Self::default()),
        }
    }

    /// The array's values in order (keys are ignored). Ints are converted to floats; numeric
    /// strings and booleans are refused.
    pub fn from_array(values: &ZendHashTable) -> PhpResult<Self> {
        Ok(Self::from_vec(Self::collect(values, "fromArray")?))
    }

    pub fn filled(length: i64, value: &Zval) -> PhpResult<Self> {
        let value = Self::float(value, "filled", "#2 ($value)")?;
        let length = usize::try_from(length)
            .map_err(|_| errors::value_error(format!("{CLASS}::filled(): Argument #1 ($length) must be greater than or equal to 0")))?;
        let mut out = Vec::new();
        out.try_reserve_exact(length)
            .map_err(|_| errors::vector(format!("{CLASS}::filled(): cannot allocate {length} elements")))?;
        out.resize(length, value);
        Ok(Self::from_vec(out))
    }

    /// `start + k * step` from `start` to `end` inclusive; counts down when `end < start`, and the
    /// sign of `step` is ignored. The end is included when it is within 1e-9 steps of a multiple.
    #[php(optional = step)]
    pub fn range(start: &Zval, end: &Zval, step: Option<&Zval>) -> PhpResult<Self> {
        let start = Self::float(start, "range", "#1 ($start)")?;
        let end = Self::float(end, "range", "#2 ($end)")?;
        let step = match step {
            Some(step) => Self::float(step, "range", "#3 ($step)")?,
            None => 1.0,
        };
        if step == 0.0 || !start.is_finite() || !end.is_finite() || !step.is_finite() {
            return Err(errors::value_error(format!(
                "{CLASS}::range(): start, end and step must be finite, and step cannot be 0"
            )));
        }
        kernels::range_f64(start, end, step)
            .map(Self::from_vec)
            .ok_or_else(|| errors::vector(format!("{CLASS}::range(): cannot allocate a range from {start} to {end} by {step}")))
    }

    pub fn empty() -> Self {
        Self::default()
    }

    pub fn to_array(&self) -> PhpResult<ZBox<ZendHashTable>> {
        crate::list(&self.data)
    }

    pub fn count(&self) -> i64 {
        self.data.len() as i64
    }

    pub fn is_empty(&self) -> bool {
        self.data.is_empty()
    }

    /// The bytes held by the element buffer (its capacity, which may exceed its length after push()).
    pub fn memory_bytes(&self) -> i64 {
        (self.data.capacity() * std::mem::size_of::<f64>()) as i64
    }

    pub fn get(&self, index: i64) -> PhpResult<f64> {
        Ok(self.data[errors::position(CLASS, "get", index, self.data.len())?])
    }

    pub fn set(&mut self, index: i64, value: &Zval) -> PhpResult<()> {
        let value = Self::float(value, "set", "#2 ($value)")?;
        self.set_at(index, value, "set")
    }

    pub fn push(&mut self, value: &Zval) -> PhpResult<()> {
        let value = Self::float(value, "push", "#1 ($value)")?;
        self.append(value, "push")
    }

    pub fn pop(&mut self) -> PhpResult<f64> {
        if self.data.is_empty() {
            return Err(errors::underflow(CLASS, "pop"));
        }
        Ok(Arc::make_mut(&mut self.data).pop().unwrap_or_default())
    }

    /// A copy of part of the vector, with `array_slice()`'s rules for negative offsets and lengths.
    #[php(optional = length)]
    pub fn slice(&self, offset: i64, length: Option<i64>) -> Self {
        let (start, end) = errors::slice_bounds(self.data.len(), offset, length);
        Self::from_vec(self.data[start..end].to_vec())
    }

    pub fn offset_exists(&self, offset: &Zval) -> bool {
        offset.long().and_then(|i| usize::try_from(i).ok()).is_some_and(|i| i < self.data.len())
    }

    pub fn offset_get(&self, offset: &Zval) -> PhpResult<f64> {
        let index = errors::offset(CLASS, "offsetGet", offset)?;
        Ok(self.data[errors::position(CLASS, "offsetGet", index, self.data.len())?])
    }

    /// `$vector[] = $value` appends; `$vector[$i] = $value` replaces an existing element.
    pub fn offset_set(&mut self, offset: &Zval, value: &Zval) -> PhpResult<()> {
        let value = Self::float(value, "offsetSet", "#2 ($value)")?;
        if offset.is_null() {
            return self.append(value, "offsetSet");
        }
        let index = errors::offset(CLASS, "offsetSet", offset)?;
        self.set_at(index, value, "offsetSet")
    }

    pub fn offset_unset(&mut self, offset: &Zval) -> PhpResult<()> {
        let _ = offset;
        Err(errors::vector(format!(
            "{CLASS}::offsetUnset(): a vector has no holes, so its elements cannot be unset; use slice(), filter() or pop()"
        )))
    }

    pub fn get_iterator(&self) -> VectorIterator {
        VectorIterator::new(Items::Float(Arc::clone(&self.data)))
    }

    pub fn json_serialize(&self) -> PhpResult<ZBox<ZendHashTable>> {
        crate::list(&self.data)
    }

    #[php(name = "__serialize")]
    pub fn serialize(&self) -> PhpResult<ZBox<ZendHashTable>> {
        crate::list(&self.data)
    }

    #[php(name = "__unserialize")]
    pub fn unserialize(&mut self, data: &ZendHashTable) -> PhpResult<()> {
        self.data = Arc::new(Self::collect(data, "__unserialize")?);
        Ok(())
    }

    /// The sum, added in a fixed order (eight running totals, element i into total i % 8), so the
    /// result may differ from a left-to-right sum in the last bits but never between machines.
    pub fn sum(&self) -> f64 {
        kernels::sum_f64(&self.data)
    }

    /// The smallest value; NaN if any value is NaN; -0.0 counts as smaller than 0.0.
    pub fn min(&self) -> PhpResult<f64> {
        kernels::min_f64(&self.data).ok_or_else(|| errors::underflow(CLASS, "min"))
    }

    /// The largest value; NaN if any value is NaN; 0.0 counts as larger than -0.0.
    pub fn max(&self) -> PhpResult<f64> {
        kernels::max_f64(&self.data).ok_or_else(|| errors::underflow(CLASS, "max"))
    }

    pub fn mean(&self) -> PhpResult<f64> {
        kernels::mean_f64(&self.data).ok_or_else(|| errors::underflow(CLASS, "mean"))
    }

    /// The dot product, in the same fixed order as `sum()`.
    pub fn dot(&self, other: &Zval) -> PhpResult<f64> {
        let other = errors::vector_argument::<FloatVector>(other, CLASS, "dot", "#1 ($other)")?;
        if other.data.len() != self.data.len() {
            return Err(errors::length_mismatch(CLASS, "dot", self.data.len(), other.data.len()));
        }
        Ok(kernels::dot_f64(&self.data, &other.data))
    }

    pub fn add(&self, other: &Zval) -> PhpResult<Self> {
        self.element_wise(FloatOp::Add, other, "add")
    }

    pub fn sub(&self, other: &Zval) -> PhpResult<Self> {
        self.element_wise(FloatOp::Sub, other, "sub")
    }

    pub fn mul(&self, other: &Zval) -> PhpResult<Self> {
        self.element_wise(FloatOp::Mul, other, "mul")
    }

    /// IEEE 754 division, like `fdiv()`: dividing by zero gives INF, -INF or NaN instead of throwing.
    pub fn div(&self, other: &Zval) -> PhpResult<Self> {
        self.element_wise(FloatOp::Div, other, "div")
    }

    pub fn abs(&self) -> Self {
        Self::from_vec(kernels::abs_f64(&self.data))
    }

    /// The indices of the elements greater than `value`. NaN is never greater, smaller or equal.
    pub fn where_greater_than(&self, value: &Zval) -> PhpResult<IntVector> {
        Ok(self.compare(Cmp::Greater(Self::float(value, "whereGreaterThan", "#1 ($value)")?)))
    }

    pub fn where_less_than(&self, value: &Zval) -> PhpResult<IntVector> {
        Ok(self.compare(Cmp::Less(Self::float(value, "whereLessThan", "#1 ($value)")?)))
    }

    pub fn where_equals(&self, value: &Zval) -> PhpResult<IntVector> {
        Ok(self.compare(Cmp::Equal(Self::float(value, "whereEquals", "#1 ($value)")?)))
    }

    /// The indices of the elements in `[min, max]`, both ends included.
    pub fn where_between(&self, min: &Zval, max: &Zval) -> PhpResult<IntVector> {
        let min = Self::float(min, "whereBetween", "#1 ($min)")?;
        let max = Self::float(max, "whereBetween", "#2 ($max)")?;
        Ok(self.compare(Cmp::Between(min, max)))
    }

    /// The elements at the given indices, in that order (repeats allowed).
    pub fn take(&self, indices: &Zval) -> PhpResult<Self> {
        let indices = errors::vector_argument::<IntVector>(indices, CLASS, "take", "#1 ($indices)")?;
        kernels::take(&self.data, indices.values()).map(Self::from_vec).map_err(|(_, index)| {
            errors::bad_index(CLASS, "take", index, self.data.len())
        })
    }

    /// Ascending; -0.0 before 0.0, NaN last.
    pub fn sort(&self) -> Self {
        Self::from_vec(kernels::sort_f64(&self.data))
    }

    /// The first occurrence of each value, in order. -0.0 repeats 0.0, and every NaN repeats the first.
    pub fn unique(&self) -> Self {
        Self::from_vec(kernels::unique_f64(&self.data))
    }

    /// Calls `$fn($value)` for each element, which must return a float or an int. Slow: one PHP call
    /// per element.
    pub fn map(&self, callback: ZendCallable) -> PhpResult<Self> {
        let mut out = Vec::with_capacity(self.data.len());
        for &v in self.data.iter() {
            let result = callback.try_call(vec![&v]).map_err(|e| PhpException::from_message(e.to_string()))?;
            out.push(number(&result).ok_or_else(|| {
                errors::type_error(format!(
                    "{CLASS}::map(): the callback must return float or int, {} returned",
                    type_name(&result)
                ))
            })?);
        }
        Ok(Self::from_vec(out))
    }

    /// The elements for which `$fn($value)` is truthy. Slow: one PHP call per element.
    pub fn filter(&self, callback: ZendCallable) -> PhpResult<Self> {
        let mut out = Vec::new();
        for &v in self.data.iter() {
            let result = callback.try_call(vec![&v]).map_err(|e| PhpException::from_message(e.to_string()))?;
            if result.coerce_to_bool() {
                out.push(v);
            }
        }
        Ok(Self::from_vec(out))
    }
}
