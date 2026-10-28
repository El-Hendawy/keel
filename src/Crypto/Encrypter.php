<?php

declare(strict_types=1);

namespace Keel\Crypto;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Security\Keys;

/**
 * Authenticated encryption for data at rest: AES-256-GCM with a key ring.
 *
 *     'crypto' => [
 *         'keys' => ['2026a' => 'base64:...', '2025b' => 'base64:...'],   // keel key:generate
 *         'current' => '2026a',                                            // encrypts; the others only decrypt
 *     ],
 *
 * A ciphertext is `kv1.<key id>.<base64url(nonce || ciphertext || tag)>`. The key id and an
 * optional context (for example `cards.pan`) are authenticated, so a value cannot be moved to
 * another column or decrypted under another key without failing. Without `crypto.keys`, a key
 * derived from `app.key` (id `app`) is used.
 *
 * Rotation: add a key, make it current, then re-encrypt (`Orm::reencrypt()` for entity
 * columns); remove the old key once nothing uses it (`needsReencryption()`).
 */
#[Singleton]
final class Encrypter
{
    private const string VERSION = 'kv1';
    private const int NONCE = 12;
    private const int TAG = 16;

    /** @var array<string, string> key id => 32-byte key */
    private array $ring = [];

    private string $current;

    /** @param array<mixed> $keys */
    public function __construct(
        Keys $secrets,
        #[Config('crypto.keys')] array $keys = [],
        #[Config('crypto.current')] string $current = '',
    ) {
        foreach ($keys as $id => $key) {
            if (!\is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) !== 1) {
                throw new \InvalidArgumentException('crypto.keys: key ids must be 1-32 letters, digits, "_" or "-".');
            }
            $raw = \is_string($key) ? Keys::decode($key) : '';
            if (\strlen($raw) !== 32) {
                throw new \InvalidArgumentException("crypto.keys.$id must be 32 bytes, e.g. base64:... from `keel key:generate`.");
            }
            $this->ring[$id] = $raw;
        }
        if ($this->ring === []) {
            $this->ring['app'] = $secrets->derive('encryption');
            $current = 'app';
        }
        if ($current === '') {
            $current = (string) array_key_first($this->ring);
        }
        if (!isset($this->ring[$current])) {
            throw new \InvalidArgumentException("crypto.current is \"$current\", which is not in crypto.keys.");
        }
        $this->current = $current;
    }

    /** @param string $context binds the ciphertext to where it is stored, e.g. "cards.pan" */
    public function encrypt(string $plaintext, string $context = ''): string
    {
        $nonce = random_bytes(self::NONCE);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->ring[$this->current], \OPENSSL_RAW_DATA, $nonce, $tag, self::aad($this->current, $context), self::TAG);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error') . '.');
        }

        return self::VERSION . '.' . $this->current . '.' . self::b64($nonce . $ciphertext . $tag);
    }

    /** @throws DecryptionFailed when the value was altered, uses an unknown key or another context */
    public function decrypt(string $payload, string $context = ''): string
    {
        [$id, $raw] = $this->split($payload);
        $key = $this->ring[$id] ?? throw new DecryptionFailed("The value was encrypted with key \"$id\", which is not in crypto.keys.");
        if (\strlen($raw) < self::NONCE + self::TAG) {
            throw new DecryptionFailed('The encrypted value is truncated.');
        }
        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE, -self::TAG),
            'aes-256-gcm',
            $key,
            \OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE),
            substr($raw, -self::TAG),
            self::aad($id, $context),
        );
        if ($plaintext === false) {
            throw new DecryptionFailed('The encrypted value failed authentication: it was altered, or belongs to another context.');
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::VERSION . '.') && substr_count($value, '.') === 2;
    }

    /** True when the value was encrypted with a key other than the current one. */
    public function needsReencryption(string $payload): bool
    {
        return $this->split($payload)[0] !== $this->current;
    }

    public function reencrypt(string $payload, string $context = ''): string
    {
        return $this->needsReencryption($payload) ? $this->encrypt($this->decrypt($payload, $context), $context) : $payload;
    }

    public function currentKeyId(): string
    {
        return $this->current;
    }

    /** @return array{0: string, 1: string} key id and raw bytes */
    private function split(string $payload): array
    {
        $parts = explode('.', $payload);
        if (\count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new DecryptionFailed('Not a Keel ciphertext (expected kv1.<key id>.<data>).');
        }
        $raw = base64_decode(strtr($parts[2], '-_', '+/'), true);
        if ($raw === false) {
            throw new DecryptionFailed('The encrypted value is not valid base64url.');
        }

        return [$parts[1], $raw];
    }

    private static function aad(string $id, string $context): string
    {
        return self::VERSION . '|' . $id . '|' . $context;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
