<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Auth\Attribute\Authorize;
use Keel\Realtime\WebSocket;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Live orders over a WebSocket (needs `keel run`). Browsers authenticate with
 * `new WebSocket(url, ['keel', 'keel.token.' + token])`.
 */
#[Get('/ws/orders', name: 'orders.socket')]
#[Authorize(scopes: ['orders:read'])]
final class OrdersWebSocket
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return WebSocket::accept($request, OrdersSocket::class);
    }
}
