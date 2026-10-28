<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Auth\Fingerprint\ClientFingerprint;
use Keel\Auth\Fingerprint\Fingerprint;
use Keel\Auth\Fingerprint\FingerprintMismatch;
use Keel\Auth\Jwt\JwtTokens;
use Keel\Auth\Token\StoredToken;
use Keel\Auth\Token\Tokens;
use Keel\Auth\Token\TokenStore;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Container\Scope;
use Keel\Http\Exception\Unauthorized;
use Keel\Http\RequestContext;
use Keel\Observability\Metrics\Metrics;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * `Authorization: Bearer <token>` with Keel tokens (`keel_...`) or JWTs.
 *
 * Every bound token is checked against the presenting client's fingerprint. In `enforce`
 * mode (the default) a mismatch is rejected and, unless `auth.fingerprint.revoke_on_mismatch`
 * is false, the token is revoked, since the legitimate holder can sign in again but a thief
 * should not get a second try. In `report` mode the request is allowed and only reported.
 * Either way a FingerprintMismatch event is dispatched, logged and counted.
 */
#[Scoped]
final readonly class BearerAuthenticator implements Authenticator
{
    public function __construct(
        private TokenStore $store,
        private JwtTokens $jwt,
        private ClientFingerprint $fingerprints,
        private ClockInterface $clock,
        private RequestContext $context,
        private Scope $scope,
        private Metrics $metrics,
        private LoggerInterface $logger,
        #[Config('auth.binding')] private bool $binding = true,
        #[Config('auth.fingerprint.mode')] private string $mode = 'enforce',
        #[Config('auth.fingerprint.revoke_on_mismatch')] private bool $revokeOnMismatch = true,
    ) {
        if (!\in_array($mode, ['enforce', 'report'], true)) {
            throw new \InvalidArgumentException("auth.fingerprint.mode must be \"enforce\" or \"report\", got \"$mode\". To turn binding off, call ->unsafeWithoutTokenBinding() in app.php.");
        }
    }

    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $match) !== 1) {
            throw new Unauthorized('Send credentials as "Authorization: Bearer <token>".', headers: ['WWW-Authenticate' => 'Bearer realm="api"']);
        }
        $token = $match[1];

        return str_starts_with($token, Tokens::PREFIX) ? $this->opaque($token, $request) : $this->jwt($token, $request);
    }

    private function opaque(string $token, ServerRequestInterface $request): Principal
    {
        $now = $this->clock->now()->getTimestamp();
        $stored = $this->store->findByHash(Tokens::hash($token));
        if ($stored === null || !$stored->isActive($now)) {
            throw new Unauthorized('The token is invalid, expired or revoked.', headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        if ($this->binding) {
            $current = $this->fingerprints->of($request);
            if ($stored->bindOnFirstUse) {
                if (!$this->store->bindOnFirstUse($stored->id, $current)) {
                    $stored = $this->store->findByHash($stored->hash) ?? $stored; // bound concurrently
                    $this->check($stored->fingerprint, $current, $stored);
                }
            } else {
                $this->check($stored->fingerprint, $current, $stored);
            }
        }

        return $stored->principal();
    }

    private function jwt(string $token, ServerRequestInterface $request): Principal
    {
        ['principal' => $principal, 'binding' => $binding] = $this->jwt->verify($token);
        if ($this->binding && $binding !== null && !hash_equals($binding, $this->fingerprints->of($request)->digest())) {
            $this->mismatch($principal->tokenId ?? 'jwt', $principal, ['jwt'], false);
        }

        return $principal;
    }

    private function check(Fingerprint $bound, Fingerprint $current, StoredToken $token): void
    {
        $mismatched = $bound->mismatches($current);
        if ($mismatched !== []) {
            $this->mismatch($token->id, $token->principal(), $mismatched, true);
        }
    }

    /** @param list<string> $components */
    private function mismatch(string $tokenId, Principal $principal, array $components, bool $revocable): void
    {
        $enforced = $this->mode === 'enforce';
        $revoked = $enforced && $revocable && $this->revokeOnMismatch;
        if ($revoked) {
            $this->store->revoke($tokenId, $this->clock->now()->getTimestamp());
        }
        $this->metrics->increment('auth_fingerprint_mismatches_total', ['mode' => $this->mode], help: 'Bound tokens presented by a different client.');
        $this->logger->warning('Token {token_id} of {principal_id} was presented by a different client ({components}).', [
            'token_id' => $tokenId,
            'principal_id' => $principal->id,
            'tenant_id' => $principal->tenantId,
            'components' => implode(', ', $components),
            'client_ip' => $this->context->clientIp,
            'enforced' => $enforced,
            'revoked' => $revoked,
        ]);
        // Resolved only here: listeners (and the queue behind async ones) cost nothing on the happy path.
        $this->scope->get(EventDispatcherInterface::class)->dispatch(new FingerprintMismatch($tokenId, $principal->id, $principal->tenantId, $components, $this->context->clientIp, $enforced, $revoked));

        if ($enforced) {
            // Deliberately vague: do not tell an attacker which trait to forge.
            throw new Unauthorized('This token cannot be used from this client.', ['code' => 'token_binding_mismatch'], headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
    }
}
