<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * JSON Web Keys (RFC 7517): public RSA and EC (P-256, P-384) keys to PEM for OpenSSL, and
 * thumbprints (RFC 7638) for DPoP and `cnf.jkt` binding.
 */
final class Jwk
{
    private const array CURVES = ['P-256' => ['1.2.840.10045.3.1.7', 32], 'P-384' => ['1.3.132.0.34', 48]];

    /**
     * @param array<mixed> $jwk
     * @return array{alg: string, key: string} the algorithm and a PEM public key
     */
    public static function toKey(array $jwk, ?string $alg = null): array
    {
        $kty = $jwk['kty'] ?? null;
        if (isset($jwk['d'])) {
            throw new \InvalidArgumentException('Refusing a JWK that contains a private key.');
        }
        if ($kty === 'RSA') {
            $n = self::b64($jwk['n'] ?? null);
            $e = self::b64($jwk['e'] ?? null);
            $spki = self::sequence(
                self::sequence(self::oid('1.2.840.113549.1.1.1') . "\x05\x00")
                . self::bitString(self::sequence(self::integer($n) . self::integer($e))),
            );
            $algorithm = $alg ?? (\is_string($jwk['alg'] ?? null) ? $jwk['alg'] : 'RS256');
        } elseif ($kty === 'EC') {
            $crv = \is_string($jwk['crv'] ?? null) ? $jwk['crv'] : '';
            [$oid, $size] = self::CURVES[$crv] ?? throw new \InvalidArgumentException("Unsupported curve $crv. Use P-256 or P-384.");
            $x = self::b64($jwk['x'] ?? null);
            $y = self::b64($jwk['y'] ?? null);
            if (\strlen($x) !== $size || \strlen($y) !== $size) {
                throw new \InvalidArgumentException('Invalid EC key coordinates.');
            }
            $spki = self::sequence(
                self::sequence(self::oid('1.2.840.10045.2.1') . self::oid($oid))
                . self::bitString("\x04" . $x . $y),
            );
            $algorithm = $alg ?? (\is_string($jwk['alg'] ?? null) ? $jwk['alg'] : ($crv === 'P-384' ? 'ES384' : 'ES256'));
        } else {
            throw new \InvalidArgumentException('Unsupported JWK key type. Use RSA or EC.');
        }

        return ['alg' => $algorithm, 'key' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"];
    }

    /** @param array<mixed> $jwk */
    public static function thumbprint(array $jwk): string
    {
        $members = match ($jwk['kty'] ?? null) {
            'EC' => ['crv' => $jwk['crv'] ?? '', 'kty' => 'EC', 'x' => $jwk['x'] ?? '', 'y' => $jwk['y'] ?? ''],
            'RSA' => ['e' => $jwk['e'] ?? '', 'kty' => 'RSA', 'n' => $jwk['n'] ?? ''],
            default => throw new \InvalidArgumentException('Unsupported JWK key type.'),
        };

        return rtrim(strtr(base64_encode(hash('sha256', json_encode($members, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), true)), '+/', '-_'), '=');
    }

    /**
     * The public JWK of an OpenSSL key (RSA or EC), e.g. for a client's DPoP key.
     *
     * @return array<string, string>
     */
    public static function fromOpenSsl(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        if (!\is_array($details)) {
            throw new \InvalidArgumentException('Unreadable key.');
        }
        $b64 = static fn (mixed $v): string => rtrim(strtr(base64_encode(\is_string($v) ? $v : ''), '+/', '-_'), '=');
        if (isset($details['ec']) && \is_array($details['ec'])) {
            $crv = match ($details['ec']['curve_name'] ?? '') {
                'prime256v1' => 'P-256',
                'secp384r1' => 'P-384',
                default => throw new \InvalidArgumentException('Unsupported curve.'),
            };
            // OpenSSL drops leading zero bytes; RFC 7518 section 6.2.1.2 wants the full curve size.
            $size = self::CURVES[$crv][1];
            $coordinate = static fn (mixed $v): string => str_pad(\is_string($v) ? $v : '', $size, "\0", STR_PAD_LEFT);

            return ['kty' => 'EC', 'crv' => $crv, 'x' => $b64($coordinate($details['ec']['x'] ?? '')), 'y' => $b64($coordinate($details['ec']['y'] ?? ''))];
        }
        if (isset($details['rsa']) && \is_array($details['rsa'])) {
            return ['kty' => 'RSA', 'n' => $b64($details['rsa']['n'] ?? ''), 'e' => $b64($details['rsa']['e'] ?? '')];
        }

        throw new \InvalidArgumentException('Unsupported key type.');
    }

    /** ECDSA signatures: JWS uses r||s, OpenSSL uses DER. */
    public static function ecdsaToDer(string $raw): string
    {
        $half = intdiv(\strlen($raw), 2);

        return self::sequence(self::integer(substr($raw, 0, $half)) . self::integer(substr($raw, $half)));
    }

    public static function ecdsaFromDer(string $der, int $size): string
    {
        $offset = 2 + (\ord($der[1] ?? "\0") & 0x80 ? (\ord($der[1]) & 0x7F) : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $length = \ord($der[$offset + 1] ?? "\0");
            $value = ltrim(substr($der, $offset + 2, $length), "\0");
            $out .= str_pad($value, $size, "\0", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }

    private static function b64(mixed $value): string
    {
        $decoded = \is_string($value) ? base64_decode(strtr($value, '-_', '+/'), true) : false;

        return $decoded === false || $decoded === '' ? throw new \InvalidArgumentException('Invalid JWK member.') : $decoded;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (\ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }

        return "\x02" . self::length(\strlen($bytes)) . $bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(\strlen($content)) . $content;
    }

    private static function bitString(string $content): string
    {
        return "\x03" . self::length(\strlen($content) + 1) . "\0" . $content;
    }

    private static function oid(string $oid): string
    {
        $parts = array_map(intval(...), explode('.', $oid));
        $body = pack('C', $parts[0] * 40 + $parts[1]);
        foreach (\array_slice($parts, 2) as $part) {
            $encoded = pack('C', $part & 0x7F);
            while (($part >>= 7) > 0) {
                $encoded = pack('C', 0x80 | ($part & 0x7F)) . $encoded;
            }
            $body .= $encoded;
        }

        return "\x06" . self::length(\strlen($body)) . $body;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return pack('C', $length);
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return pack('C', 0x80 | \strlen($bytes)) . $bytes;
    }
}
