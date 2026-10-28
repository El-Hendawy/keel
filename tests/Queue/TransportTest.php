<?php

declare(strict_types=1);

namespace Keel\Tests\Queue;

use Keel\Queue\Transport\AmqpTransport;
use Keel\Queue\Transport\Envelope;
use Keel\Queue\Transport\RedisStreamsTransport;
use Keel\Queue\Transport\SigV4;
use Keel\Queue\Transport\SqsTransport;
use Keel\Queue\Transport\Transport;
use Keel\Redis\RedisConnection;
use Keel\Testing\FakeHttpClient;
use Keel\Testing\FrozenClock;
use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Broker transports. Redis and RabbitMQ parts run when KEEL_TEST_REDIS_URL / KEEL_TEST_AMQP_URL are set. */
final class TransportTest extends TestCase
{
    public function testSigV4MatchesTheAwsTestSuite(): void
    {
        // "get-vanilla" from the AWS Signature Version 4 test suite.
        $signer = new SigV4('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');
        $signed = $signer->sign(new Request('GET', 'https://example.amazonaws.com/'), new \DateTimeImmutable('2015-08-30T12:36:00Z'));
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $signed->getHeaderLine('Authorization'),
        );
    }

    public function testSqsSpeaksTheJsonProtocol(): void
    {
        $http = new FakeHttpClient();
        $calls = [];
        $queue = [];
        $http->stub('POST', 'https://sqs.eu-west-1.amazonaws.com/', static function (RequestInterface $request) use (&$calls, &$queue): Response {
            $action = substr($request->getHeaderLine('X-Amz-Target'), \strlen('AmazonSQS.'));
            $body = json_decode((string) $request->getBody(), true);
            self::assertIsArray($body);
            self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $request->getHeaderLine('Authorization'));
            $calls[] = $action . ' ' . $body['QueueUrl'];

            if ($action === 'SendMessage') {
                $queue[] = $body['MessageBody'];
            }

            return match ($action) {
                'SendMessage' => new Response(200, [], (string) json_encode(['MessageId' => 'm-' . \count($queue)])),
                'ReceiveMessage' => new Response(200, [], (string) json_encode(['Messages' => array_map(static fn (string $b) => ['Body' => $b, 'ReceiptHandle' => 'rh-1', 'Attributes' => ['ApproximateReceiveCount' => '2']], \array_slice($queue, 0, 1))])),
                default => new Response(200, [], '{}'),
            };
        });
        $transport = new SqsTransport($http, new FrozenClock(), new SigV4('AKID', 'secret', 'eu-west-1', 'sqs'), [
            'default' => 'https://sqs.eu-west-1.amazonaws.com/123/app',
            'default-failed' => 'https://sqs.eu-west-1.amazonaws.com/123/app-failed',
        ], 'https://sqs.eu-west-1.amazonaws.com/');

        $transport->publish(new Envelope('j1', 'default', 'T', '{"a":1}'));
        $delivery = $transport->receive(['default'], 1.0);
        self::assertNotNull($delivery);
        self::assertSame('j1', $delivery->envelope->id);
        self::assertSame(2, $delivery->attempts, 'Attempts come from ApproximateReceiveCount.');
        $transport->retry($delivery, 30, 'boom');
        $transport->fail($delivery, 'boom');
        self::assertSame([
            'SendMessage https://sqs.eu-west-1.amazonaws.com/123/app',
            'ReceiveMessage https://sqs.eu-west-1.amazonaws.com/123/app',
            'ChangeMessageVisibility https://sqs.eu-west-1.amazonaws.com/123/app',
            'SendMessage https://sqs.eu-west-1.amazonaws.com/123/app-failed',
            'DeleteMessage https://sqs.eu-west-1.amazonaws.com/123/app',
        ], $calls);
    }

    public function testRedisStreams(): void
    {
        $url = getenv('KEEL_TEST_REDIS_URL') ?: self::markTestSkipped('Set KEEL_TEST_REDIS_URL to run against Redis.');
        $redis = new RedisConnection($url);
        $queue = 'test-' . bin2hex(random_bytes(4));
        $transport = new RedisStreamsTransport($redis, visibilitySeconds: 1);

        $transport->publish(new Envelope('a', $queue, 'T', '1'));
        $transport->publish(new Envelope('b', $queue, 'T', '2'), delaySeconds: 1);
        $first = $transport->receive([$queue], 0.1);
        self::assertSame('a', $first?->envelope->id);
        self::assertNull($transport->receive([$queue], 0.1), 'b is delayed.');
        $transport->ack($first);

        // A worker takes b and dies without acknowledging: after the visibility timeout another worker gets it.
        usleep(1_100_000);
        $taken = $transport->receive([$queue], 0.1);
        self::assertSame('b', $taken?->envelope->id);
        usleep(1_100_000);
        $other = new RedisStreamsTransport(new RedisConnection($url), visibilitySeconds: 1);
        $reclaimed = $other->receive([$queue], 0.1);
        self::assertSame('b', $reclaimed?->envelope->id);

        $other->retry($reclaimed, 1, 'boom');
        usleep(1_100_000);
        $retried = $other->receive([$queue], 0.1);
        self::assertSame('b', $retried?->envelope->id);
        self::assertSame(2, $retried->attempts);
        $other->fail($retried, 'boom');
        self::assertSame(1, $redis->client()->xLen("keel:q:$queue:failed"));
        self::assertNull($other->receive([$queue], 0.1));
        $redis->client()->del("keel:q:$queue", "keel:q:$queue:failed", "keel:q:$queue:delayed");
    }

    public function testTheOutboxFeedsTheBroker(): void
    {
        $url = getenv('KEEL_TEST_REDIS_URL') ?: self::markTestSkipped('Set KEEL_TEST_REDIS_URL to run against Redis.');
        $root = __DIR__ . '/../../examples/shop';
        $app = TestApp::boot($root . '/app.php', [
            'database' => ['dsn' => TestDatabase::dsn()],
            'log' => ['stream' => 'php://memory'],
            'redis' => ['url' => $url],
            'queue' => ['transport' => 'redis'],
            'merchants' => ['webhook_url' => 'https://merchant.example/hooks', 'webhook_secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'],
            'webhooks' => ['unsafe_allow_private_networks' => true],
        ]);
        $app->freshDatabase($root . '/migrations');
        $app->container()->get(RedisConnection::class)->client()->del('keel:q:default', 'keel:q:webhooks');
        $app->http()->stub('POST', 'https://merchant.example/hooks', [], 204);

        $app->post('/orders', ['merchantId' => '22222222-2222-4222-8222-222222222222', 'items' => [['sku' => 'A', 'quantity' => 1, 'unitPriceCents' => 5]]], $app->tokenFor('ada', '11111111-1111-4111-8111-111111111111'))->assertStatus(201);
        self::assertSame(2, $app->runJobs(), 'Listener job relayed and consumed, then the webhook job it pushed.');
        self::assertCount(1, $app->http()->sent());
    }

    public function testSqsAgainstARealServer(): void
    {
        $endpoint = getenv('KEEL_TEST_SQS_ENDPOINT') ?: self::markTestSkipped('Set KEEL_TEST_SQS_ENDPOINT (ElasticMQ, LocalStack) to run against SQS.');
        $http = new \Keel\Http\Client\CurlHttpClient();
        $signer = new SigV4('x', 'x', 'us-east-1', 'sqs');
        $name = 'keel-' . bin2hex(random_bytes(4));
        $urls = [];
        foreach ([$name, "$name-failed"] as $queue) {
            $request = $signer->sign(new Request('POST', $endpoint, ['Content-Type' => 'application/x-amz-json-1.0', 'X-Amz-Target' => 'AmazonSQS.CreateQueue'], (string) json_encode(['QueueName' => $queue])), new \DateTimeImmutable());
            $created = json_decode((string) $http->sendRequest($request)->getBody(), true);
            self::assertIsArray($created, 'CreateQueue answered');
            $urls[$queue] = (string) $created['QueueUrl'];
        }
        $transport = new SqsTransport($http, new \Keel\Support\SystemClock(), $signer, $urls, $endpoint, visibilitySeconds: 30);
        $transport->publish(new Envelope('a', $name, 'T', '{"x":1}'));
        $delivery = $transport->receive([$name], 2.0);
        self::assertSame('a', $delivery?->envelope->id);
        self::assertSame(1, $delivery->attempts);
        $transport->fail($delivery, 'boom');
        self::assertNull($transport->receive([$name], 1.0));
        self::assertSame('a', $transport->receive(["$name-failed"], 2.0)?->envelope->id);
    }

    public function testRabbitMq(): void
    {
        $url = getenv('KEEL_TEST_AMQP_URL') ?: self::markTestSkipped('Set KEEL_TEST_AMQP_URL to run against RabbitMQ.');
        $transport = new AmqpTransport($url);
        $queue = 'test-' . bin2hex(random_bytes(4));
        $transport->publish(new Envelope('a', $queue, 'T', '1'));
        $delivery = $transport->receive([$queue], 2.0);
        self::assertSame('a', $delivery?->envelope->id);
        $transport->retry($delivery, 1, 'boom');
        $retried = $transport->receive([$queue], 3.0);
        self::assertSame(2, $retried?->attempts);
        $transport->fail($retried, 'boom');
        self::assertNull($transport->receive([$queue], 0.3));
        self::assertInstanceOf(Transport::class, $transport);
    }
}
