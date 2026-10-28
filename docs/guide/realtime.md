# Real time: server-sent events and WebSockets

Both run on [`keel run`](server.md), where an open connection costs a fiber, not a worker. Both start
from an ordinary endpoint, so authentication, tenancy, scopes and rate limits apply to them like to any
request.

## The hub

`Hub` is publish/subscribe for real-time clients:

```php
$hub->publish("orders.$tenantId", json_encode(['orderId' => $order->id]));
```

- Subscribers live in the process holding the connection.
- With `'realtime' => ['redis' => true]`, every publish also goes through Redis, and every `keel run`
  process relays it to its subscribers. A message published anywhere reaches every client on every node:
  a web request, a queue worker, a scheduled task, a FrankenPHP worker.

## Server-sent events

```php
#[Get('/orders/live')]
#[Authorize(scopes: ['orders:read'])]
final class LiveOrders
{
    public function __invoke(Hub $hub, CurrentTenant $tenant): ResponseInterface
    {
        return EventStream::response(
            fn (EventSink $events) => $hub->stream("orders.{$tenant->id}", $events, 'order.placed'),
        );
    }
}
```

```js
const events = new EventSource('/orders/live', { withCredentials: true });
events.addEventListener('order.placed', (e) => console.log(JSON.parse(e.data)));
```

- `EventSink::send($data, $event, $id)`, `json()`, and `heartbeat()`. `Hub::stream()` forwards a channel
  until the client leaves, with a heartbeat every 15 seconds so proxies keep the stream open.
- A disconnected client raises `ClientDisconnected` inside your producer; it is caught for you.
- The `Last-Event-ID` header is on the request when a browser reconnects.
- Under FrankenPHP or FPM, streams work too (flushed chunk by chunk), but each open stream holds a worker.

## WebSockets

```php
#[Get('/ws/orders')]
#[Authorize(scopes: ['orders:read'])]
final class OrdersWebSocket
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return WebSocket::accept($request, OrdersSocket::class, ['room' => 'lobby']);
    }
}

final readonly class OrdersSocket implements WebSocketHandler
{
    public function __construct(private Orders $orders) {}  // any service, scoped ones included

    public function onOpen(WebSocketConnection $connection): void
    {
        $connection->join('orders.' . $connection->tenantId());
    }

    public function onMessage(WebSocketConnection $connection, string $message, bool $binary): void
    {
        $connection->send('echo: ' . $message);
    }

    public function onClose(WebSocketConnection $connection, int $code, string $reason): void {}
}
```

```js
// Browsers cannot send Authorization on a WebSocket: the token travels as a subprotocol.
const socket = new WebSocket('wss://api.example.com/ws/orders', ['keel', `keel.token.${token}`]);
socket.onmessage = (e) => console.log(e.data);
```

- The handshake is a normal request through the middleware pipeline: authentication (including token
  binding), tenancy and rate limits apply; a 401 or 403 refuses the upgrade.
- The handler runs in a **fresh scope for every event** (open, each message, close), with the caller's
  principal and tenant set. It can depend on the Database, the ORM or anything scoped.
- `WebSocketConnection`: `send()`, `sendBinary()`, `join()`/`leave()` hub channels, `close()`, `principal()`,
  `tenantId()`, `context()`.
- The server implements RFC 6455: masking, fragmentation, ping/pong (a ping every
  `websocket.ping_seconds`, 30; idle connections are closed), close codes, UTF-8 validation of text (1007)
  and a message size limit (`websocket.max_message_bytes`, 1 MiB; 1009). It is tested against Python's
  `websockets` library.
- Without `keel run`, a WebSocket endpoint answers 501.
- Writes to one connection from several fibers (the handler, hub broadcasts) are serialized, so frames never
  interleave.

## Limits

- Authentication happens at the handshake. A token revoked later does not close open connections.
  Close them from your revocation code if that matters (track connection ids per principal).
- There is no per-message compression (permessage-deflate) yet.
