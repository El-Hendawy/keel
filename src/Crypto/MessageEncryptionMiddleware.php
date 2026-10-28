<?php

declare(strict_types=1);

namespace Keel\Crypto;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Keel\Http\Exception\BadRequest;
use Keel\Http\Exception\PayloadTooLarge;
use Keel\Http\Responses;
use Keel\Http\Streaming\StreamingBody;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * End-to-end message encryption on top of TLS: bodies stay encrypted through proxies, load
 * balancers, API gateways and their logs, and only this application can read them.
 *
 * - A request with `Content-Type: application/jose` is a compact JWE (ECDH-ES + A256GCM) to one
 *   of the server's keys; it is decrypted before routing, and its `cty` header becomes the
 *   Content-Type (default application/json).
 * - A request with `Keel-E2E-Key: <base64url public JWK>` gets its response body encrypted to
 *   that key (`Content-Type: application/jose`, the original type in `cty`).
 * - Paths under `crypto.e2e.required` refuse plaintext bodies and responses without a client key.
 * - `GET crypto.e2e.jwks_path` (default /.well-known/keel-e2e-keys) serves the public keys.
 *
 * Streaming responses (server-sent events, WebSockets) are not encrypted.
 */
#[Singleton]
final readonly class MessageEncryptionMiddleware implements MiddlewareInterface
{
    public const string KEY_HEADER = 'Keel-E2E-Key';

    /** Request attribute: true when the request body arrived encrypted. */
    public const string ENCRYPTED = 'keel.e2e';

    /** @param list<string> $required */
    public function __construct(
        private MessageKeys $keys,
        #[Config('crypto.e2e.required')] private array $required = [],
        #[Config('crypto.e2e.jwks_path')] private string $jwksPath = '/.well-known/keel-e2e-keys',
        #[Config('http.max_body_bytes')] private int $maxBytes = 1_048_576,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === $this->jwksPath && \in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return Responses::json($this->keys->publicJwks())->withHeader('Cache-Control', 'public, max-age=300');
        }
        $required = $this->isRequired($path);
        $clientKey = $this->clientKey($request);

        $encrypted = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) === Jwe::MEDIA_TYPE;
        if ($encrypted) {
            $request = $this->decryptRequest($request);
        } elseif ($required && $this->hasBody($request)) {
            throw new BadRequest('This endpoint only accepts encrypted bodies: send a JWE (ECDH-ES, A256GCM) as application/jose.', ['code' => 'encryption_required']);
        }
        if ($required && $clientKey === null) {
            throw new BadRequest('This endpoint only sends encrypted responses: send your public key in the ' . self::KEY_HEADER . ' header.', ['code' => 'encryption_required']);
        }

        $response = $handler->handle($request->withAttribute(self::ENCRYPTED, $encrypted));
        if ($clientKey === null) {
            return $response;
        }
        $body = $response->getBody();
        if ($body instanceof StreamingBody || $response->getStatusCode() === 204 || $response->getStatusCode() === 304) {
            return $response;
        }
        $contentType = $response->getHeaderLine('Content-Type');
        $jwe = Jwe::encrypt((string) $body, $clientKey, $contentType === '' ? [] : ['cty' => $contentType]);

        return $response
            ->withBody(Stream::create($jwe))
            ->withHeader('Content-Type', Jwe::MEDIA_TYPE)
            ->withHeader('Content-Length', (string) \strlen($jwe))
            ->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Vary', self::KEY_HEADER);
    }

    private function decryptRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $jwe = trim($stream->read($this->maxBytes + 1));
        if (\strlen($jwe) > $this->maxBytes) {
            throw new PayloadTooLarge("Request bodies are limited to {$this->maxBytes} bytes.");
        }
        try {
            [$header, $plaintext] = $this->keys->decrypt($jwe);
        } catch (DecryptionFailed $e) {
            throw new BadRequest('The encrypted request could not be decrypted: ' . $e->getMessage(), ['code' => 'invalid_encryption'], $e);
        }
        $type = \is_string($header['cty'] ?? null) ? $header['cty'] : 'application/json';

        return $request
            ->withBody(Stream::create($plaintext))
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) \strlen($plaintext))
            ->withParsedBody(null);
    }

    /** @return array<mixed>|null */
    private function clientKey(ServerRequestInterface $request): ?array
    {
        $value = $request->getHeaderLine(self::KEY_HEADER);
        if ($value === '') {
            return null;
        }
        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $jwk = \is_string($json) ? json_decode($json, true, 4) : null;
        if (!\is_array($jwk) || isset($jwk['d'])) {
            throw new BadRequest(self::KEY_HEADER . ' must be a base64url-encoded public JWK.', ['code' => 'invalid_encryption_key']);
        }
        try {
            Jwe::publicKey($jwk);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequest(self::KEY_HEADER . ': ' . $e->getMessage(), ['code' => 'invalid_encryption_key'], $e);
        }

        return $jwk;
    }

    private function isRequired(string $path): bool
    {
        foreach ($this->required as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasBody(ServerRequestInterface $request): bool
    {
        $size = $request->getBody()->getSize();

        return $size === null ? $request->getHeaderLine('Content-Length') !== '' && $request->getHeaderLine('Content-Length') !== '0' : $size > 0;
    }
}
