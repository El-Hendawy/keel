<?php

declare(strict_types=1);

namespace Keel\Build\Service;

use Keel\Container\Lifetime;

final class ServiceDefinition
{
    /**
     * @param string $id the type other services ask for
     * @param class-string|null $class concrete class to instantiate (null for factories and synthetic services)
     * @param array{0: string, 1: string}|null $factory [owner service id, method]
     * @param list<Argument> $arguments
     * @param string $origin human-readable description for error messages
     */
    public function __construct(
        public readonly string $id,
        public readonly Lifetime $lifetime,
        public readonly ?string $class,
        public readonly array $arguments = [],
        public readonly ?array $factory = null,
        public readonly bool $synthetic = false,
        public readonly string $origin = '',
    ) {
    }

    public static function synthetic(string $id, Lifetime $lifetime): self
    {
        return new self($id, $lifetime, null, synthetic: true, origin: 'provided by the runtime');
    }

    /** The class whose instances this service produces, used for #[All] matching. */
    public function producedType(): string
    {
        return $this->class ?? $this->id;
    }
}
