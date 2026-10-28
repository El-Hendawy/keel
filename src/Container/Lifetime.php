<?php

declare(strict_types=1);

namespace Keel\Container;

/**
 * How long a service instance lives.
 *
 * Singleton: one per worker process, shared by every request.
 * Scoped:    one per request, job or message; disposed when the scope closes.
 * Transient: a new instance every time it is injected.
 */
enum Lifetime: string
{
    case Singleton = 'singleton';
    case Scoped = 'scoped';
    case Transient = 'transient';

    /** Compact integer used by the compiled container. */
    public function code(): int
    {
        return match ($this) {
            self::Singleton => 0,
            self::Scoped => 1,
            self::Transient => 2,
        };
    }
}
