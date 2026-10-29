//! `Keel\Vector\IntVector`: a contiguous list of 64-bit integers.

use std::sync::Arc;

use ext_php_rs::{
    flags::ClassFlags,
    prelude::*,
    types::{ZendCallable, ZendHashTable, Zval},
    boxed::ZBox,
    zend::ce,
};

use crate::errors::{self, type_name};
use crate::iterator::{Items, VectorIterator};
use crate::kernels::{self, Cmp, IntOp};

const CLASS: &str = "Keel\\Vector\\IntVector";

/// The buffer is shared between clones and iterators and copied on the first write (`Arc::make_mut`),
/// so `clone` and `foreach` are O(1) and still behave like deep copies.
#[php_class]
#[php(name = "Keel\\Vector\\IntVector")]
#[php(flags = ClassFlags::Final)]
#[php(implements(ce = ce::countable, stub = "\\Countable"))]
#[php(implements(ce = ce::arrayaccess, stub = "\\ArrayAccess"))]
#[php(implements(ce = ce::aggregate, stub = "\\IteratorAggregate"))]
#[php(implements(ce = crate::json_serializable, stub = "\\JsonSerializable"))]
#[derive(Clone, Default)]
pub struct IntVector {
    data: Arc<Vec<i64>>,
}

impl IntVector {
    pub fn from_vec(values: Vec<i64>) -> Self {
        Self { data: Arc::new(values) }
    }

    pub fn values(&self) -> &[i64] {
        &self.data
    }

    fn int(value: &Zval, method: &str, argument: &str) -> PhpResult<i64> {
        value.long().ok_or_else(|| {
            errors::type_error(format!(
                "{CLASS}::{method}(): Argument {argument} must be of type int, {} given",
                type_name(value)
            ))
        })
    }

    fn collect(values: &ZendHashTable, method: &str) -> PhpResult<Vec<i64>> {
        let mut out = Vec::new();
        out.try_reserve_exact(values.len())
            .map_err(|_| errors::vector(format!("{CLASS}::{method}(): cannot allocate {} elements", values.len())))?;
        for (key, value) in values.iter() {
            match value.long() {
                Some(v) => out.push(v),
                None => {
                    return Err(errors::type_error(format!(
                        "{CLASS}::{method}(): the value at key {key} is {}, but an IntVector holds only int values; convert them first, or use FloatVector",
                        type_name(value)
                    )))
                }
            }
        }
        Ok(out)
    }

    fn other<'a>(value: &'a Zval, method: &str) -> PhpResult<Result<&'a IntVector, i64>> {
        if let Some(v) = value.long() {
            return Ok(Err(v));
        }
        if let Some(vector) = value.object().and_then(|o| ext_php_rs::types::ZendClassObject::<IntVector>::from_zend_obj(o)) {
            return Ok(Ok(&**vector));
        }
        Err(errors::type_error(format!(
            "{CLASS}::{method}(): Argument #1 ($other) must be of type {CLASS}|int, {} given",
            type_name(value)
        )))
    }

    fn element_wise(&self, op: IntOp, other: &Zval, method: &str) -> PhpResult<Self> {
        let result = match Self::other(other, method)? {
            Ok(vector) => {
                if vector.data.len() != self.data.len() {
                    return Err(errors::length_mismatch(CLASS, method, self.data.len(), vector.data.len()));
                }
                kernels::zip_i64(op, &self.data, &vector.data).map_err(|i| (i, vector.data[i]))
            }
            Err(scalar) => kernels::scalar_i64(op, &self.data, scalar).map_err(|i| (i, scalar)),
        };
        result.map(Self::from_vec).map_err(|(i, right)| {
            errors::overflow(format!(
                "{CLASS}::{method}(): {} {} {right} at index {i} does not fit in an int; use FloatVector for values beyond ±9.2e18",
                self.data[i],
                op.symbol(),
            ))
        })
    }

    fn compare(&self, cmp: Cmp<i64>) -> Self {
        Self::from_vec(kernels::where_i64(&self.data, cmp))
    }

    fn set_at(&mut self, index: i64, value: i64, method: &str) -> PhpResult<()> {
        let i = errors::position(CLASS, method, index, self.data.len())?;
        Arc::make_mut(&mut self.data)[i] = value;
        Ok(())
    }

    fn append(&mut self, value: i64, method: &str) -> PhpResult<()> {
        let data = Arc::make_mut(&mut self.data);
        data.try_reserve(1)
            .map_err(|_| errors::vector(format!("{CLASS}::{method}(): cannot grow the vector beyond {} elements", data.len())))?;
        data.push(value);
        Ok(())
    }
}

#[php_impl]
impl IntVector {
    /// Same as `fromArray()`; no argument makes an empty vector.
    #[php(optional = values)]
    pub fn __construct(values: Option<&ZendHashTable>) -> PhpResult<Self> {
        match values {
            Some(values) => Ok(Self::from_vec(Self::collect(values, "__construct")?)),
            None => Ok(Self::default()),
        }
    }

    /// The array's values in order (keys are ignored). Every value must be an int: floats, numeric
    /// strings and booleans are refused, not converted.
    pub fn from_array(values: &ZendHashTable) -> PhpResult<Self> {
        Ok(Self::from_vec(Self::collect(values, "fromArray")?))
    }

    pub fn filled(length: i64, value: &Zval) -> PhpResult<Self> {
        let value = Self::int(value, "filled", "#2 ($value)")?;
        let length = usize::try_from(length)
            .map_err(|_| errors::value_error(format!("{CLASS}::filled(): Argument #1 ($length) must be greater than or equal to 0")))?;
        let mut out = Vec::new();
        out.try_reserve_exact(length)
            .map_err(|_| errors::vector(format!("{CLASS}::filled(): cannot allocate {length} elements")))?;
        out.resize(length, value);
        Ok(Self::from_vec(out))
    }

    /// `start` to `end` inclusive, like PHP's `range()`: counts down when `end < start`, and the
    /// sign of `step` is ignored.
    #[php(defaults(step = 1))]
    pub fn range(start: i64, end: i64, step: i64) -> PhpResult<Self> {
        if step == 0 {
            return Err(errors::value_error(format!("{CLASS}::range(): Argument #3 ($step) cannot be 0")));
        }
        kernels::range_i64(start, end, step)
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
        (self.data.capacity() * std::mem::size_of::<i64>()) as i64
    }

    pub fn get(&self, index: i64) -> PhpResult<i64> {
        Ok(self.data[errors::position(CLASS, "get", index, self.data.len())?])
    }

    pub fn set(&mut self, index: i64, value: &Zval) -> PhpResult<()> {
        let value = Self::int(value, "set", "#2 ($value)")?;
        self.set_at(index, value, "set")
    }

    pub fn push(&mut self, value: &Zval) -> PhpResult<()> {
        let value = Self::int(value, "push", "#1 ($value)")?;
        self.append(value, "push")
    }

    pub fn pop(&mut self) -> PhpResult<i64> {
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

    pub fn offset_get(&self, offset: &Zval) -> PhpResult<i64> {
        let index = errors::offset(CLASS, "offsetGet", offset)?;
        Ok(self.data[errors::position(CLASS, "offsetGet", index, self.data.len())?])
    }

    /// `$vector[] = $value` appends; `$vector[$i] = $value` replaces an existing element.
    pub fn offset_set(&mut self, offset: &Zval, value: &Zval) -> PhpResult<()> {
        let value = Self::int(value, "offsetSet", "#2 ($value)")?;
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
        VectorIterator::new(Items::Int(Arc::clone(&self.data)))
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

    /// The exact sum. Throws `OverflowException` when it does not fit in an int.
    pub fn sum(&self) -> PhpResult<i64> {
        kernels::sum_i64(&self.data).ok_or_else(|| {
            errors::overflow(format!("{CLASS}::sum(): the sum does not fit in an int; use mean(), or a FloatVector"))
        })
    }

    pub fn min(&self) -> PhpResult<i64> {
        kernels::min_i64(&self.data).ok_or_else(|| errors::underflow(CLASS, "min"))
    }

    pub fn max(&self) -> PhpResult<i64> {
        kernels::max_i64(&self.data).ok_or_else(|| errors::underflow(CLASS, "max"))
    }

    /// The exact sum rounded once to a float, divided by the count. Never overflows.
    pub fn mean(&self) -> PhpResult<f64> {
        kernels::mean_i64(&self.data).ok_or_else(|| errors::underflow(CLASS, "mean"))
    }

    /// The exact dot product. Throws `OverflowException` when it does not fit in an int.
    pub fn dot(&self, other: &Zval) -> PhpResult<i64> {
        let other = errors::vector_argument::<IntVector>(other, CLASS, "dot", "#1 ($other)")?;
        if other.data.len() != self.data.len() {
            return Err(errors::length_mismatch(CLASS, "dot", self.data.len(), other.data.len()));
        }
        kernels::dot_i64(&self.data, &other.data).ok_or_else(|| {
            errors::overflow(format!("{CLASS}::dot(): the dot product does not fit in an int; use FloatVector"))
        })
    }

    pub fn add(&self, other: &Zval) -> PhpResult<Self> {
        self.element_wise(IntOp::Add, other, "add")
    }

    pub fn sub(&self, other: &Zval) -> PhpResult<Self> {
        self.element_wise(IntOp::Sub, other, "sub")
    }

    pub fn mul(&self, other: &Zval) -> PhpResult<Self> {
        self.element_wise(IntOp::Mul, other, "mul")
    }

    pub fn abs(&self) -> PhpResult<Self> {
        kernels::abs_i64(&self.data).map(Self::from_vec).map_err(|i| {
            errors::overflow(format!(
                "{CLASS}::abs(): the absolute value of {} at index {i} does not fit in an int",
                i64::MIN
            ))
        })
    }

    /// The indices of the elements greater than `value`.
    pub fn where_greater_than(&self, value: &Zval) -> PhpResult<Self> {
        Ok(self.compare(Cmp::Greater(Self::int(value, "whereGreaterThan", "#1 ($value)")?)))
    }

    pub fn where_less_than(&self, value: &Zval) -> PhpResult<Self> {
        Ok(self.compare(Cmp::Less(Self::int(value, "whereLessThan", "#1 ($value)")?)))
    }

    pub fn where_equals(&self, value: &Zval) -> PhpResult<Self> {
        Ok(self.compare(Cmp::Equal(Self::int(value, "whereEquals", "#1 ($value)")?)))
    }

    /// The indices of the elements in `[min, max]`, both ends included.
    pub fn where_between(&self, min: &Zval, max: &Zval) -> PhpResult<Self> {
        let min = Self::int(min, "whereBetween", "#1 ($min)")?;
        let max = Self::int(max, "whereBetween", "#2 ($max)")?;
        Ok(self.compare(Cmp::Between(min, max)))
    }

    /// The elements at the given indices, in that order (repeats allowed).
    pub fn take(&self, indices: &Zval) -> PhpResult<Self> {
        let indices = errors::vector_argument::<IntVector>(indices, CLASS, "take", "#1 ($indices)")?;
        kernels::take(&self.data, &indices.data).map(Self::from_vec).map_err(|(_, index)| {
            errors::bad_index(CLASS, "take", index, self.data.len())
        })
    }

    pub fn sort(&self) -> Self {
        Self::from_vec(kernels::sort_i64(&self.data))
    }

    /// The first occurrence of each value, in order.
    pub fn unique(&self) -> Self {
        Self::from_vec(kernels::unique_i64(&self.data))
    }

    /// Calls `$fn($value)` for each element, which must return an int. Slow: one PHP call per element.
    pub fn map(&self, callback: ZendCallable) -> PhpResult<Self> {
        let mut out = Vec::with_capacity(self.data.len());
        for &v in self.data.iter() {
            let result = callback.try_call(vec![&v]).map_err(|e| PhpException::from_message(e.to_string()))?;
            out.push(result.long().ok_or_else(|| {
                errors::type_error(format!(
                    "{CLASS}::map(): the callback must return int, {} returned",
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
