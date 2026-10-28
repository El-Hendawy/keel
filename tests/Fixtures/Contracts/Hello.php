<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Routing\Attribute\Get;

/** Greets someone. */
#[Get('/hello/{name}')]
final class Hello
{
    public function __invoke(string $name, Greeter $greeter, UnitOfWork $work): Greeting
    {
        return new Greeting($greeter->greet($name), spl_object_hash($work));
    }
}
