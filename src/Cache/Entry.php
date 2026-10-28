<?php

declare(strict_types=1);

namespace Keel\Cache;

/** @internal a cached value with what XFetch needs: its expiry and how long it took to compute */
final readonly class Entry
{
    public function __construct(public mixed $value, public float $expiresAt, public float $computeSeconds)
    {
    }

    /** XFetch (Vattani et al.): refresh early with a probability that grows as expiry nears. */
    public function shouldRefresh(float $now, float $beta = 1.0): bool
    {
        if ($this->expiresAt === 0.0) {
            return false;
        }
        $random = max(1e-12, random_int(1, \PHP_INT_MAX) / \PHP_INT_MAX);

        return $now - $this->computeSeconds * $beta * log($random) >= $this->expiresAt;
    }

    public static function check(mixed $value): void
    {
        if (\is_object($value) || \is_resource($value)) {
            throw new \InvalidArgumentException('Only null, scalars and arrays can be cached. Cache the data of ' . get_debug_type($value) . ', not the object.');
        }
        if (\is_array($value)) {
            array_walk_recursive($value, static fn (mixed $item) => self::check($item));
        }
    }
}
