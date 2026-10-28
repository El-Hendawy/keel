<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

use Attribute;
use Keel\Container\Lifetime;

/**
 * Marks a public method on a service as the factory for its declared return type.
 * Use it for classes you do not own (PDO, Redis, SDK clients).
 *
 *     #[Factory(Lifetime::Singleton)]
 *     public function redis(): \Redis { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Factory
{
    public function __construct(public Lifetime $lifetime = Lifetime::Singleton)
    {
    }
}
