<?php

declare(strict_types=1);

namespace Keel\Vector;

/**
 * Iterates over a snapshot of a vector's values, with their indices as keys.
 *
 * @implements \Iterator<int, int|float|null>
 */
final class VectorIterator implements \Iterator
{
    private int $position = 0;

    /**
     * @param list<int>|list<float> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @internal used by IntVector and FloatVector
     *
     * @param list<int>|list<float> $values
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    public function current(): mixed
    {
        return $this->values[$this->position] ?? null;
    }

    public function key(): ?int
    {
        return $this->position < \count($this->values) ? $this->position : null;
    }

    public function next(): void
    {
        $this->position++;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return $this->position < \count($this->values);
    }
}
