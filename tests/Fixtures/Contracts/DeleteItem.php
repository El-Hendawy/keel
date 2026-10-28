<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Routing\Attribute\Delete;

#[Delete('/items/{id}')]
final class DeleteItem
{
    public function __invoke(int $id): void
    {
    }
}
