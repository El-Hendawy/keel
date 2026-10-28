<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

use Attribute;

/**
 * Injects every registered service that is an instance of the given type, as a list.
 *
 *     public function __construct(#[All(HealthCheck::class)] private array $checks) {}
 *
 * The list is resolved at build time, so adding a service never needs extra wiring.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class All
{
    /** @param class-string $type */
    public function __construct(public string $type)
    {
    }
}
