<?php

declare(strict_types=1);

namespace Keel\Auth\Dpop;

use Keel\Auth\Jwt\Jwk;
use Keel\Auth\Jwt\Jwt;

/**
 * Creates DPoP proofs: for PHP clients (calling another DPoP-protected API) and tests.
 * Browsers use WebCrypto with a non-extractable key instead.
 */
final class DpopProof
{
    public static function create(\OpenSSLAsymmetricKey $privateKey, string $method, string $url, ?string $accessToken = null, ?int $now = null): string
    {
        $jwk = Jwk::fromOpenSsl($privateKey);
        if (!openssl_pkey_export($privateKey, $pem) || !\is_string($pem)) {
            throw new \RuntimeException('Cannot export the DPoP key.');
        }
        $claims = ['jti' => bin2hex(random_bytes(16)), 'htm' => $method, 'htu' => $url, 'iat' => $now ?? time()];
        if ($accessToken !== null) {
            $claims['ath'] = rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
        }

        return Jwt::encodeWithHeader(['typ' => 'dpop+jwt', 'jwk' => $jwk], $claims, $jwk['kty'] === 'EC' ? ($jwk['crv'] === 'P-384' ? 'ES384' : 'ES256') : 'RS256', $pem);
    }
}
