<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Scoped;
use Keel\Queue\Queue;
use Keel\Support\IdGenerator;
use Keel\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Publishes events to other services as CloudEvents, through the transactional outbox.
 *
 *     'events' => [
 *         'source' => 'https://orders.example.com',
 *         'subscribers' => [
 *             'order.placed' => ['billing', 'https://analytics.internal/events'],   // a service name or a URL
 *             '*' => ['audit'],
 *         ],
 *     ],
 *
 *     $db->transaction(function () use ($order) {
 *         $this->orders->save($order);
 *         $this->events->publish('order.placed', ['orderId' => $order->id], subject: $order->id);
 *     });
 *
 * Published inside a transaction, an event is delivered if and only if the transaction commits
 * (one queued job per subscriber), at least once; receivers deduplicate with Inbox. A service
 * subscriber receives at `services.<name>.url` + `services.<name>.events_path` (default /events).
 */
#[Scoped]
final readonly class IntegrationEvents
{
    /**
     * @param array<mixed> $subscribers
     * @param array<mixed> $services
     */
    public function __construct(
        private Queue $queue,
        private IdGenerator $ids,
        private ClockInterface $clock,
        private TenantContext $tenant,
        #[Config('events.source')] private string $source = '',
        #[Config('events.subscribers')] private array $subscribers = [],
        #[Config('services')] private array $services = [],
        #[Config('service.name')] private string $name = 'app',
    ) {
    }

    /**
     * @param array<mixed>|\JsonSerializable $data
     * @return string the event id
     */
    public function publish(string $type, array|\JsonSerializable $data, ?string $subject = null): string
    {
        $id = $this->ids->generate();
        $payload = $data instanceof \JsonSerializable ? $data->jsonSerialize() : $data;
        $event = (new CloudEvent(
            $id,
            $this->source !== '' ? $this->source : 'urn:keel:' . $this->name,
            $type,
            \is_array($payload) ? $payload : ['value' => $payload],
            $subject,
            $this->clock->now()->format('Y-m-d\TH:i:s.vP'),
        ))->jsonSerialize();
        $tenant = $this->tenant->current();
        if ($tenant !== null) {
            $event['keeltenant'] = $tenant; // a CloudEvents extension attribute: lowercase letters only
        }
        $body = json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        foreach ($this->targets($type) as [$url, $audience]) {
            $this->queue->push(new IntegrationEventDelivery($id, $type, $url, $audience, $body));
        }

        return $id;
    }

    /** @return list<array{0: string, 1: string}> url and audience per subscriber */
    private function targets(string $type): array
    {
        $names = [];
        foreach ([$type, '*'] as $key) {
            $list = $this->subscribers[$key] ?? [];
            foreach (\is_array($list) ? $list : [$list] as $subscriber) {
                if (\is_string($subscriber)) {
                    $names[$subscriber] = true;
                }
            }
        }
        $targets = [];
        foreach (array_keys($names) as $subscriber) {
            if (str_starts_with($subscriber, 'http://') || str_starts_with($subscriber, 'https://')) {
                $targets[] = [$subscriber, (string) parse_url($subscriber, \PHP_URL_HOST)];
                continue;
            }
            $service = $this->services[$subscriber] ?? null;
            if (!\is_array($service) || !\is_string($service['url'] ?? null)) {
                throw new \InvalidArgumentException("events.subscribers names \"$subscriber\", which has no services.$subscriber.url.");
            }
            $path = \is_string($service['events_path'] ?? null) ? $service['events_path'] : '/events';
            $targets[] = [rtrim($service['url'], '/') . $path, \is_string($service['audience'] ?? null) ? $service['audience'] : $subscriber];
        }

        return $targets;
    }
}
