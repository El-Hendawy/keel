<?php

declare(strict_types=1);

namespace Keel\Support;

/** Generates unique identifiers. Inject it instead of calling a UUID library directly, so tests are deterministic. */
interface IdGenerator
{
    public function generate(): string;
}
