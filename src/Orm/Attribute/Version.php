<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/** Optimistic locking: an int incremented on every update; a concurrent change raises StaleEntity (409). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Version
{
}
