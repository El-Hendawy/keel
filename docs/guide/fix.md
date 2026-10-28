# FIX protocol

Keel speaks [FIX](https://www.fixtrading.org/standards/) (Financial Information eXchange), the tag=value
protocol trading systems use for orders and executions, natively: a session layer, typed messages compiled at
build time, and an engine that accepts and initiates sessions.

```bash
keel fix:run                      # every session in config fix.sessions
keel fix:run --session=broker     # some of them
```

See `examples/fix-gateway` for a complete venue that accepts orders and answers with execution reports.

## Sessions

```php
// config.php
'fix' => [
    'listen_host' => '127.0.0.1',            // acceptors bind here; widen deliberately
    'store' => __DIR__ . '/var/fix',         // sequence numbers and sent messages, per session
    'sessions' => [
        'client-acme' => [                   // ACME connects to us
            'role' => 'acceptor', 'port' => 9878, 'begin_string' => 'FIX.4.4',
            'sender' => 'VENUE', 'target' => 'ACME', 'heartbeat' => 30,
            'password' => getenv('FIX_ACME_PASSWORD'),
        ],
        'broker' => [                        // we connect to a broker
            'role' => 'initiator', 'host' => 'fix.broker.example', 'port' => 9443,
            'begin_string' => 'FIXT.1.1', 'default_appl_ver_id' => '9',
            'sender' => 'VENUE', 'target' => 'BROKER', 'heartbeat' => 30,
            'username' => 'venue', 'password' => getenv('FIX_BROKER_PASSWORD'),
        ],
    ],
],
```

The session layer implements:

- **Logon** with HeartBtInt, `ResetSeqNumFlag`, and Username/Password (553/554), checked with constant-time
  comparison on acceptors; an acceptor identifies the session from the CompIDs of the first message.
- **Sequence numbers** persisted per session (`fix.store`), so a restart resumes where it left off.
- **Gap recovery:** a message ahead of the expected sequence number triggers a ResendRequest; resent
  application messages carry PossDupFlag and OrigSendingTime; administrative messages and gaps are filled with
  SequenceReset-GapFill. A sequence number that is too low without PossDupFlag ends the session, as the
  standard requires.
- **Supervision:** Heartbeats after HeartBtInt of silence, a TestRequest after 1.2× without input, and a
  disconnect after 2.4×. Initiators reconnect with a 5-second backoff.
- **Validation:** BeginString, CompIDs, SendingTime accuracy (`max_clock_skew_seconds`, 120), BodyLength and
  CheckSum. Garbled messages are discarded; header problems produce a session Reject (35=3) with
  SessionRejectReason.
- **Shutdown:** SIGTERM logs every session out, then stops.

TLS is terminated in front of the engine (stunnel, HAProxy), as most venues expect.

## Typed messages

```php
#[FixMessage('D')]
final readonly class NewOrderSingle
{
    /** @param list<Party> $parties */
    public function __construct(
        #[Field(11)] public string $clOrdId,
        #[Field(55)] public string $symbol,
        #[Field(54)] public Side $side,                     // enum Side: string { case Buy = '1'; ... }
        #[Field(38)] public int $orderQty,
        #[Field(40)] public OrdType $ordType,
        #[Field(60)] public \DateTimeImmutable $transactTime, // UTCTimestamp
        #[Field(44)] public ?string $price = null,           // strings keep decimals exact
        #[Group(count: 453, of: Party::class)] public array $parties = [],
    ) {}
}
```

- Field types: `string`, `int`, `float`, `bool` (Y/N), `DateTimeImmutable` (UTCTimestamp) and backed enums.
- Repeating groups are lists of component classes; the component's first field delimits instances, and
  groups can nest.
- A non-nullable field without a default is required: a message without it is rejected with
  SessionRejectReason 1 and RefTagID. A malformed value gets reason 6, and a value outside an enum gets
  reason 5.
- Encoders and decoders are generated (`Fix.php` in the build), checked by PHPStan like all generated code.
  Session-level types (0–5, A) are reserved for the engine.

## Handlers

```php
#[FixHandler]
final readonly class AcceptOrder
{
    public function __construct(private OrderBook $book) {}

    public function __invoke(NewOrderSingle $order, FixContext $fix): void
    {
        $fix->reply(new ExecutionReport(/* ... */));          // same session
        $fix->send('broker', $this->book->route($order));     // another session of this process
    }
}
```

Each inbound application message runs in its own scope, like an HTTP request. A message type without a handler
gets a BusinessMessageReject (35=j, reason 3). A handler that throws is logged, and the counterparty gets a
BusinessMessageReject (reason 0) instead of silence.

`FixGateway` sends typed or raw (`Message::of('AE', [...])`) messages from any service in the FIX process.

## Testing

`Session` is a state machine independent of sockets: feed it messages and a clock (see `tests/Fix/FixTest.php`).
`Engine::run(seconds)` runs a bounded slice of the reactor, so a test can run an acceptor and an initiator in
one process over real TCP. The wire format is checked against the published FIX example message (its
BodyLength and CheckSum).

## Limits

- One engine process per set of sessions (sequence numbers are owned by one process); scale by splitting
  sessions across processes.
- No native TLS, FIXP/SBE or FAST: tag=value over TCP, FIX 4.0–4.4 and FIXT 1.1.
- No data-dictionary validation beyond your typed messages; a field you do not declare is ignored.
