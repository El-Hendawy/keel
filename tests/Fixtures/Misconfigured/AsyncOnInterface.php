<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Misconfigured;

use Keel\Events\Listener;

#[Listener(async: true)]
final class AsyncOnInterface
{
    public function __invoke(SomethingHappened $event): void
    {
    }
}
