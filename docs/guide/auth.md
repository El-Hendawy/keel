# Authentication, authorization and token binding

```php
// app.php
return AppDefinition::create(__DIR__, 'shop')
    ->scan('src')
    ->withDatabase()
    ->withAuth();
```

`->withAuth()` turns on three things:

1. **Deny by default.** Every endpoint requires an authenticated caller unless it is marked `#[Anonymous]`.
   A new endpoint is never public by accident.
2. **Bearer tokens.** Opaque Keel tokens (`keel_...`, stored hashed) and JWTs are accepted in
   `Authorization: Bearer <token>`.
3. **Token binding.** Every token is bound to a fingerprint of the client it was issued to. A stolen token
   presented by a different client is refused and revoked.

## Protecting endpoints

```php
#[Post('/orders')]
#[Authorize(scopes: ['orders:write'])]
final class CreateOrder
{
    public function __invoke(CreateOrderInput $in, Principal $caller, Orders $orders): OrderResource { /* ... */ }
}

#[Get('/status')]
#[Anonymous]
final class Status
{
    public function __invoke(?Principal $caller): StatusResource { /* ... */ }
}
```

| You write | What happens |
| --- | --- |
| nothing | The caller must be authenticated, or 401 |
| `#[Authorize(scopes: [...])]` | The caller must hold every scope, or 403 with `requiredScopes` |
| `#[Authorize(policy: OwnsOrder::class)]` | The policy's `allows()` must return true, or 403 |
| `#[Anonymous]` | No credentials needed; declare `?Principal` to receive the caller if there is one |
| a `Principal` parameter | The authenticated caller, injected |

The checks are compiled into the endpoint's dispatch method: no attribute is read per request. Mistakes are
build errors: `#[Authorize]` without `->withAuth()`, a non-nullable `Principal` on an anonymous endpoint, a
policy that is not a service.

Scopes are strings. `orders:*` grants every `orders:` scope; `*` grants everything.

A policy is a service, so it can use repositories:

```php
#[Scoped]
final readonly class OwnsOrder implements Policy
{
    public function __construct(private Orders $orders) {}

    public function allows(Principal $principal, ServerRequestInterface $request, array $params): bool
    {
        return $this->orders->find($params['id'])?->ownerId === $principal->id;
    }
}
```

The health and metrics endpoints are exempt (`auth.exempt`): they have their own protection.

## Tokens

```php
// A login endpoint: bind the token to the client that logged in.
$issued = $tokens->issue($user->id, $user->tenantId, ['orders:*'], client: $request);
return new LoginResult($issued->token, $issued->expiresAt);

$tokens->revoke($principal->tokenId);   // sign out
$tokens->revokeAllFor($user->id);       // password change
```

```bash
keel token:issue --principal=billing-sync --tenant=t_123 --scopes=orders:read --name="Billing sync"
keel token:revoke <token-id>
```

- Only SHA-256 hashes are stored (`keel_tokens`). The plain token is shown once.
- The `keel_` prefix lets secret scanners (GitHub, GitGuardian) recognise leaked tokens.
- `auth.tokens.ttl_seconds` (default 1 day) applies unless you pass a TTL; `0` never expires (API keys).
- Without `->withDatabase()` tokens live in memory, which the production checks refuse.

## JWTs

Keel verifies JWTs from your identity provider:

```php
// config.php
'auth' => ['jwt' => [
    'keys' => ['idp-2026' => ['alg' => 'RS256', 'key' => file_get_contents('/run/secrets/idp.pem')]],
    'issuer' => 'https://login.example.com/',
    'audience' => 'api',
    'tenant_claim' => 'org_id',
]],
```

- Algorithms: HS256/384/512 and RS256/384/512. The algorithm comes from the configured key, never from the
  token, so `alg: none` and algorithm-confusion attacks do not apply.
- `exp` is required; `nbf`, `iss` and `aud` are checked when configured (`auth.jwt.leeway_seconds`, default 30).
- Scopes come from `scope` (space separated) or `scp` (array).
- `JwtTokens::issue($principal, $fingerprint)` issues short-lived JWTs of your own (`auth.jwt.ttl_seconds`,
  default 15 minutes), bound with a `cnf.kfp` claim. Without configured keys they are signed with a key
  derived from `app.key`. `audience:` overrides `auth.jwt.audience` (tokens for another service), and a
  principal of kind `service` or `api_key` keeps its kind (claim `knd`); see [microservices](microservices.md).

JWTs cannot be revoked before they expire; keep them short-lived and use opaque tokens for long sessions and
API keys.

## OpenID Connect providers

Accept tokens from Auth0, Okta, Entra ID, Keycloak, Google or any OpenID Connect provider:

```php
'auth' => ['oidc' => [
    'issuer' => 'https://login.example.com/',   // discovery: <issuer>/.well-known/openid-configuration
    'audience' => 'api',
]],
'auth' => ['jwt' => ['tenant_claim' => 'org_id']],
```

- Signing keys come from the provider's JWKS (RSA and EC P-256/P-384: RS256/384/512, ES256/384) and are
  cached for `auth.oidc.jwks_ttl_seconds` (1 hour) in the application cache (shared through Redis with
  `->withRedisStores()`).
- **Key rotation needs no deploy:** a token signed with a key id the cache does not know triggers one
  refetch, at most every `auth.oidc.refresh_cooldown_seconds` (60), so forged key ids cannot flood the
  provider.
- `iss` must be the issuer and `aud` must include `auth.oidc.audience`; `exp`/`nbf` are checked with leeway.
- Keel-issued JWTs and statically configured keys keep working alongside.

### Signing users in

For a backend that serves its own web app, `OidcClient` runs the authorization code flow with PKCE:

```php
'auth' => ['oidc' => ['issuer' => '...', 'client_id' => 'web', 'client_secret' => getenv('OIDC_SECRET'), 'redirect_uri' => 'https://app.example.com/callback']],
```

```php
$login = $oidc->start();                                // redirect the user to $login->url; keep $login server-side
$result = $oidc->finish($code, $state, $login);         // on the callback: verified ID token claims
$token = $tokens->issue($result['claims']['sub'], $tenantId, $scopes, client: $request);
```

The ID token's signature, issuer, audience, expiry and nonce are verified; a state mismatch is refused.

## DPoP: proof of possession

Fingerprint binding raises the cost of using a stolen token; DPoP (RFC 9449) makes it useless. The client
keeps a private key (in browsers, a non-extractable WebCrypto key), signs a short proof JWT for every request,
and the token is bound to the key's thumbprint:

```http
GET /orders HTTP/1.1
Authorization: DPoP keel_3wVZ...
DPoP: eyJ0eXAiOiJkcG9wK2p3dCIsImFsZyI6IkVTMjU2IiwiandrIjp7...
```

```php
// A login endpoint: verify the client's proof, then bind the token to its key.
$jkt = $dpop->verify($request);
$issued = $tokens->issue($user->id, $user->tenantId, $scopes, client: $request, dpopKey: $jkt);
// Or a JWT: $jwt->issue($principal, dpopKey: $jkt)  -> cnf.jkt
```

- A DPoP-bound token is refused as a plain `Bearer` token, refused with a proof from another key, and each
  proof is checked: `typ`, an asymmetric algorithm (ES256/384, RS256/384/512), the signature, `htm`, `htu`,
  `iat` within `auth.dpop.max_age_seconds` (60), `ath` (the token's hash), and a `jti` never seen before
  (replays are refused through the LockStore, so across nodes with Redis).
- An API key issued with `keel token:issue` binds to the DPoP key of the first client that uses it, like
  fingerprints.
- Behind a proxy that rewrites the host, set `auth.dpop.base_url` to the public origin so `htu` matches.
- `DpopProof::create($key, $method, $url, $token)` creates proofs for PHP clients and tests.
- JWTs from a provider that issues `cnf.jkt` (DPoP-bound access tokens) are checked the same way.

DPoP and fingerprint binding work together: a DPoP-bound token is also fingerprint-bound unless you turned
that off.

## Token binding

A bearer token works for whoever holds it. Binding narrows that to the client it was issued to.

When a token is issued during a request (`client: $request`), Keel records a fingerprint of that client.
When a token is issued without one (CLI, admin screen), the first client that uses it becomes the one it is
bound to. Every later request must match.

| Component | Source | Default |
| --- | --- | --- |
| `user_agent` | `User-Agent`, lower-cased, **with version numbers removed** so browser updates do not break sessions | on |
| `accept_language` | the primary language tag of `Accept-Language` | on |
| `tls` | a TLS client fingerprint (JA4) set by your TLS-terminating proxy in `auth.fingerprint.tls_header` (default `X-JA4-Fingerprint`). Only read when the request comes from a proxy you trusted with `trustProxy()`; a client cannot set it. | on |
| `ip_prefix` | the client's /24 (IPv4) or /48 (IPv6) network | off: mobile clients change networks |

Choose components with `auth.fingerprint.components`. A component that was absent when the token was bound
is ignored; one that was present must match.

### When a token does not match

| `auth.fingerprint.mode` | Effect |
| --- | --- |
| `enforce` (default) | 401 with `code: token_binding_mismatch`, and the token is revoked (`auth.fingerprint.revoke_on_mismatch`, default true). The legitimate holder signs in again; the thief gets no second try. |
| `report` | The request is allowed. Use it to measure false positives before enforcing. |

Either way Keel logs a warning, increments `auth_fingerprint_mismatches_total{mode}`, and dispatches a
`FingerprintMismatch` event. Listen for it to alert the user or your security team:

```php
#[Listener(async: true)]
final class AlertOnStolenToken
{
    public function __invoke(FingerprintMismatch $event): void { /* email the user, page security */ }
}
```

The response never says which trait differed, so it does not tell an attacker what to forge.

### Privacy

No raw value is stored. Each component is an HMAC-SHA256 digest under a key derived from `app.key`, truncated
to 128 bits: it cannot be reversed, and the same browser produces unrelated digests in two applications.
Binding uses traits the client already sends with every request and keeps them only for as long as the token
lives, for the purpose of securing that session. Mention it in your privacy notice as a session security
measure.

### What binding does and does not do

Binding stops the common case: a token copied out of a log, a browser extension, a leaked `.env` or a
compromised machine and replayed from a script or another device. It is not proof of possession: an attacker
who knows the victim's user agent and language, and is behind the same kind of TLS stack, can reproduce the
fingerprint. Combine it with short token lifetimes, revocation on password change, and TLS everywhere.

Turning binding off is unsafe by name, in `app.php`:

```php
->withAuth()
->unsafeWithoutTokenBinding()
```

## Custom authentication

Bind your own `Authenticator` to support another scheme (mutual TLS, signed requests, a session cookie):

```php
->bind(Authenticator::class, MutualTlsAuthenticator::class)
```

Return a `Principal`, `null` when the request carries no credentials, or throw `Unauthorized`.

## Configuration reference

| Key | Default | Meaning |
| --- | --- | --- |
| `app.key` | (required in production) | 32+ random bytes; `keel key:generate` |
| `auth.exempt` | `['/health/', '/metrics']` | Path prefixes that skip authentication |
| `auth.tokens.ttl_seconds` | `86400` | Default token lifetime |
| `auth.fingerprint.mode` | `enforce` | `enforce` or `report` |
| `auth.fingerprint.components` | `user_agent, accept_language, tls` | See above |
| `auth.fingerprint.tls_header` | `X-JA4-Fingerprint` | Header your proxy sets |
| `auth.fingerprint.revoke_on_mismatch` | `true` | Revoke a token presented by another client |
| `auth.jwt.*` | | See [JWTs](#jwts) |
