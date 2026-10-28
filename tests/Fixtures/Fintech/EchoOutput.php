<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Fintech;

final readonly class EchoOutput
{
    public function __construct(public string $message, public bool $encrypted)
    {
    }
}
