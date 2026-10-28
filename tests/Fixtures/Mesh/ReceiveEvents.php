<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Mesh;

use Keel\Auth\Principal;
use Keel\Routing\Attribute\Post;
use Keel\Service\CloudEvent;
use Keel\Service\Inbox;
use Psr\Http\Message\ServerRequestInterface;

#[Post('/events')]
final class ReceiveEvents
{
    public function __invoke(ServerRequestInterface $request, Inbox $inbox, ReceivedEvents $received, Principal $caller): Received
    {
        $event = CloudEvent::fromRequest($request);
        $handled = $inbox->once($event, static function () use ($received, $event, $caller): void {
            $received->events[] = ['type' => $event->type, 'tenant' => $caller->tenantId, 'data' => $event->data];
        });

        return new Received($handled, \count($received->events));
    }
}
