<?php

declare(strict_types=1);

namespace Keel\Http\Streaming;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Server-sent events: one long response that pushes events to the browser (EventSource).
 *
 *     #[Get('/orders/live')]
 *     final class LiveOrders
 *     {
 *         public function __invoke(Hub $hub, Principal $user): ResponseInterface
 *         {
 *             return EventStream::response(function (EventSink $events) use ($hub, $user): void {
 *                 $hub->stream("orders.{$user->tenantId}", $events); // until the client leaves
 *             });
 *         }
 *     }
 *
 * Under `keel run` a stream costs one fiber, not a process, so thousands can be open at
 * once. Under FrankenPHP or FPM each open stream holds a worker.
 */
final class EventStream
{
    /** @param \Closure(EventSink): void $producer */
    public static function response(\Closure $producer, int $retryMs = 3000): ResponseInterface
    {
        $body = new StreamingBody(static function (\Closure $write) use ($producer, $retryMs): void {
            $write("retry: $retryMs\n\n");
            try {
                $producer(new EventSink($write));
            } catch (ClientDisconnected) {
                // the client left: nothing more to do
            }
        });

        return new Response(200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // nginx: do not buffer
        ], $body);
    }
}
