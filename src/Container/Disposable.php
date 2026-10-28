<?php

declare(strict_types=1);

namespace Keel\Container;

/**
 * Implemented by services that hold resources. Scoped services are disposed when
 * their scope closes; singletons are disposed when the worker shuts down.
 */
interface Disposable
{
    public function dispose(): void;
}
