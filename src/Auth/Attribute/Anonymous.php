<?php

declare(strict_types=1);

namespace Keel\Auth\Attribute;

use Attribute;

/**
 * Allows an endpoint to be called without credentials. Once ->withAuth() is on, every
 * other endpoint requires an authenticated principal, so a new endpoint is never public
 * by accident.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Anonymous
{
}
