<?php

declare(strict_types=1);

namespace Keel\Http\Client;

use Keel\Async\CancelledException;
use Keel\Async\Loop;
use Keel\Async\Suspension;
use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client on curl_multi. Inside await_all() requests run concurrently; outside,
 * the call simply blocks until the response arrives. Timeouts are always set.
 */
#[Singleton]
final readonly class CurlHttpClient implements ClientInterface
{
    public function __construct(
        #[Config('http_client.connect_timeout_ms')] private int $connectTimeoutMs = 2000,
        #[Config('http_client.timeout_ms')] private int $timeoutMs = 10_000,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        if ($url === '' || $method === '') {
            throw new NetworkException($request, 'Requests need a URL and a method.');
        }
        $handle = curl_init();
        $responseHeaders = [];
        $headerLines = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headerLines[] = $name . ': ' . implode(', ', $values);
        }
        $body = (string) $request->getBody();

        curl_setopt_array($handle, [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headerLines,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            \CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_2TLS,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $h, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[trim($parts[0])][] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (e.g. after 100 Continue)
                }

                return \strlen($line);
            },
        ]);
        if ($body !== '' || \in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, $body);
        }
        if ($request->getMethod() === 'HEAD') {
            curl_setopt($handle, \CURLOPT_NOBODY, true);
        }

        $loop = Loop::get();
        $suspension = Suspension::create();
        $loop->addCurl($handle, static function (\CurlHandle $h, int $code) use ($suspension, $request): void {
            if ($code === \CURLE_OK) {
                $suspension->resume();
            } else {
                $suspension->throw(new NetworkException($request, \sprintf('%s %s failed: %s', $request->getMethod(), $request->getUri(), curl_strerror($code))));
            }
        });
        $suspension->onCancel(static fn () => $loop->removeCurl($handle));

        try {
            $suspension->suspend();
        } catch (CancelledException $e) {
            curl_close($handle);
            throw $e;
        }

        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $content = curl_multi_getcontent($handle) ?? '';
        curl_close($handle);

        return new Response($status, $responseHeaders, $content);
    }
}
