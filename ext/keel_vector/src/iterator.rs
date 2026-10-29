//! What `foreach` walks over: a snapshot of the vector taken when the loop starts.

use std::sync::Arc;

use ext_php_rs::{flags::ClassFlags, prelude::*, types::Zval, zend::ce};

#[derive(Clone)]
pub enum Items {
    Int(Arc<Vec<i64>>),
    Float(Arc<Vec<f64>>),
}

impl Default for Items {
    fn default() -> Self {
        Items::Int(Arc::default())
    }
}

impl Items {
    fn len(&self) -> usize {
        match self {
            Items::Int(v) => v.len(),
            Items::Float(v) => v.len(),
        }
    }
}

/// Iterates over a vector's values with their indices as keys. Shares the vector's buffer, so
/// starting a loop copies nothing; a vector changed during the loop copies its buffer first.
#[php_class]
#[php(name = "Keel\\Vector\\VectorIterator")]
#[php(flags = ClassFlags::Final)]
#[php(implements(ce = ce::iterator, stub = "\\Iterator"))]
#[derive(Default)]
pub struct VectorIterator {
    items: Items,
    position: usize,
}

impl VectorIterator {
    pub fn new(items: Items) -> Self {
        Self { items, position: 0 }
    }
}

#[php_impl]
impl VectorIterator {
    pub fn current(&self) -> Zval {
        let mut value = Zval::new();
        match &self.items {
            Items::Int(v) => {
                if let Some(&x) = v.get(self.position) {
                    value.set_long(x);
                }
            }
            Items::Float(v) => {
                if let Some(&x) = v.get(self.position) {
                    value.set_double(x);
                }
            }
        }
        value
    }

    pub fn key(&self) -> Option<i64> {
        (self.position < self.items.len()).then_some(self.position as i64)
    }

    pub fn next(&mut self) {
        self.position = self.position.saturating_add(1);
    }

    pub fn rewind(&mut self) {
        self.position = 0;
    }

    pub fn valid(&self) -> bool {
        self.position < self.items.len()
    }
}
