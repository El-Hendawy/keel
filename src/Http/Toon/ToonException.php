<?php

declare(strict_types=1);

namespace Keel\Http\Toon;

final class ToonException extends \InvalidArgumentException
{
    public static function at(int $line, string $message): self
    {
        return new self("TOON line $line: $message");
    }
}
