# Background work: queues, the outbox, the scheduler and webhooks

```php
// app.php
->withDatabase()
->withQueue()      // jobs in keel_jobs, processed by `keel queue:work`
->withWebhooks()   // signed outgoing webhooks, delivered by the queue
```

## Jobs

A job is a typed DTO; its handler is an invokable class:

```php
final readonly class SendReceipt
{
    public function __construct(public string $orderId, public string $email) {}
}

#[Handler(maxAttempts: 10, backoffSeconds: 30, queue: 'mail')]
final readonly class SendReceiptHandler
{
    public function __construct(private Mailer $mailer, private Orders $orders) {}

    public function __invoke(SendReceipt $job): void { /* ... */ }
}

$queue->push(new SendReceipt($order->id, $email));
$queue->push(new SendReceipt($order->id, $email), delaySeconds: 600);
```

- Jobs are serialized with the same generated code as API types and **validated when read**. There is no
  `unserialize()`: a row in the jobs table cannot instantiate an arbitrary class.
- Each job runs in its own scope, like a request: fresh scoped services, the tenant that pushed it, and a
  trace that continues from the code that pushed it.
- A handler that throws is retried with exponential backoff and jitter (`backoffSeconds × 2^(attempt−1)`,
  capped at an hour). After `maxAttempts` the job is kept as `failed`.
- Delivery is **at least once**: write handlers so running twice is harmless.
- A job type has exactly one handler; two is a build error.

## Brokers: Redis Streams, Amazon SQS, RabbitMQ

By default workers poll `keel_jobs`. For higher volume or an existing broker, set a transport:

```php
'queue' => [
    'transport' => 'redis',     // database (default) | redis | sqs | amqp
    'visibility_seconds' => 300,
    'sqs' => ['region' => 'eu-west-1', 'queues' => ['default' => 'https://sqs.eu-west-1.amazonaws.com/123/app', 'webhooks' => '...']],
    'amqp' => ['url' => 'amqp://app:secret@rabbitmq:5672/app'],
],
```

The outbox stays: `push()` still writes to `keel_jobs` inside your transaction. `keel queue:work` relays
committed jobs to the broker (deleting them only after they were published) and consumes from it.
Delivery is at least once on every transport.

| Transport | Delays and retries | Crashed worker | Dead letters | Needs |
| --- | --- | --- | --- | --- |
| `redis` | Sorted set, moved into the stream when due | `XAUTOCLAIM` after `visibility_seconds` | Stream `keel:q:<queue>:failed` | `ext-redis` |
| `sqs` | `DelaySeconds` (max 15 min) and visibility timeout (max 12 h) | Visibility timeout | Queue `<queue>-failed` | Nothing: Keel signs SigV4 itself |
| `amqp` | `keel.<queue>.retry` with per-message TTL dead-lettering back | Unacknowledged messages return | Queue `keel.<queue>.failed` | `php-amqplib/php-amqplib` |

SQS credentials: `queue.sqs.key`/`secret`/`token` or `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and
`AWS_SESSION_TOKEN`; `queue.sqs.endpoint` points at LocalStack or ElasticMQ in development. Keel's SigV4
signer is tested against AWS's published test suite.

## The transactional outbox

With `->withQueue()`, `push()` inserts into `keel_jobs` through the request's `Database`. Inside a
transaction, the job commits or rolls back with your data:

```php
$db->transaction(function () use ($order) {
    $this->save($order);
    $this->queue->push(new SendReceipt($order->id, $order->email));
});
```

"Saved the order but lost the email" and "sent the email for an order that rolled back" both become
impossible, without a message broker.

## Async listeners

Any listener can run in the background:

```php
#[Listener(async: true)]
final readonly class NotifyMerchantOfOrder
{
    public function __invoke(OrderPlaced $event): void { /* ... */ }
}
```

Dispatching `OrderPlaced` runs the synchronous listeners immediately and pushes one job per async listener,
so async listeners are part of the outbox too. The event class must be a concrete, typed DTO (a build error
otherwise).

## Running workers

```bash
keel queue:work                       # every queue a handler uses
keel queue:work --queue=mail,default  # specific queues, in priority order
keel queue:work --once                # process what is ready, then exit
keel queue:failed                     # list failed jobs with their last error
keel queue:retry <id>                 # or `all`
```

Workers stop after the current job on SIGTERM. A worker that dies mid-job leaves a reservation that expires
after `--visibility` seconds (default 300); another worker then takes the job. On PostgreSQL and MySQL 8,
reservation uses `FOR UPDATE SKIP LOCKED`, so many workers never wait for each other.

Without `->withQueue()`, `Queue` is bound to `SyncQueue`, which runs each job immediately. That is fine in
development; the production checks refuse it when the application has handlers.

## Scheduled tasks

```php
#[Schedule('0 3 * * *')]   // 03:00 UTC every day
final readonly class PurgeExpiredCarts
{
    public function __construct(private Carts $carts) {}

    public function __invoke(): void { $this->carts->purgeExpired(); }
}
```

- Five-field cron with lists, ranges and steps, plus `@hourly`, `@daily`, `@weekly`, `@monthly`, `@yearly`.
  Invalid expressions are build errors.
- `keel schedule:work` runs continuously; or call `keel schedule:run` from cron every minute.
- Each task runs once per due minute **across all nodes**: it takes a lock named after the task and the minute
  (`LockStore`: Redis with `->withRedisStores()`, the database with `->withQueue()`, in-memory otherwise, which
  the production checks refuse when tasks exist).
- A task that throws is logged; the others still run.

## Webhooks

```php
$webhooks->send($endpoint->url, $endpoint->secret, 'order.placed', ['orderId' => $order->id]);
```

- Signed in the [Standard Webhooks](https://www.standardwebhooks.com) format (`webhook-id`,
  `webhook-timestamp`, `webhook-signature: v1,...`), so receivers can verify them with existing libraries in any
  language. `WebhookSigner::generateSecret()` creates `whsec_...` secrets; `WebhookSigner::verify()` checks
  incoming webhooks, rejecting timestamps outside 5 minutes.
- Delivered by the queue: up to 8 attempts over about a day, on the `webhooks` queue. Any non-2xx response
  is a failure. The message id is stable across retries, so receivers can deduplicate.
- **Server-side request forgery protection:** customer-supplied URLs must be `https` and must not resolve to
  loopback, private (RFC 1918), link-local (including cloud metadata at 169.254.169.254), CGNAT or
  unique-local IPv6 addresses. The check runs when sending and again before each attempt, because DNS can
  change. `webhooks.allow_http` and `webhooks.unsafe_allow_private_networks` relax it for development.

## Testing

```php
$app->post('/orders', $order, $user)->assertStatus(201);
$app->http()->stub('POST', 'https://merchant.example/hooks', [], 204);
self::assertSame(2, $app->runJobs());               // the async listener, then the webhook it queued
self::assertSame([PurgeExpiredCarts::class], $app->runSchedule(new DateTimeImmutable('2026-01-02T03:00Z')));
```

Jobs run against the frozen clock, so `$app->clock()->advance('+1 hour')` moves retries along.
