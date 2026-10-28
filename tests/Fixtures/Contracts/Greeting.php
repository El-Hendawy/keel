<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

final readonly class Greeting
{
    public function __construct(public string $message, public string $unitOfWork)
    {
    }
}
