<?php

declare(strict_types=1);

namespace Keel\Support;

use Keel\Container\Attribute\Singleton;
use Psr\Clock\ClockInterface;

/** RFC 9562 UUIDv7: time-ordered, so primary keys stay index-friendly. */
#[Singleton]
final readonly class UuidV7Generator implements IdGenerator
{
    public function __construct(private ClockInterface $clock, private Random $random)
    {
    }

    public function generate(): string
    {
        $ms = (int) $this->clock->now()->format('Uv');
        $bytes = pack('J', $ms << 16);
        $bytes = substr($bytes, 0, 6) . $this->random->bytes(10);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
