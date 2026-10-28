<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Http\Streaming\EventSink;
use Keel\Http\Streaming\EventStream;
use Keel\Realtime\Hub;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;

use function Keel\Async\delay;

/** Three events, then whatever is published on the "news" channel. */
#[Get('/events')]
final class Events
{
    public function __invoke(Hub $hub): ResponseInterface
    {
        return EventStream::response(static function (EventSink $events) use ($hub): void {
            for ($i = 1; $i <= 3; $i++) {
                $events->json(['n' => $i], 'tick', (string) $i);
                delay(0.05);
            }
            $hub->stream('news', $events, 'news', heartbeatSeconds: 0.2);
        });
    }
}
