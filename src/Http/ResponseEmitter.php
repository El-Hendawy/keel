<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Writes a PSR-7 response through the SAPI. Streaming bodies (server-sent events) are
 * flushed chunk by chunk; WebSocket upgrades need `keel run` and are refused here.
 */
final class ResponseEmitter
{
    public static function emit(ResponseInterface $response, bool $withBody = true): void
    {
        if ($response->getBody() instanceof \Keel\Realtime\UpgradeBody) {
            $response = Responses::problem(501, 'Not Implemented', 'WebSockets need the Keel server: run the application with `keel run`.');
        }
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header(\sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase()), true, $response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                $replace = strtolower((string) $name) !== 'set-cookie';
                foreach ($values as $value) {
                    header("$name: $value", $replace);
                    $replace = false;
                }
            }
        }

        if (!$withBody) {
            return;
        }
        $body = $response->getBody();
        if ($body instanceof Streaming\StreamingBody) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $body->produce(static function (string $chunk): void {
                if (connection_aborted() === 1) {
                    throw new Streaming\ClientDisconnected('The client disconnected.');
                }
                echo $chunk;
                flush();
            });

            return;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(65536);
        }
    }
}
