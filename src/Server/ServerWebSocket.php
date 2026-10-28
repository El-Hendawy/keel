<?php

declare(strict_types=1);

namespace Keel\Server;

use Keel\Async\Io;
use Keel\Async\Suspension;
use Keel\Async\TimeoutException;
use Keel\Auth\AuthContext;
use Keel\Auth\Principal;
use Keel\Container\Container;
use Keel\Realtime\Frame;
use Keel\Realtime\Hub;
use Keel\Realtime\UpgradeBody;
use Keel\Realtime\WebSocketClosed;
use Keel\Realtime\WebSocketConnection;
use Keel\Realtime\WebSocketHandler;
use Keel\Tenancy\TenantContext;
use Psr\Log\LoggerInterface;

/** @internal one WebSocket on the Keel server */
final class ServerWebSocket implements WebSocketConnection
{
    private bool $closed = false;

    private bool $writing = false;

    /** @var list<Suspension> */
    private array $writers = [];

    /** @var array<string, int> channel => hub subscription */
    private array $channels = [];

    private string $buffer;

    /** @param resource $socket */
    public function __construct(
        private $socket,
        private readonly string $id,
        private readonly UpgradeBody $upgrade,
        string $buffered,
        private readonly Container $container,
        private readonly ServerOptions $options,
        private readonly LoggerInterface $logger,
    ) {
        $this->buffer = $buffered;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function principal(): ?Principal
    {
        return $this->upgrade->principal;
    }

    public function tenantId(): ?string
    {
        return $this->upgrade->principal?->tenantId;
    }

    public function context(): array
    {
        return $this->upgrade->context;
    }

    public function send(string $text): void
    {
        $this->write(Frame::encode(Frame::TEXT, $text));
    }

    public function sendBinary(string $data): void
    {
        $this->write(Frame::encode(Frame::BINARY, $data));
    }

    public function join(string $channel): void
    {
        $this->channels[$channel] ??= $this->container->get(Hub::class)->subscribe($channel, function (string $message): void {
            try {
                $this->send($message);
            } catch (\Throwable) {
                // a broken connection is noticed and closed by its own read loop
            }
        });
    }

    public function leave(string $channel): void
    {
        if (isset($this->channels[$channel])) {
            $this->container->get(Hub::class)->unsubscribe($this->channels[$channel]);
            unset($this->channels[$channel]);
        }
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->write(Frame::encode(Frame::CLOSE, pack('n', $code) . substr($reason, 0, 120)));
        } catch (\Throwable) {
        }
        $this->closed = true;
    }

    /** Runs the connection until it closes: dispatches events to the handler, answers pings. */
    public function run(\Closure $stopping): void
    {
        $code = 1006;
        $reason = '';
        try {
            $this->event(static fn (WebSocketHandler $h, self $c) => $h->onOpen($c));
            $message = null;
            $binary = false;
            $idle = 0;
            while (!$this->closed) {
                if ($stopping()) {
                    $this->close(1001, 'Server shutting down');
                    $code = 1001;
                    break;
                }
                $frame = Frame::decode($this->buffer, true, $this->options->maxWebSocketMessageBytes);
                if ($frame === null) {
                    if (!$this->fill()) {
                        if (++$idle >= 2) {
                            throw new WebSocketClosed(1001, 'Idle timeout.');
                        }
                        $this->write(Frame::encode(Frame::PING, 'keel'));
                    } else {
                        $idle = 0;
                    }
                    continue;
                }
                $this->buffer = substr($this->buffer, $frame['length']);
                switch ($frame['opcode']) {
                    case Frame::PING:
                        $this->write(Frame::encode(Frame::PONG, $frame['payload']));
                        break;
                    case Frame::PONG:
                        break;
                    case Frame::CLOSE:
                        $received = \strlen($frame['payload']) >= 2 ? (unpack('n', substr($frame['payload'], 0, 2))[1] ?? 1005) : 1005;
                        $code = \is_int($received) ? $received : 1005;
                        $reason = substr($frame['payload'], 2);
                        $this->close($code !== 1005 ? $code : 1000);
                        break 2;
                    case Frame::TEXT:
                    case Frame::BINARY:
                        if ($message !== null) {
                            throw new WebSocketClosed(1002, 'Expected a continuation frame.');
                        }
                        $message = $frame['payload'];
                        $binary = $frame['opcode'] === Frame::BINARY;
                        // fall through to complete the message
                    case Frame::CONTINUATION:
                        if ($frame['opcode'] === Frame::CONTINUATION) {
                            if ($message === null) {
                                throw new WebSocketClosed(1002, 'Unexpected continuation frame.');
                            }
                            $message .= $frame['payload'];
                        }
                        if (\strlen((string) $message) > $this->options->maxWebSocketMessageBytes) {
                            throw new WebSocketClosed(1009, 'Message too big.');
                        }
                        if ($frame['fin']) {
                            $complete = (string) $message;
                            $message = null;
                            if (!$binary && !mb_check_encoding($complete, 'UTF-8')) {
                                throw new WebSocketClosed(1007, 'Text messages must be UTF-8.');
                            }
                            $this->event(static fn (WebSocketHandler $h, self $c) => $h->onMessage($c, $complete, $binary));
                        }
                        break;
                    default:
                        throw new WebSocketClosed(1002, 'Unknown opcode.');
                }
            }
        } catch (WebSocketClosed $e) {
            $code = $e->closeCode;
            $reason = $e->getMessage();
            $this->close($code, $reason);
        } catch (\Throwable $e) {
            $this->logger->error('WebSocket {id} failed: {error}', ['id' => $this->id, 'error' => $e->getMessage(), 'exception' => $e]);
            $code = 1011;
            $this->close(1011, 'Internal error');
        } finally {
            foreach (array_keys($this->channels) as $channel) {
                $this->leave($channel);
            }
            try {
                $this->event(static fn (WebSocketHandler $h, self $c) => $h->onClose($c, $code, $reason));
            } catch (\Throwable $e) {
                $this->logger->error('WebSocket {id} onClose failed: {error}', ['id' => $this->id, 'error' => $e->getMessage()]);
            }
            @fclose($this->socket);
        }
    }

    /** @param \Closure(WebSocketHandler, self): void $call */
    private function event(\Closure $call): void
    {
        $scope = $this->container->createScope();
        try {
            if ($this->upgrade->principal !== null) {
                $scope->get(AuthContext::class)->set($this->upgrade->principal);
                if ($this->upgrade->principal->tenantId !== null) {
                    $scope->get(TenantContext::class)->set($this->upgrade->principal->tenantId);
                }
            }
            $handler = $scope->get($this->upgrade->handler);
            $call($handler, $this);
        } finally {
            $scope->dispose();
        }
    }

    /** @return bool false on idle timeout */
    private function fill(): bool
    {
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            throw new WebSocketClosed(1006, 'Connection lost.');
        }
        if ($chunk !== '') {
            $this->buffer .= $chunk;

            return true;
        }
        try {
            Io::readable($this->socket, $this->options->webSocketPingSeconds);
        } catch (TimeoutException) {
            return false;
        }

        return true;
    }

    /** Frames from several fibers (handler, hub broadcasts) are written one at a time. */
    private function write(string $frame): void
    {
        if ($this->closed) {
            throw new WebSocketClosed(1006, 'The connection is closed.');
        }
        if ($this->writing) {
            $suspension = Suspension::create();
            $this->writers[] = $suspension;
            $suspension->suspend();
        }
        $this->writing = true;
        try {
            Io::writeAll($this->socket, $frame, 30.0);
        } finally {
            $next = array_shift($this->writers);
            $next === null ? $this->writing = false : $next->resume();
        }
    }
}
