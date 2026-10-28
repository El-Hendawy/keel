<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Container\Attribute\All;
use Keel\Container\Attribute\Singleton;

#[Singleton]
final readonly class PluginRegistry
{
    /** @param list<Plugin> $plugins */
    public function __construct(#[All(Plugin::class)] public array $plugins)
    {
    }
}
