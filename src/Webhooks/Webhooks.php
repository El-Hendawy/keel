<?php

declare(strict_types=1);

namespace Keel\Webhooks;

use Keel\Container\Attribute\Scoped;
use Keel\Http\Responses;
use Keel\Queue\Queue;
use Keel\Support\IdGenerator;

/**
 * Sends signed webhooks to your customers' endpoints through the queue.
 *
 *     $webhooks->send($endpoint->url, $endpoint->secret, 'order.placed', ['orderId' => $order->id]);
 *
 * The URL is checked against private networks before enqueueing and again before each
 * attempt. The message id is stable across retries, so receivers can deduplicate.
 */
#[Scoped]
final readonly class Webhooks
{
    public function __construct(private Queue $queue, private IdGenerator $ids, private UrlGuard $guard)
    {
    }

    /**
     * @param array<string, mixed>|\JsonSerializable $data
     * @return string the message id
     */
    public function send(string $url, string $secret, string $type, array|\JsonSerializable $data): string
    {
        $this->guard->check($url);
        $id = 'msg_' . $this->ids->generate();
        $body = json_encode(['type' => $type, 'data' => $data], Responses::JSON_FLAGS);
        $this->queue->push(new WebhookDelivery($id, $url, $secret, $type, $body));

        return $id;
    }
}
