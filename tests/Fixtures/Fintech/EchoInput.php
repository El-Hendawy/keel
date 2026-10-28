<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Fintech;

final readonly class EchoInput
{
    public function __construct(public string $message)
    {
    }
}
