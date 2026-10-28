# Microservices

Keel services are small, start fast, and carry what a distributed system needs on every call: who is
calling and for which tenant, the trace, the request id, and how long the caller will wait.

```php
// app.php
->withDatabase()
->withQueue()
->withAuth()
->withServices()     // after ->withDatabase(), so the inbox is included
```

## Calling another service

```php
// config.php
'service' => ['name' => 'orders'],
'services' => [
    'billing' => ['url' => 'http://billing.internal', 'scopes' => ['invoices:write']],
    'stock' => ['url' => 'http://stock.internal'],
],
```

```php
public function __construct(private Services $services) {}

$invoice = $this->services->post('billing', '/invoices', ['orderId' => $order->id]);

// Several at once, concurrently under `keel run`:
[$stock, $price] = await_all(
    fn () => $this->services->get('stock', "/items/$sku"),
    fn () => $this->services->get('pricing', "/prices/$sku"),
);
```

Every call carries, with nothing at the call site:

| Header | What it carries |
| --- | --- |
| `Authorization` | A service token for the target (below), with the current tenant inside it |
| `traceparent` | The W3C trace, so one trace spans every service |
| `X-Request-Id` | The incoming request id, so logs join up |
| `Keel-Timeout-Ms` | The time the caller has left |

A non-2xx answer throws `ServiceCallFailed` (rendered as 502, or 504 for deadlines) with the upstream status
and problem document; `send()` returns the raw response instead. Calls go through the default HTTP client:
a circuit breaker and bulkhead per host, RED metrics, client spans.

## Service identity

| `service.auth` | Token |
| --- | --- |
| `jwt` (default) | A short-lived JWT signed with this service's `auth.jwt` key: subject `service:<name>`, kind `service`, audience the target, the current tenant. Share the public key (RS256/ES256) with the services that verify it. |
| `client_credentials` | An OAuth 2.0 access token from your identity provider (`service.token_url`, `client_id`, `client_secret`), cached per audience |
| `none` | No token, when a service mesh authenticates with mTLS |

The receiving service checks it like any token: `auth.jwt.audience` must be its own name, and
`#[Authorize(scopes: [...])]` applies. `Principal::$kind` is `service`. Tenants never come from headers: in
`jwt` mode the tenant travels inside the signed token.

## Deadlines

A caller that has given up should not keep five services busy. `DeadlineMiddleware` reads `Keel-Timeout-Ms`
(or gRPC's `grpc-timeout`), records the deadline in `RequestContext::$deadline`, and answers 504 at once when
it has already passed. `Services` passes the remaining time on, and refuses to call after the deadline.
`services.max_timeout_ms` (60 000) caps what callers can ask for.

## Events between services

```php
'events' => [
    'source' => 'https://orders.example.com',
    'subscribers' => [
        'order.placed' => ['billing', 'https://analytics.internal/events'],   // a service name or a URL
        '*' => ['audit'],
    ],
],
```

```php
$db->transaction(function () use ($order) {
    $this->orders->save($order);
    $this->events->publish('order.placed', ['orderId' => $order->id], subject: $order->id);
});
```

- Events are [CloudEvents 1.0](https://cloudevents.io), so Knative, Dapr, Event Grid and Eventarc
  understand them.
- `publish()` writes one job per subscriber through the transactional outbox: the event is delivered if and
  only if the transaction commits. The queue posts it with a service token and retries with backoff;
  with a [broker transport](background-work.md#brokers-redis-streams-amazon-sqs-rabbitmq) the jobs go through
  Redis Streams, SQS or RabbitMQ.
- A service subscriber receives at `services.<name>.url` + `events_path` (default `/events`).

On the receiving side, `Inbox` turns at-least-once delivery into exactly-once handling:

```php
#[Post('/events')]
#[Authorize(scopes: ['events:publish'])]
final class ReceiveEvents
{
    public function __invoke(ServerRequestInterface $request, Inbox $inbox, Invoices $invoices): ResponseInterface
    {
        $event = CloudEvent::fromRequest($request);       // structured or binary mode
        $inbox->once($event, fn () => match ($event->type) {
            'order.placed' => $invoices->open($event->data['orderId']),
            default => null,
        });

        return Responses::empty(202);
    }
}
```

The event id is recorded in `keel_inbox` in the same transaction as the work: a redelivery finds it done.

## Deploying

```bash
keel deploy:docker
keel deploy:kubernetes --name=orders --image=registry.example.com/orders:1.0
```

Each service is one image running `keel run`: one process per CPU, many requests per process, graceful
drain on SIGTERM. The generated Kubernetes manifest wires the probes to `/health/live` and `/health/ready`
(readiness fails while draining or when a dependency is down), adds a pre-stop pause, an autoscaler and a
disruption budget. Configuration comes from the environment and a Secret. See [production](production.md).

## Checklist

- One database per service; share data through calls and events, not tables.
- Make handlers idempotent (`Idempotency-Key` for calls, `Inbox` for events).
- Put a deadline on user-facing requests at the edge (`Keel-Timeout-Ms`); the rest follows.
- Version APIs additively: OpenAPI is generated from your types, and `keel sdk` writes clients for the
  services that call you.
