<?php

declare(strict_types=1);

namespace Keel\Webhooks;

use Keel\Queue\Handler;
use Nyholm\Psr7\Request;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Delivers one webhook. Any non-2xx response or network error throws, so the queue
 * retries with backoff (up to 8 attempts, spread over about a day).
 */
#[Handler(maxAttempts: 8, backoffSeconds: 30, queue: 'webhooks')]
final readonly class DeliverWebhook
{
    public function __construct(
        private ClientInterface $http,
        private UrlGuard $guard,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WebhookDelivery $delivery): void
    {
        // Checked on every attempt: DNS can change between enqueue and delivery.
        $this->guard->check($delivery->url);
        $timestamp = $this->clock->now()->getTimestamp();
        $request = new Request('POST', $delivery->url, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Keel-Webhooks/1.0',
            'webhook-id' => $delivery->id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => WebhookSigner::sign($delivery->id, $timestamp, $delivery->body, $delivery->secret),
            'webhook-type' => $delivery->type,
        ], $delivery->body);
        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new WebhookFailed("Webhook {$delivery->id} to {$delivery->url} got HTTP $status.");
        }
    }
}
