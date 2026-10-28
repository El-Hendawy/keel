<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

use Attribute;

/**
 * Injects a configuration value into a scalar constructor parameter.
 *
 *     public function __construct(#[Config('database.write')] private string $dsn) {}
 *
 * The key is checked at boot: a missing key without a parameter default is a boot error.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Config
{
    public function __construct(public string $key)
    {
    }
}
