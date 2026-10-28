<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Realtime\Hub;
use Keel\Realtime\WebSocketConnection;
use Keel\Realtime\WebSocketHandler;

/** Echoes messages, and relays "say:" messages to everyone in the room through the hub. */
final readonly class ChatSocket implements WebSocketHandler
{
    public function __construct(private Hub $hub)
    {
    }

    public function onOpen(WebSocketConnection $connection): void
    {
        $connection->join('room.' . $connection->context()['room']);
        $connection->send('welcome to ' . $connection->context()['room']);
    }

    public function onMessage(WebSocketConnection $connection, string $message, bool $binary): void
    {
        if (str_starts_with($message, 'say:')) {
            $this->hub->publish('room.' . $connection->context()['room'], substr($message, 4));

            return;
        }
        $binary ? $connection->sendBinary(strrev($message)) : $connection->send('echo:' . $message);
    }

    public function onClose(WebSocketConnection $connection, int $code, string $reason): void
    {
    }
}
