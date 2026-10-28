<?php

declare(strict_types=1);

namespace Keel\Crypto;

use Keel\Auth\Jwt\Jwk;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The client side of end-to-end message encryption, for PHP services and tests calling a Keel
 * API protected by MessageEncryptionMiddleware.
 *
 *     $client = MessageEncryption::client();                       // a key pair for this client
 *     $request = MessageEncryption::seal($request, $serverJwk, $client);
 *     $response = $http->sendRequest($request);
 *     $plain = MessageEncryption::open($response, $client);       // the decrypted body
 *
 * Browsers do the same with WebCrypto and a JOSE library (for example `jose` on npm).
 */
final class MessageEncryption
{
    /** A fresh P-256 key pair. Keep it for the session; its public half travels with each request. */
    public static function client(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Could not generate an EC key.');
        }

        return $key;
    }

    /**
     * Encrypts the request body to the server's key, and asks for an encrypted response to $client.
     *
     * @param array<mixed> $server the server's public JWK (from its key set)
     */
    public static function seal(RequestInterface $request, array $server, ?\OpenSSLAsymmetricKey $client = null): RequestInterface
    {
        $body = (string) $request->getBody();
        if ($body !== '') {
            $type = $request->getHeaderLine('Content-Type');
            $jwe = Jwe::encrypt($body, $server, ['cty' => $type === '' ? 'application/json' : $type]);
            $request = $request->withBody(Stream::create($jwe))->withHeader('Content-Type', Jwe::MEDIA_TYPE);
        }
        if ($client !== null) {
            $request = $request->withHeader(MessageEncryptionMiddleware::KEY_HEADER, self::publicKeyHeader($client));
        }

        return $request;
    }

    /** The response body, decrypted when the server encrypted it. */
    public static function open(ResponseInterface $response, \OpenSSLAsymmetricKey $client): string
    {
        $body = (string) $response->getBody();
        if (strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])) !== Jwe::MEDIA_TYPE) {
            return $body;
        }

        return Jwe::decrypt($body, ['client' => $client])[1];
    }

    public static function publicKeyHeader(\OpenSSLAsymmetricKey $client): string
    {
        return rtrim(strtr(base64_encode(json_encode(Jwk::fromOpenSsl($client), \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
