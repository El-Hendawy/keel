//! The extension's exception classes, and the standard ones it throws.

use ext_php_rs::{
    class::RegisteredClass,
    exception::PhpException,
    flags::ClassFlags,
    prelude::*,
    types::{ZendClassObject, Zval},
    zend::{ce, ClassEntry},
};

fn spl(name: &str) -> &'static ClassEntry {
    crate::find_class(name).unwrap_or_else(ce::exception)
}

fn runtime_exception() -> &'static ClassEntry {
    spl("RuntimeException")
}

/// The parent of every exception the vectors define.
#[php_class]
#[php(name = "Keel\\Vector\\VectorException")]
#[php(extends(ce = runtime_exception, stub = "\\RuntimeException"))]
#[derive(Default)]
pub struct VectorException;

/// An integer result that does not fit in 64 bits.
#[php_class]
#[php(name = "Keel\\Vector\\OverflowException")]
#[php(extends(VectorException))]
#[php(flags = ClassFlags::Final)]
#[derive(Default)]
pub struct OverflowException;

/// Two vectors combined element by element have different lengths.
#[php_class]
#[php(name = "Keel\\Vector\\LengthMismatchException")]
#[php(extends(VectorException))]
#[php(flags = ClassFlags::Final)]
#[derive(Default)]
pub struct LengthMismatchException;

pub fn vector(message: String) -> PhpException {
    PhpException::from_class::<VectorException>(message)
}

pub fn overflow(message: String) -> PhpException {
    PhpException::from_class::<OverflowException>(message)
}

pub fn length_mismatch(class: &str, method: &str, left: usize, right: usize) -> PhpException {
    PhpException::from_class::<LengthMismatchException>(format!(
        "{class}::{method}(): the vectors have {left} and {right} elements; element-wise operations need equal lengths (use slice() to align them)"
    ))
}

pub fn out_of_range(message: String) -> PhpException {
    PhpException::new(message, 0, spl("OutOfRangeException"))
}

pub fn underflow(class: &str, method: &str) -> PhpException {
    PhpException::new(
        format!("{class}::{method}(): the vector is empty; check isEmpty() first"),
        0,
        spl("UnderflowException"),
    )
}

pub fn type_error(message: String) -> PhpException {
    PhpException::new(message, 0, ce::type_error())
}

pub fn value_error(message: String) -> PhpException {
    PhpException::new(message, 0, ce::value_error())
}

/// The PHP name of a value's type, as PHP's own type errors print it.
pub fn type_name(value: &Zval) -> String {
    if value.is_long() {
        "int".into()
    } else if value.is_double() {
        "float".into()
    } else if value.is_string() {
        "string".into()
    } else if value.is_true() || value.is_false() {
        "bool".into()
    } else if value.is_null() {
        "null".into()
    } else if value.is_array() {
        "array".into()
    } else if let Some(object) = value.object() {
        object.get_class_name().unwrap_or_else(|_| "object".into())
    } else {
        "resource".into()
    }
}

/// An index that is not inside the vector.
pub fn bad_index(class: &str, method: &str, index: i64, len: usize) -> PhpException {
    let valid = if len == 0 { "the vector is empty".to_owned() } else { format!("valid indices are 0 to {}", len - 1) };
    out_of_range(format!("{class}::{method}(): index {index} is out of range; {valid}"))
}

/// Converts a PHP index to a position in a vector of `len` elements.
pub fn position(class: &str, method: &str, index: i64, len: usize) -> Result<usize, PhpException> {
    usize::try_from(index).ok().filter(|&i| i < len).ok_or_else(|| bad_index(class, method, index, len))
}

/// The integer offset given to `$vector[...]`.
pub fn offset(class: &str, method: &str, offset: &Zval) -> Result<i64, PhpException> {
    offset
        .long()
        .ok_or_else(|| type_error(format!("{class}::{method}(): the offset must be of type int, {} given", type_name(offset))))
}

/// The values of a slice like `array_slice($values, $offset, $length)`: a negative offset counts
/// from the end, a negative length stops that many elements before the end.
pub fn slice_bounds(len: usize, offset: i64, length: Option<i64>) -> (usize, usize) {
    let n = len as i128;
    let start = if offset < 0 { (n + offset as i128).max(0) } else { (offset as i128).min(n) };
    let end = match length {
        None => n,
        Some(l) if l < 0 => (n + l as i128).max(start),
        Some(l) => (start + l as i128).min(n),
    };
    (start as usize, end.max(start) as usize)
}

/// The vector of class `T` passed as `argument`, or a TypeError naming what was given.
pub fn vector_argument<'a, T: RegisteredClass>(
    value: &'a Zval,
    class: &str,
    method: &str,
    argument: &str,
) -> Result<&'a T, PhpException> {
    value
        .object()
        .and_then(|o| ZendClassObject::<T>::from_zend_obj(o))
        .map(|o| &**o)
        .ok_or_else(|| {
            type_error(format!(
                "{class}::{method}(): Argument {argument} must be of type {}, {} given",
                T::CLASS_NAME,
                type_name(value)
            ))
        })
}
