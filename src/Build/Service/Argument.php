<?php

declare(strict_types=1);

namespace Keel\Build\Service;

/** One constructor or factory parameter, as the compiler will satisfy it. */
final readonly class Argument
{
    public const string SERVICE = 'service';
    public const string CONFIG = 'config';
    public const string ALL = 'all';
    public const string VALUE = 'value';

    /**
     * @param self::SERVICE|self::CONFIG|self::ALL|self::VALUE $kind
     * @param string $type class name for services/all, scalar type for config
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $type,
        public bool $hasDefault = false,
        public mixed $default = null,
        public bool $nullable = false,
        public ?string $configKey = null,
    ) {
    }
}
