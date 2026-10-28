<?php

declare(strict_types=1);

namespace Keel\Realtime;

use Keel\Http\Exception\BadRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Accepting WebSocket connections from an ordinary endpoint, so the handshake goes through
 * the same middleware as any request: authentication, tenancy, rate limits.
 *
 *     #[Get('/ws/rooms/{room}')]
 *     final class JoinRoom
 *     {
 *         public function __invoke(string $room, ServerRequestInterface $request): ResponseInterface
 *         {
 *             return WebSocket::accept($request, ChatSocket::class, ['room' => $room]);
 *         }
 *     }
 *
 * The handler runs in a fresh scope for every event, with the caller's principal and tenant.
 * Browsers cannot send an Authorization header on a WebSocket; send the token as the
 * subprotocol pair `Sec-WebSocket-Protocol: keel, keel.token.<token>`.
 */
final class WebSocket
{
    public const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * @param class-string<WebSocketHandler> $handler
     * @param array<string, scalar|null> $context available as $connection->context
     */
    public static function accept(ServerRequestInterface $request, string $handler, array $context = []): ResponseInterface
    {
        if (strtolower($request->getHeaderLine('Upgrade')) !== 'websocket' || !str_contains(strtolower($request->getHeaderLine('Connection')), 'upgrade')) {
            throw new BadRequest('This endpoint only accepts WebSocket connections (Upgrade: websocket).');
        }
        if ($request->getHeaderLine('Sec-WebSocket-Version') !== '13') {
            throw new BadRequest('Only WebSocket version 13 is supported.', ['code' => 'unsupported_version']);
        }
        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        $decoded = base64_decode($key, true);
        if ($decoded === false || \strlen($decoded) !== 16) {
            throw new BadRequest('Invalid Sec-WebSocket-Key.');
        }
        $protocols = array_map(trim(...), explode(',', $request->getHeaderLine('Sec-WebSocket-Protocol')));
        $protocol = \in_array('keel', $protocols, true) ? 'keel' : null;

        $principal = $request->getAttribute(\Keel\Auth\Principal::class);

        return new Response(101, [], new UpgradeBody($handler, $context, $protocol, $principal instanceof \Keel\Auth\Principal ? $principal : null));
    }

    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /** The bearer token carried in the subprotocol list, if any. */
    public static function tokenFromProtocols(string $header): ?string
    {
        foreach (explode(',', $header) as $protocol) {
            $protocol = trim($protocol);
            if (str_starts_with($protocol, 'keel.token.')) {
                return substr($protocol, \strlen('keel.token.'));
            }
        }

        return null;
    }
}
