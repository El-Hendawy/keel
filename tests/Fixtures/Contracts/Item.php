<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Routing\Attribute\Get;

/** Fetches an item by numeric id. */
#[Get('/items/{id}')]
final class Item
{
    public function __invoke(int $id): Greeting
    {
        return new Greeting("item $id", '');
    }
}
