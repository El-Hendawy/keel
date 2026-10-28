<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

use Attribute;

/** The success status code of an endpoint, for example `#[Status(201)]`. Defaults to 200. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Status
{
    public function __construct(public int $code)
    {
    }
}
