<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

final class ConfigMissing extends ContainerError
{
    public static function key(string $key): self
    {
        return new self(\sprintf('Configuration key "%s" is required but not set.', $key));
    }

    public static function type(string $key, string $expected, mixed $actual): self
    {
        return new self(\sprintf(
            'Configuration key "%s" must be %s, %s given.',
            $key,
            $expected,
            get_debug_type($actual),
        ));
    }
}
