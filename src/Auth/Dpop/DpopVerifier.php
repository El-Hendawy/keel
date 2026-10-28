<?php

declare(strict_types=1);

namespace Keel\Auth\Dpop;

use Keel\Auth\Jwt\Jwk;
use Keel\Auth\Jwt\Jwt;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\Unauthorized;
use Keel\Locks\LockStore;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DPoP (RFC 9449): proof that the caller holds the private key a token is bound to.
 *
 * The client signs a short JWT for every request (`DPoP:` header) with a key it never
 * shares; the token carries the key's thumbprint (`cnf.jkt`). A stolen token is useless
 * without the key: unlike fingerprint binding, this is cryptographic proof.
 *
 * Checked: `typ: dpop+jwt`, an asymmetric algorithm, a public `jwk` in the header, the
 * signature, `htm` and `htu` against this request, `iat` within `auth.dpop.max_age_seconds`,
 * `ath` (the token's hash) when a token is presented, and `jti` never seen before (replays
 * are refused through the LockStore, shared across nodes in production).
 */
#[Singleton]
final readonly class DpopVerifier
{
    private const array ALGORITHMS = ['ES256', 'ES384', 'RS256', 'RS384', 'RS512'];

    public function __construct(
        private ClockInterface $clock,
        private LockStore $replays,
        #[Config('auth.dpop.max_age_seconds')] private int $maxAge = 60,
        #[Config('auth.dpop.base_url')] private string $baseUrl = '',
    ) {
    }

    /**
     * @return string the JWK thumbprint (jkt) of the key that signed the proof
     * @throws Unauthorized with `WWW-Authenticate: DPoP error="invalid_dpop_proof"`
     */
    public function verify(ServerRequestInterface $request, ?string $accessToken = null): string
    {
        $proofs = $request->getHeader('DPoP');
        if (\count($proofs) !== 1) {
            throw self::invalid('Send exactly one DPoP proof header.');
        }
        try {
            [$header, $claims, $input, $signature] = Jwt::parse($proofs[0]);
        } catch (Unauthorized) {
            throw self::invalid('The DPoP proof is not a JWT.');
        }
        $alg = $header['alg'] ?? null;
        $jwk = $header['jwk'] ?? null;
        if (($header['typ'] ?? null) !== 'dpop+jwt' || !\is_string($alg) || !\in_array($alg, self::ALGORITHMS, true) || !\is_array($jwk)) {
            throw self::invalid('The DPoP proof must have typ dpop+jwt, an asymmetric alg and a public jwk.');
        }
        try {
            $key = Jwk::toKey($jwk, $alg);
            $thumbprint = Jwk::thumbprint($jwk);
        } catch (\InvalidArgumentException $e) {
            throw self::invalid('The DPoP key is not usable: ' . $e->getMessage());
        }
        if (!Jwt::check($input, $signature, $alg, $key['key'])) {
            throw self::invalid('The DPoP proof signature is invalid.');
        }
        $now = $this->clock->now()->getTimestamp();
        $iat = $claims['iat'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (!\is_int($iat) || abs($now - $iat) > $this->maxAge || !\is_string($jti) || \strlen($jti) < 8) {
            throw self::invalid('The DPoP proof is expired, from the future, or has no jti.');
        }
        if (($claims['htm'] ?? null) !== $request->getMethod() || ($claims['htu'] ?? null) !== $this->url($request)) {
            throw self::invalid('The DPoP proof was made for another request (htm/htu).');
        }
        if ($accessToken !== null) {
            $ath = rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
            if (($claims['ath'] ?? null) !== $ath) {
                throw self::invalid('The DPoP proof does not cover this access token (ath).');
            }
        }
        if ($this->replays->acquire('dpop:' . $thumbprint . ':' . $jti, 2 * $this->maxAge) === null) {
            throw self::invalid('This DPoP proof was already used.');
        }

        return $thumbprint;
    }

    /** The htu a proof must carry: scheme, host and path, without query or fragment. */
    public function url(ServerRequestInterface $request): string
    {
        if ($this->baseUrl !== '') {
            return rtrim($this->baseUrl, '/') . $request->getUri()->getPath();
        }
        $uri = $request->getUri();

        return $uri->getScheme() . '://' . $uri->getAuthority() . $uri->getPath();
    }

    private static function invalid(string $detail): Unauthorized
    {
        return new Unauthorized($detail, ['code' => 'invalid_dpop_proof'], headers: ['WWW-Authenticate' => 'DPoP error="invalid_dpop_proof", algs="ES256 ES384 RS256"']);
    }
}
