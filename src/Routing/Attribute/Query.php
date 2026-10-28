<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

use Attribute;

/** Hydrate this input parameter from the query string (the default for GET, HEAD and DELETE). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Query
{
}
