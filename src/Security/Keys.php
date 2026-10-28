<?php

declare(strict_types=1);

namespace Keel\Security;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;

/**
 * The application secret (`app.key`) and the keys derived from it.
 *
 * Every feature that needs a key (fingerprint hashing, cursor signing, ...) derives its
 * own with HKDF and a purpose label, so one leaked derived key exposes nothing else and
 * rotating `app.key` rotates them all. Without `app.key` a fixed development key is used,
 * which the production checks refuse.
 */
#[Singleton]
final class Keys
{
    public const string DEVELOPMENT_KEY = 'keel-development-key-never-use-in-production';

    /** @var array<string, string> */
    private array $derived = [];

    public function __construct(#[Config('app.key')] private readonly string $key = '')
    {
    }

    /** A 32-byte key for one purpose, e.g. derive('fingerprint'). */
    public function derive(string $purpose): string
    {
        return $this->derived[$purpose] ??= hash_hkdf('sha256', $this->material(), 32, 'keel:' . $purpose);
    }

    public function isDevelopmentKey(): bool
    {
        return $this->key === '';
    }

    /** Keys may be given raw or as `base64:...`. */
    public static function decode(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? $key : $decoded;
        }

        return $key;
    }

    private function material(): string
    {
        return $this->key === '' ? self::DEVELOPMENT_KEY : self::decode($this->key);
    }
}
