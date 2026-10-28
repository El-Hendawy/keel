# Encryption: data at rest and end to end

Keel encrypts in two places, both with AES-256-GCM:

- **At rest:** `#[Encrypted]` entity columns and the `Encrypter` service. The database, its backups and
  anyone with read access to them see only ciphertext.
- **In transit, end to end:** request and response bodies encrypted between the client and your
  application, on top of TLS. Proxies, load balancers, API gateways and their logs see only ciphertext.

## Keys

```php
// config.php
'crypto' => [
    'keys' => [
        '2026b' => getenv('CRYPTO_KEY_2026B'),   // keel key:generate
        '2026a' => getenv('CRYPTO_KEY_2026A'),
    ],
    'current' => '2026b',                        // encrypts; the others only decrypt
],
```

Keys are 32 bytes (`base64:...` from `keel key:generate`). Without `crypto.keys`, a key derived from
`app.key` (id `app`) is used, so encryption works from the first day; configure your own keys before you
have data you cannot afford to re-encrypt.

## The Encrypter

```php
$sealed = $crypto->encrypt($taxId, context: 'customers.tax_id');   // kv1.2026b.Qk9...
$taxId  = $crypto->decrypt($sealed, context: 'customers.tax_id');
```

- The format is `kv1.<key id>.<base64url(nonce, ciphertext, tag)>`: a fresh 96-bit nonce for every value,
  and a 128-bit tag. Equal values encrypt differently.
- The key id and the **context** are authenticated: a value copied to another column, or swapped to
  another key id, fails to decrypt (`DecryptionFailed`) instead of decrypting to something wrong.
- `needsReencryption()` and `reencrypt()` support rotation.

## Encrypted columns

```php
#[Entity('wallets')]
final class Wallet
{
    public function __construct(
        public string $owner,
        #[Encrypted] public string $iban,
        #[Encrypted] public ?array $kyc = null,          // JSON, encrypted as a whole
        #[Id] public string $id = '',
    ) {}
}
```

The entity sees plaintext; the database stores ciphertext in a text column, bound to `table.column`.
Changes are detected on the plaintext, so saving an unchanged entity writes nothing.

Encrypted columns cannot be filtered, sorted, indexed or unique, because equal values encrypt differently:
`where('iban', ...)` throws and the compiler refuses `#[Encrypted]` with `unique`, `index`, `#[Id]`,
`#[TenantKey]`, `#[Version]` or timestamps. To look rows up by a secret value, store a keyed hash of it in a
second column (`hash_hmac('sha256', $iban, $keys->derive('iban-lookup'))`) and query that.

### Rotating keys

1. Add the new key to `crypto.keys` and make it `current`. New writes use it; old values still decrypt.
2. `keel orm:reencrypt 'App\Wallets\Wallet'` for each entity with encrypted columns.
3. Remove the old key.

## End-to-end message encryption

```php
// app.php
->withMessageEncryption()

// config.php
'crypto' => ['e2e' => [
    'keys' => ['2026a' => getenv('E2E_KEY_2026A')],   // keel crypto:e2e-key
    'required' => ['/payments/', '/cards/'],          // paths that refuse plaintext
]],
```

Bodies are [JWE](https://www.rfc-editor.org/rfc/rfc7516) in compact form with `alg: ECDH-ES` and
`enc: A256GCM`: an ephemeral elliptic-curve key per message (P-256 or P-384), the content key derived with
the Concat KDF, AES-256-GCM. That is the format JOSE libraries in every language produce and read; Keel's
implementation is tested against Python's `jwcrypto` in both directions.

| The client sends | Keel does |
| --- | --- |
| `Content-Type: application/jose` and a JWE body | Decrypts it before routing; the JWE's `cty` becomes the Content-Type (default JSON) |
| `Keel-E2E-Key: <base64url public JWK>` | Encrypts the response body to that key: `application/jose`, `Cache-Control: no-store` |
| neither, on a `required` path | 400 with `code: encryption_required` |
| `GET /.well-known/keel-e2e-keys` | The server's public keys (a JWK Set) |

Endpoints are unchanged: they receive the decrypted input and return their result as usual. The request
attribute `MessageEncryptionMiddleware::ENCRYPTED` says whether the body arrived encrypted. The middleware
sits outside content negotiation, so TOON bodies can be encrypted too. Streaming responses (server-sent
events, WebSockets) are not encrypted.

### In the browser

```js
import { CompactEncrypt, compactDecrypt, generateKeyPair, exportJWK, importJWK } from 'jose';

const { keys: [server] } = await (await fetch('/.well-known/keel-e2e-keys')).json();
const client = await generateKeyPair('ECDH-ES', { crv: 'P-256' });          // keep it for the session
const body = await new CompactEncrypt(new TextEncoder().encode(JSON.stringify({ amount: '10.00' })))
  .setProtectedHeader({ alg: 'ECDH-ES', enc: 'A256GCM', kid: server.kid, cty: 'application/json' })
  .encrypt(await importJWK(server, 'ECDH-ES'));
const publicJwk = await exportJWK(client.publicKey);
const response = await fetch('/payments', {
  method: 'POST',
  headers: { 'Content-Type': 'application/jose', 'Keel-E2E-Key': btoa(JSON.stringify(publicJwk)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') },
  body,
});
const { plaintext } = await compactDecrypt(await response.text(), client.privateKey);
```

### From PHP

```php
$client = MessageEncryption::client();
$request = MessageEncryption::seal($request, $serverJwk, $client);
$plain = MessageEncryption::open($http->sendRequest($request), $client);
```

### What it protects, and what it does not

It protects bodies from everything between the client and your application: TLS-terminating proxies,
gateways, request logging, a compromised CDN. It does not hide which endpoint was called, headers, or
sizes; it does not protect a compromised client or server. Server keys are the crown jewels: keep them in
a secret manager, and rotate by adding a key (both decrypt), publishing it, then removing the old one.
