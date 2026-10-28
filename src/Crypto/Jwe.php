<?php

declare(strict_types=1);

namespace Keel\Crypto;

use Keel\Auth\Jwt\Jwk;

/**
 * JSON Web Encryption (RFC 7516), compact form, with ECDH-ES key agreement and A256GCM
 * (RFC 7518): what JOSE libraries in every language (panva/jose, jwcrypto, Nimbus) produce
 * and read for `{"alg": "ECDH-ES", "enc": "A256GCM"}`.
 *
 * A fresh ephemeral key per message gives forward secrecy for the sender; the content key is
 * derived with the Concat KDF and never transmitted. Recipient keys are EC P-256 or P-384.
 */
final class Jwe
{
    public const string MEDIA_TYPE = 'application/jose';

    private const array CURVES = ['P-256' => 'prime256v1', 'P-384' => 'secp384r1'];

    /**
     * @param array<mixed> $recipient the recipient's public JWK (its `kid` is copied into the header)
     * @param array<string, mixed> $header extra protected header members, e.g. ['cty' => 'application/json']
     */
    public static function encrypt(string $plaintext, array $recipient, array $header = []): string
    {
        $crv = \is_string($recipient['crv'] ?? null) ? $recipient['crv'] : '';
        $curve = self::CURVES[$crv] ?? throw new \InvalidArgumentException('JWE recipients must be EC P-256 or P-384 keys.');
        $peer = self::publicKey($recipient);
        $ephemeral = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        if ($ephemeral === false) {
            throw new \RuntimeException('Could not create an ephemeral EC key: ' . (openssl_error_string() ?: 'unknown OpenSSL error') . '.');
        }
        $protected = ['alg' => 'ECDH-ES', 'enc' => 'A256GCM', 'epk' => Jwk::fromOpenSsl($ephemeral)]
            + (\is_string($recipient['kid'] ?? null) ? ['kid' => $recipient['kid']] : [])
            + $header;
        $cek = self::agree($peer, $ephemeral, $protected);
        $encodedHeader = self::b64(json_encode($protected, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $cek, \OPENSSL_RAW_DATA, $iv, $tag, $encodedHeader, 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed.');
        }

        return $encodedHeader . '..' . self::b64($iv) . '.' . self::b64($ciphertext) . '.' . self::b64($tag);
    }

    /**
     * @param array<string, \OpenSSLAsymmetricKey> $keys private keys by kid; without a kid in the
     *        header every key is tried
     * @return array{0: array<string, mixed>, 1: string} the protected header and the plaintext
     * @throws DecryptionFailed
     */
    public static function decrypt(string $jwe, array $keys): array
    {
        $parts = explode('.', $jwe);
        if (\count($parts) !== 5) {
            throw new DecryptionFailed('Not a compact JWE (expected five base64url parts).');
        }
        [$encodedHeader, $encryptedKey, $iv, $ciphertext, $tag] = $parts;
        if ($encryptedKey !== '') {
            throw new DecryptionFailed('Only direct key agreement (ECDH-ES) is accepted: the encrypted key must be empty.');
        }
        try {
            $header = json_decode(self::unb64($encodedHeader), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DecryptionFailed('The JWE header is not JSON.');
        }
        if (!\is_array($header) || ($header['alg'] ?? null) !== 'ECDH-ES' || ($header['enc'] ?? null) !== 'A256GCM' || isset($header['zip'])) {
            throw new DecryptionFailed('Unsupported JWE: use alg ECDH-ES, enc A256GCM and no compression.');
        }
        /** @var array<string, mixed> $header */
        $epk = $header['epk'] ?? null;
        if (!\is_array($epk) || ($epk['kty'] ?? null) !== 'EC') {
            throw new DecryptionFailed('The JWE header needs an EC ephemeral public key (epk).');
        }
        try {
            $peer = self::publicKey($epk);
        } catch (\InvalidArgumentException $e) {
            throw new DecryptionFailed('Invalid ephemeral key: ' . $e->getMessage(), previous: $e);
        }
        $kid = $header['kid'] ?? null;
        $candidates = \is_string($kid) ? (isset($keys[$kid]) ? [$keys[$kid]] : []) : array_values($keys);
        if ($candidates === []) {
            throw new DecryptionFailed(\is_string($kid) ? "Unknown key id \"$kid\"." : 'No decryption key is configured.');
        }
        $ivBytes = self::unb64($iv);
        $tagBytes = self::unb64($tag);
        if (\strlen($ivBytes) !== 12 || \strlen($tagBytes) !== 16) {
            throw new DecryptionFailed('Invalid JWE initialization vector or tag length.');
        }
        foreach ($candidates as $key) {
            try {
                $cek = self::agree($peer, $key, $header);
            } catch (\RuntimeException) {
                continue; // a key on another curve
            }
            $plaintext = openssl_decrypt(self::unb64($ciphertext), 'aes-256-gcm', $cek, \OPENSSL_RAW_DATA, $ivBytes, $tagBytes, $encodedHeader);
            if ($plaintext !== false) {
                return [$header, $plaintext];
            }
        }

        throw new DecryptionFailed('The JWE failed authentication: it was altered, or encrypted to another key.');
    }

    /**
     * @param array<mixed> $jwk
     * @throws \InvalidArgumentException for anything but a valid public EC P-256/P-384 point
     */
    public static function publicKey(array $jwk): \OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'EC' || !isset(self::CURVES[\is_string($jwk['crv'] ?? null) ? $jwk['crv'] : ''])) {
            throw new \InvalidArgumentException('Expected a public EC P-256 or P-384 JWK.');
        }
        // OpenSSL refuses points that are not on the curve, which stops invalid-curve attacks.
        $key = openssl_pkey_get_public(Jwk::toKey($jwk, 'ECDH-ES')['key']);
        if ($key === false) {
            throw new \InvalidArgumentException('The EC point is not on the curve.');
        }

        return $key;
    }

    /**
     * ECDH, then the Concat KDF of RFC 7518 section 4.6.2 for a 256-bit A256GCM key.
     *
     * @param array<string, mixed> $header
     */
    private static function agree(\OpenSSLAsymmetricKey $public, \OpenSSLAsymmetricKey $private, array $header): string
    {
        $z = openssl_pkey_derive($public, $private);
        if ($z === false) {
            throw new \RuntimeException('ECDH key agreement failed.');
        }
        $apu = \is_string($header['apu'] ?? null) ? self::unb64($header['apu']) : '';
        $apv = \is_string($header['apv'] ?? null) ? self::unb64($header['apv']) : '';
        $otherInfo = self::prefixed('A256GCM') . self::prefixed($apu) . self::prefixed($apv) . pack('N', 256);

        return hash('sha256', pack('N', 1) . $z . $otherInfo, true);
    }

    private static function prefixed(string $data): string
    {
        return pack('N', \strlen($data)) . $data;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false) {
            throw new DecryptionFailed('Invalid base64url in the JWE.');
        }

        return $bytes;
    }
}
