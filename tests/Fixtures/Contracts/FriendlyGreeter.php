<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Contracts;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;

#[Singleton]
final readonly class FriendlyGreeter implements Greeter
{
    public function __construct(#[Config('greeting.word')] private string $word = 'Hello', #[Config('greeting.excited')] private bool $excited = false)
    {
    }

    public function greet(string $name): string
    {
        return "{$this->word}, $name" . ($this->excited ? '!' : '.');
    }
}
