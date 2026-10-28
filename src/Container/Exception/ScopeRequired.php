<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

final class ScopeRequired extends ContainerError
{
    public static function for(string $id): self
    {
        return new self(\sprintf(
            '"%s" is Scoped and can only be resolved inside a scope (a request, job or message).',
            $id,
        ));
    }
}
