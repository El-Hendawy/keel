<?php

declare(strict_types=1);

namespace Keel\Crypto;

use Keel\Auth\Jwt\Jwk;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;

/**
 * The server's key pairs for end-to-end message encryption.
 *
 *     'crypto' => ['e2e' => ['keys' => ['2026a' => getenv('E2E_KEY_2026A')]]],   // PEM private keys
 *
 * Clients encrypt to a public key from `publicJwks()` (served at `crypto.e2e.jwks_path`). Every
 * configured key decrypts, so a new key can be published before the old one is retired; the
 * first key is the one clients should use. `keel crypto:e2e-key` creates one.
 */
#[Singleton]
final class MessageKeys
{
    /** @var array<string, \OpenSSLAsymmetricKey>|null */
    private ?array $keys = null;

    /** @param array<mixed> $configured */
    public function __construct(#[Config('crypto.e2e.keys')] private readonly array $configured = [])
    {
    }

    public function enabled(): bool
    {
        return $this->configured !== [];
    }

    /** @return array<string, \OpenSSLAsymmetricKey> */
    public function privateKeys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $keys = [];
        foreach ($this->configured as $kid => $pem) {
            $key = \is_string($kid) && \is_string($pem) ? openssl_pkey_get_private($pem) : false;
            if ($key === false) {
                throw new \InvalidArgumentException("crypto.e2e.keys.$kid must be a PEM private key: create one with `keel crypto:e2e-key`.");
            }
            $details = openssl_pkey_get_details($key);
            $ec = \is_array($details) && \is_array($details['ec'] ?? null) ? $details['ec'] : [];
            if (!\in_array($ec['curve_name'] ?? null, ['prime256v1', 'secp384r1'], true)) {
                throw new \InvalidArgumentException("crypto.e2e.keys.$kid must be an EC P-256 or P-384 key.");
            }
            $keys[$kid] = $key;
        }

        return $this->keys = $keys;
    }

    /** @return array{keys: list<array<string, string>>} the public keys as a JWK Set */
    public function publicJwks(): array
    {
        $set = [];
        foreach ($this->privateKeys() as $kid => $key) {
            $set[] = Jwk::fromOpenSsl($key) + ['kid' => $kid, 'use' => 'enc', 'alg' => 'ECDH-ES'];
        }

        return ['keys' => $set];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} header and plaintext
     * @throws DecryptionFailed
     */
    public function decrypt(string $jwe): array
    {
        return Jwe::decrypt($jwe, $this->privateKeys());
    }

    /** @return array{private: string, public: array<string, string>} a new P-256 key pair */
    public static function generate(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem) || !\is_string($pem)) {
            throw new \RuntimeException('Could not generate an EC key: ' . (openssl_error_string() ?: 'unknown OpenSSL error') . '.');
        }

        return ['private' => $pem, 'public' => Jwk::fromOpenSsl($key) + ['kid' => $kid, 'use' => 'enc', 'alg' => 'ECDH-ES']];
    }
}
