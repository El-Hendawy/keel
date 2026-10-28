<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/** A ?DateTimeImmutable set on insert. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CreatedAt
{
}
