<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class ServiceNotFound extends ContainerError implements NotFoundExceptionInterface
{
    public static function for(string $id): self
    {
        return new self(\sprintf(
            '"%s" is not a registered service. Add #[Singleton], #[Scoped] or #[Transient] to the class, or bind it in app.php.',
            $id,
        ));
    }
}
