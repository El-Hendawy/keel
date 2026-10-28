<?php

declare(strict_types=1);

namespace Keel\Server;

use Keel\Async\Io;
use Keel\Async\Loop;
use Keel\Async\Task;
use Keel\Http\Responses;
use Keel\Http\Streaming\ClientDisconnected;
use Keel\Http\Streaming\StreamingBody;
use Keel\Realtime\Hub;
use Keel\Realtime\UpgradeBody;
use Keel\Realtime\WebSocket;
use Keel\Redis\AsyncRedis;
use Keel\Runtime\Application;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * One Keel server process: an HTTP/1.1 server on the event loop. Every connection is a
 * fiber; while one request waits on the database, Redis or an HTTP call, the others run.
 * Singletons are shared by all requests of the process; scoped services stay per request.
 *
 * Put a TLS-terminating proxy (Caddy, nginx, a load balancer) in front for HTTPS, HTTP/2
 * and HTTP/3.
 */
final class HttpServer
{
    private bool $stopping = false;

    private int $connections = 0;

    private int $handled = 0;

    private int $nextId = 0;

    private ?int $acceptWatcher = null;

    /** @param resource $listener a listening socket (shared by every process) */
    public function __construct(
        private readonly Application $app,
        private $listener,
        private readonly ServerOptions $options,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Serves until stop() is called (on SIGTERM) and in-flight work has finished. */
    public function run(): void
    {
        stream_set_blocking($this->listener, false);
        $loop = Loop::get();
        $this->acceptWatcher = $loop->onReadable($this->listener, $this->accept(...));
        $hub = $this->app->container()->has(Hub::class) ? $this->app->container()->get(Hub::class) : null;
        if ($hub !== null && $hub->usesRedis()) {
            Task::start(fn () => $this->relayHub($hub));
        }
        $deadline = null;
        $loop->run(function () use (&$deadline): bool {
            if (!$this->stopping) {
                return false;
            }
            $deadline ??= microtime(true) + $this->options->shutdownGraceSeconds;

            return $this->connections === 0 || microtime(true) > $deadline;
        });
        $this->app->shutdown();
    }

    /** Stops accepting; open connections finish their current request (WebSockets close with 1001). */
    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->app->drain();
        if ($this->acceptWatcher !== null) {
            Loop::get()->cancelWatcher($this->acceptWatcher);
            $this->acceptWatcher = null;
        }
        Loop::get()->delay(0.0, static fn () => null); // wake the loop
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    private function accept(): void
    {
        $client = @stream_socket_accept($this->listener, 0, $peer);
        if ($client === false) {
            return; // another process took it
        }
        if ($this->connections >= $this->options->maxConnections) {
            @fwrite($client, "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\nRetry-After: 1\r\n\r\n");
            @fclose($client);

            return;
        }
        stream_set_blocking($client, false);
        $this->connections++;
        [$address, $port] = self::peer(\is_string($peer) ? $peer : '');
        Task::start(function () use ($client, $address, $port): void {
            try {
                $this->serve($client, $address, $port);
            } catch (\Throwable $e) {
                $this->logger->error('Connection failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            } finally {
                @fclose($client);
                $this->connections--;
            }
        });
    }

    /** @param resource $socket */
    private function serve($socket, string $address, int $port): void
    {
        $reader = new RequestReader($socket, $this->options, $address, $port);
        $first = true;
        $kernel = $this->app->http();
        while (!$this->stopping || $first) {
            try {
                $request = $reader->next($first);
            } catch (HttpError $e) {
                $this->write($socket, Responses::problem($e->status, 'Bad Request', $e->getMessage()), false, 'GET');

                return;
            }
            if ($request === null) {
                return;
            }
            $first = false;
            $response = $kernel->handle($request);
            $this->handled++;
            $body = $response->getBody();
            if ($body instanceof UpgradeBody) {
                $this->upgrade($socket, $request, $response, $body, $reader->takeBuffer());

                return;
            }
            $keepAlive = self::keepAlive($request) && !$this->stopping && !$this->recycling();
            try {
                $this->write($socket, $response, $keepAlive, $request->getMethod());
            } catch (ClientDisconnected) {
                return;
            } finally {
                $this->app->afterRequest();
            }
            if ($this->recycling()) {
                $this->stop(); // max requests reached: drain and let the supervisor start a fresh process
            }
            if (!$keepAlive) {
                return;
            }
        }
    }

    /** @param resource $socket */
    private function write($socket, ResponseInterface $response, bool $keepAlive, string $method): void
    {
        $status = $response->getStatusCode();
        $body = $response->getBody();
        $streaming = $body instanceof StreamingBody;
        $noBody = $method === 'HEAD' || $status < 200 || $status === 204 || $status === 304;
        $content = $streaming || $noBody ? '' : (string) $body;

        $head = \sprintf("HTTP/1.1 %d %s\r\n", $status, $response->getReasonPhrase());
        foreach ($response->withoutHeader('Content-Length')->withoutHeader('Transfer-Encoding')->withoutHeader('Connection')->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $head .= "$name: " . str_replace(["\r", "\n"], '', $value) . "\r\n";
            }
        }
        $head .= 'Date: ' . gmdate('D, d M Y H:i:s') . " GMT\r\n";
        $head .= 'Connection: ' . ($keepAlive ? 'keep-alive' : 'close') . "\r\n";
        $head .= $streaming && !$noBody ? "Transfer-Encoding: chunked\r\n" : ($status >= 200 && $status !== 204 && $status !== 304 ? 'Content-Length: ' . \strlen($method === 'HEAD' ? (string) $body : $content) . "\r\n" : '');

        try {
            Io::writeAll($socket, $head . "\r\n" . $content, 30.0);
            if ($streaming && !$noBody) {
                $body->produce(static function (string $chunk) use ($socket): void {
                    if ($chunk === '') {
                        return;
                    }
                    try {
                        Io::writeAll($socket, dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n", 30.0);
                    } catch (\RuntimeException $e) {
                        throw new ClientDisconnected('The client disconnected.', 0, $e);
                    }
                });
                Io::writeAll($socket, "0\r\n\r\n", 30.0);
            }
        } catch (\RuntimeException $e) {
            throw $e instanceof ClientDisconnected ? $e : new ClientDisconnected('The client disconnected.', 0, $e);
        }
    }

    /** @param resource $socket */
    private function upgrade($socket, ServerRequestInterface $request, ResponseInterface $response, UpgradeBody $body, string $buffered): void
    {
        $head = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . WebSocket::acceptKey($request->getHeaderLine('Sec-WebSocket-Key')) . "\r\n"
            . ($body->protocol !== null ? "Sec-WebSocket-Protocol: {$body->protocol}\r\n" : '');
        foreach (['X-Request-Id', 'traceparent'] as $name) {
            if ($response->hasHeader($name)) {
                $head .= "$name: " . $response->getHeaderLine($name) . "\r\n";
            }
        }
        Io::writeAll($socket, $head . "\r\n", 10.0);
        $this->app->afterRequest();
        $connection = new ServerWebSocket($socket, 'ws-' . getmypid() . '-' . ++$this->nextId, $body, $buffered, $this->app->container(), $this->options, $this->logger);
        $connection->run(fn (): bool => $this->stopping);
    }

    /** Delivers Hub messages published through Redis (by any process) to this process's subscribers. */
    private function relayHub(Hub $hub): void
    {
        $config = $this->app->container()->configuration();
        $url = \is_string($config['redis.url'] ?? null) ? $config['redis.url'] : 'redis://127.0.0.1:6379';
        while (!$this->stopping) {
            try {
                (new AsyncRedis($url))->psubscribe([Hub::PREFIX . '*'], $hub->receive(...), fn (): bool => $this->stopping);
            } catch (\Throwable $e) {
                $this->logger->warning('Hub relay lost Redis ({error}); retrying.', ['error' => $e->getMessage()]);
                \Keel\Async\delay(1.0);
            }
        }
    }

    private function recycling(): bool
    {
        return $this->options->maxRequests > 0 && $this->handled >= $this->options->maxRequests;
    }

    private static function keepAlive(ServerRequestInterface $request): bool
    {
        $connection = strtolower($request->getHeaderLine('Connection'));

        return $request->getProtocolVersion() === '1.1' ? !str_contains($connection, 'close') : str_contains($connection, 'keep-alive');
    }

    /** @return array{0: string, 1: int} */
    private static function peer(string $peer): array
    {
        $colon = strrpos($peer, ':');
        if ($colon === false) {
            return [$peer, 0];
        }

        return [trim(substr($peer, 0, $colon), '[]'), (int) substr($peer, $colon + 1)];
    }
}
