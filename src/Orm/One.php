<?php

declare(strict_types=1);

namespace Keel\Orm;

/**
 * A to-one relation (BelongsTo, HasOne), loaded only by eager loading. get() before that
 * throws RelationNotLoaded.
 *
 * @template T of object
 */
final class One
{
    /** @var T|null */
    private ?object $value = null;

    private bool $loaded = false;

    /** @return T|null */
    public function get(): ?object
    {
        if (!$this->loaded) {
            throw new RelationNotLoaded('This relation was not loaded. Add it to ->with(...) or call $orm->load(...).');
        }

        return $this->value;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * @internal filled by the ORM when eager loading
     * @param T|null $value
     */
    public function fill(?object $value): void
    {
        $this->value = $value;
        $this->loaded = true;
    }
}
