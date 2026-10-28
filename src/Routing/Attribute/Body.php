<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

use Attribute;

/** Hydrate this input parameter from the JSON request body (the default for POST, PUT and PATCH). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Body
{
}
