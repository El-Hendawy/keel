<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

/**
 * RabbitMQ (AMQP 0-9-1) through php-amqplib (`composer require php-amqplib/php-amqplib`).
 *
 * Per queue: a durable `keel.<queue>`, a `keel.<queue>.retry` queue whose messages expire
 * back into it (delays and retries), and `keel.<queue>.failed` for dead letters. Messages
 * are persistent and acknowledged only after the handler succeeds.
 */
final class AmqpTransport implements Transport
{
    private ?\PhpAmqpLib\Connection\AbstractConnection $connection = null;

    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;

    /** @var array<string, true> */
    private array $declared = [];

    /** @var array<string, \PhpAmqpLib\Message\AMQPMessage> delivery handle => message */
    private array $inflight = [];

    private int $tag = 0;

    public function __construct(private readonly string $url)
    {
        if (!class_exists(\PhpAmqpLib\Connection\AMQPStreamConnection::class)) {
            throw new \RuntimeException('The amqp transport needs php-amqplib: composer require php-amqplib/php-amqplib');
        }
    }

    public function publish(Envelope $envelope, int $delaySeconds = 0): void
    {
        $this->declare($envelope->queue);
        $properties = ['delivery_mode' => 2, 'message_id' => $envelope->id, 'content_type' => 'application/json'];
        $target = 'keel.' . $envelope->queue;
        if ($delaySeconds > 0) {
            $properties['expiration'] = (string) ($delaySeconds * 1000);
            $target .= '.retry';
        }
        $this->channel()->basic_publish(new \PhpAmqpLib\Message\AMQPMessage($envelope->encode(), $properties), '', $target);
    }

    public function receive(array $queues, float $waitSeconds): ?Delivery
    {
        $deadline = microtime(true) + $waitSeconds;
        do {
            foreach ($queues as $queue) {
                $this->declare($queue);
                $message = $this->channel()->basic_get('keel.' . $queue);
                if ($message instanceof \PhpAmqpLib\Message\AMQPMessage) {
                    $envelope = Envelope::decode((string) $message->getBody());
                    $handle = 'd' . ++$this->tag;
                    $this->inflight[$handle] = $message;

                    return new Delivery($envelope, $handle, $envelope->attempts + 1);
                }
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function ack(Delivery $delivery): void
    {
        $message = $this->inflight[$delivery->handle] ?? null;
        unset($this->inflight[$delivery->handle]);
        $message?->ack();
    }

    public function retry(Delivery $delivery, int $delaySeconds, string $error): void
    {
        $this->publish($delivery->envelope->withAttempts($delivery->attempts), max(1, $delaySeconds));
        $this->ack($delivery);
    }

    public function fail(Delivery $delivery, string $error): void
    {
        $this->declare($delivery->envelope->queue);
        $this->channel()->basic_publish(new \PhpAmqpLib\Message\AMQPMessage($delivery->envelope->withAttempts($delivery->attempts)->encode(), [
            'delivery_mode' => 2,
            'application_headers' => new \PhpAmqpLib\Wire\AMQPTable(['x-error' => substr($error, 0, 1000)]),
        ]), '', 'keel.' . $delivery->envelope->queue . '.failed');
        $this->ack($delivery);
    }

    private function declare(string $queue): void
    {
        if (isset($this->declared[$queue])) {
            return;
        }
        $channel = $this->channel();
        $main = 'keel.' . $queue;
        $channel->queue_declare($main, false, true, false, false);
        $channel->queue_declare($main . '.retry', false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $main,
        ]));
        $channel->queue_declare($main . '.failed', false, true, false, false);
        $this->declared[$queue] = true;
    }

    private function channel(): \PhpAmqpLib\Channel\AMQPChannel
    {
        if ($this->channel !== null && $this->connection?->isConnected() === true) {
            return $this->channel;
        }
        $parts = parse_url($this->url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('queue.amqp.url is not a valid URL.');
        }
        $path = trim($parts['path'] ?? '', '/');
        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
            $parts['host'],
            $parts['port'] ?? 5672,
            rawurldecode($parts['user'] ?? 'guest'),
            rawurldecode($parts['pass'] ?? 'guest'),
            $path === '' ? '/' : rawurldecode($path), // the vhost
        );
        $channel = $connection->channel();
        $channel->basic_qos(0, 1, false);
        $this->connection = $connection;
        $this->channel = $channel;

        return $channel;
    }
}
