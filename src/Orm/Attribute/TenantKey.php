<?php

declare(strict_types=1);

namespace Keel\Orm\Attribute;

use Attribute;

/** The tenant column. Queries are filtered by the current tenant automatically and inserts get it; writing another tenant's row is a TenantViolation. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class TenantKey
{
}
