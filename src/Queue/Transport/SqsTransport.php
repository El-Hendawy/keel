<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

use Nyholm\Psr7\Request;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Amazon SQS through its JSON API, signed with SigV4 (no AWS SDK needed). Queues map to
 * queue URLs; failed jobs go to `<queue>-failed` unless mapped. Delays above 15 minutes
 * (the SQS limit) are capped; retries use the visibility timeout (up to 12 hours).
 * `endpoint` points at LocalStack or ElasticMQ in development.
 */
final class SqsTransport implements Transport
{
    /** @param array<string, string> $queueUrls queue name => queue URL */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly ClockInterface $clock,
        private readonly SigV4 $signer,
        private readonly array $queueUrls,
        private readonly string $endpoint,
        private readonly int $visibilitySeconds = 300,
    ) {
    }

    public function publish(Envelope $envelope, int $delaySeconds = 0): void
    {
        $this->call('SendMessage', ['QueueUrl' => $this->url($envelope->queue), 'MessageBody' => $envelope->encode(), 'DelaySeconds' => min(900, max(0, $delaySeconds))]);
    }

    public function receive(array $queues, float $waitSeconds): ?Delivery
    {
        $wait = (int) min(20, max(0, round($waitSeconds / max(1, \count($queues)))));
        foreach ($queues as $queue) {
            $result = $this->call('ReceiveMessage', [
                'QueueUrl' => $this->url($queue),
                'MaxNumberOfMessages' => 1,
                'WaitTimeSeconds' => $wait,
                'VisibilityTimeout' => $this->visibilitySeconds,
                'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
                'AttributeNames' => ['ApproximateReceiveCount'],
            ]);
            $message = \is_array($result['Messages'] ?? null) ? ($result['Messages'][0] ?? null) : null;
            if (!\is_array($message) || !\is_string($message['Body'] ?? null) || !\is_string($message['ReceiptHandle'] ?? null)) {
                continue;
            }
            $envelope = Envelope::decode($message['Body']);
            $received = \is_array($message['Attributes'] ?? null) ? ($message['Attributes']['ApproximateReceiveCount'] ?? 1) : 1;
            $count = is_numeric($received) ? (int) $received : 1;

            return new Delivery($envelope, $queue . '|' . $message['ReceiptHandle'], $envelope->attempts + $count);
        }

        return null;
    }

    public function ack(Delivery $delivery): void
    {
        [$queue, $receipt] = explode('|', $delivery->handle, 2);
        $this->call('DeleteMessage', ['QueueUrl' => $this->url($queue), 'ReceiptHandle' => $receipt]);
    }

    public function retry(Delivery $delivery, int $delaySeconds, string $error): void
    {
        [$queue, $receipt] = explode('|', $delivery->handle, 2);
        $this->call('ChangeMessageVisibility', ['QueueUrl' => $this->url($queue), 'ReceiptHandle' => $receipt, 'VisibilityTimeout' => min(43_200, max(1, $delaySeconds))]);
    }

    public function fail(Delivery $delivery, string $error): void
    {
        $failed = $delivery->envelope->queue . '-failed';
        $this->call('SendMessage', [
            'QueueUrl' => $this->url($failed),
            'MessageBody' => $delivery->envelope->withAttempts($delivery->attempts)->encode(),
            'MessageAttributes' => ['error' => ['DataType' => 'String', 'StringValue' => substr($error, 0, 1000)]],
        ]);
        $this->ack($delivery);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $action, array $body): array
    {
        $request = new Request('POST', $this->endpoint, [
            'Content-Type' => 'application/x-amz-json-1.0',
            'X-Amz-Target' => 'AmazonSQS.' . $action,
        ], json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $response = $this->http->sendRequest($this->signer->sign($request, $this->clock->now()));
        $data = json_decode((string) $response->getBody(), true, 16);
        if ($response->getStatusCode() >= 300) {
            $detail = \is_array($data) ? ($data['message'] ?? $data['Message'] ?? $data['__type'] ?? '') : '';
            $message = \is_scalar($detail) ? (string) $detail : '';
            throw new \RuntimeException("SQS $action failed ({$response->getStatusCode()}): $message");
        }
        $result = [];
        foreach (\is_array($data) ? $data : [] as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function url(string $queue): string
    {
        return $this->queueUrls[$queue] ?? throw new \InvalidArgumentException("No SQS URL for queue \"$queue\". Add it to queue.sqs.queues.");
    }
}
