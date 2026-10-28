<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

use Keel\Http\Exception\Unauthorized;

/**
 * Minimal, strict JSON Web Tokens (RFC 7519): HS256/384/512 and RS256/384/512.
 *
 * The algorithm is taken from the configured key, never trusted from the token header,
 * so `alg: none` and algorithm-confusion attacks do not apply.
 */
final class Jwt
{
    private const array HMAC = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];
    private const array RSA = ['RS256' => \OPENSSL_ALGO_SHA256, 'RS384' => \OPENSSL_ALGO_SHA384, 'RS512' => \OPENSSL_ALGO_SHA512];

    /**
     * @param array<string, mixed> $claims
     * @param string $key the HMAC secret or a PEM private key
     */
    public static function encode(array $claims, string $alg, string $key, ?string $kid = null): string
    {
        $header = ['alg' => $alg, 'typ' => 'JWT'] + ($kid !== null ? ['kid' => $kid] : []);
        $input = self::b64(json_encode($header, \JSON_THROW_ON_ERROR)) . '.' . self::b64(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return $input . '.' . self::b64(self::sign($input, $alg, $key));
    }

    /**
     * Splits a token and verifies its signature. Claims are not validated here.
     *
     * @param array<string, array{alg: string, key: string}> $keys kid => key; a token without kid must match a single key
     * @return array<string, mixed>
     */
    public static function verify(string $token, array $keys): array
    {
        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            throw self::invalid();
        }
        [$h, $p, $s] = $parts;
        $header = self::json($h);
        $kid = $header['kid'] ?? null;
        $key = match (true) {
            \is_string($kid) => $keys[$kid] ?? throw self::invalid(),
            \count($keys) === 1 => reset($keys),
            default => throw self::invalid(),
        };
        if (($header['alg'] ?? null) !== $key['alg']) {
            throw self::invalid();
        }
        $signature = self::unb64($s);
        $input = "$h.$p";
        $valid = isset(self::HMAC[$key['alg']])
            ? hash_equals(self::sign($input, $key['alg'], $key['key']), $signature)
            : isset(self::RSA[$key['alg']]) && openssl_verify($input, $signature, $key['key'], self::RSA[$key['alg']]) === 1;
        if (!$valid) {
            throw self::invalid();
        }

        return self::json($p);
    }

    private static function sign(string $input, string $alg, string $key): string
    {
        if (isset(self::HMAC[$alg])) {
            return hash_hmac(self::HMAC[$alg], $input, $key, true);
        }
        if (isset(self::RSA[$alg])) {
            if (!openssl_sign($input, $signature, $key, self::RSA[$alg]) || !\is_string($signature)) {
                throw new \RuntimeException('Signing the JWT failed: check the private key.');
            }

            return $signature;
        }

        throw new \InvalidArgumentException("Unsupported JWT algorithm $alg. Use HS256/384/512 or RS256/384/512.");
    }

    /** @return array<string, mixed> */
    private static function json(string $segment): array
    {
        try {
            $data = json_decode(self::unb64($segment), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid();
        }
        if (!\is_array($data) || array_is_list($data)) {
            throw self::invalid();
        }

        /** @var array<string, mixed> */
        return $data;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? throw self::invalid() : $decoded;
    }

    private static function invalid(): Unauthorized
    {
        return new Unauthorized('The token is invalid.', headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }
}
