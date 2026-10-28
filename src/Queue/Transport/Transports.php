<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

use Keel\Container\Container;
use Keel\Redis\RedisConnection;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Builds the transport named by `queue.transport`:
 *
 *     'queue' => [
 *         'transport' => 'redis',                       // database (default), redis, sqs, amqp
 *         'sqs' => ['region' => 'eu-west-1', 'queues' => ['default' => 'https://sqs.eu-west-1.amazonaws.com/123/app-default']],
 *         'amqp' => ['url' => 'amqp://guest:guest@rabbitmq:5672/'],
 *     ],
 *
 * SQS credentials come from `queue.sqs.key`/`secret`/`token` or AWS_ACCESS_KEY_ID,
 * AWS_SECRET_ACCESS_KEY and AWS_SESSION_TOKEN.
 */
final class Transports
{
    public static function fromContainer(Container $container): ?Transport
    {
        $config = $container->configuration();
        $string = static fn (string $key, string $default = ''): string => \is_scalar($config[$key] ?? null) ? (string) $config[$key] : $default;
        $visibility = (int) $string('queue.visibility_seconds', '300');

        return match ($string('queue.transport', 'database')) {
            'database' => null,
            'redis' => new RedisStreamsTransport($container->get(RedisConnection::class), $visibility),
            'sqs' => self::sqs($container, $string, $config, $visibility),
            'amqp' => new AmqpTransport($string('queue.amqp.url', 'amqp://guest:guest@127.0.0.1:5672/')),
            default => throw new \InvalidArgumentException('queue.transport must be database, redis, sqs or amqp.'),
        };
    }

    /**
     * @param \Closure(string, string=): string $string
     * @param array<string, mixed> $config
     */
    private static function sqs(Container $container, \Closure $string, array $config, int $visibility): SqsTransport
    {
        $region = $string('queue.sqs.region', getenv('AWS_REGION') ?: 'us-east-1');
        $queues = [];
        foreach (\is_array($config['queue.sqs.queues'] ?? null) ? $config['queue.sqs.queues'] : [] as $name => $url) {
            if (\is_string($url)) {
                $queues[(string) $name] = $url;
            }
        }
        $signer = new SigV4(
            $string('queue.sqs.key', (string) getenv('AWS_ACCESS_KEY_ID')),
            $string('queue.sqs.secret', (string) getenv('AWS_SECRET_ACCESS_KEY')),
            $region,
            'sqs',
            $string('queue.sqs.token', (string) getenv('AWS_SESSION_TOKEN')),
        );

        return new SqsTransport(
            $container->get(ClientInterface::class),
            $container->get(ClockInterface::class),
            $signer,
            $queues,
            $string('queue.sqs.endpoint', "https://sqs.$region.amazonaws.com/"),
            $visibility,
        );
    }
}
