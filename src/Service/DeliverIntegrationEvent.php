<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Queue\Handler;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;

/**
 * Posts one CloudEvent to one subscriber with a service token. A non-2xx answer or a network
 * error throws, so the queue retries with backoff; the event id is stable across attempts, so
 * the receiver's Inbox drops duplicates.
 */
#[Handler(maxAttempts: 10, backoffSeconds: 10, queue: 'events')]
final readonly class DeliverIntegrationEvent
{
    public function __construct(private ClientInterface $http, private ServiceTokens $tokens)
    {
    }

    public function __invoke(IntegrationEventDelivery $delivery): void
    {
        $event = json_decode($delivery->body, true);
        $tenant = \is_array($event) && \is_string($event['keeltenant'] ?? null) ? $event['keeltenant'] : null;
        $headers = ['Content-Type' => CloudEvent::MEDIA_TYPE, 'User-Agent' => 'Keel-Events/1.0'];
        $authorization = $this->tokens->authorization($delivery->audience, $tenant);
        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }
        $response = $this->http->sendRequest(new Request('POST', $delivery->url, $headers, $delivery->body));
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Event {$delivery->eventId} ({$delivery->type}) was refused by {$delivery->url} with $status; it will be retried.");
        }
    }
}
