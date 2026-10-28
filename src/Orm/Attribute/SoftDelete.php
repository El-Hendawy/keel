<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/** A ?DateTimeImmutable; delete() sets it, and queries skip deleted rows unless withTrashed(). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class SoftDelete
{
}
