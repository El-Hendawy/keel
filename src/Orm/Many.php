<?php

declare(strict_types=1);

namespace Keel\Orm;

/**
 * A to-many relation. It is empty and *not loaded* until you eager-load it with
 * `->with('lines')` or `$orm->load($entities, 'lines')`; reading it before that throws
 * instead of silently running one query per entity (the N+1 problem).
 *
 * @template T of object
 * @implements \IteratorAggregate<int, T>
 */
final class Many implements \IteratorAggregate, \Countable
{
    /** @param list<T> $items */
    public function __construct(private array $items = [], private bool $loaded = false)
    {
    }

    /**
     * A loaded relation, e.g. for a new entity: `lines: Many::of([$line])`.
     *
     * @template U of object
     * @param list<U> $items
     * @return self<U>
     */
    public static function of(array $items): self
    {
        return new self($items, true);
    }

    /** @return list<T> */
    public function all(): array
    {
        if (!$this->loaded) {
            throw new RelationNotLoaded('This relation was not loaded. Add it to ->with(...) or call $orm->load(...).');
        }

        return $this->items;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /** @return \ArrayIterator<int, T> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    public function count(): int
    {
        return \count($this->all());
    }

    /**
     * @internal filled by the ORM when eager loading
     * @param list<T> $items
     */
    public function fill(array $items): void
    {
        $this->items = $items;
        $this->loaded = true;
    }
}
