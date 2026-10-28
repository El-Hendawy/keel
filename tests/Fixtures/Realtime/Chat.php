<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Realtime\WebSocket;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get('/ws/{room}')]
final class Chat
{
    public function __invoke(string $room, ServerRequestInterface $request): ResponseInterface
    {
        return WebSocket::accept($request, ChatSocket::class, ['room' => $room]);
    }
}
