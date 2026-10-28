<?php

declare(strict_types=1);

namespace Keel\Runtime;

/** The environment the application booted in. Provided by the runtime; inject it where needed. */
final readonly class Environment
{
    public function __construct(
        public string $name,
        public bool $debug,
        public string $rootDir,
    ) {
    }

    public function isProduction(): bool
    {
        return $this->name === 'prod' || $this->name === 'production';
    }

    public function isTest(): bool
    {
        return $this->name === 'test';
    }
}
