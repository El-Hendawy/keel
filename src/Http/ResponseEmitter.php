<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ResponseInterface;

/** Writes a PSR-7 response through the SAPI. */
final class ResponseEmitter
{
    public static function emit(ResponseInterface $response, bool $withBody = true): void
    {
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
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(65536);
        }
    }
}
