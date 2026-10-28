# The Keel server

```bash
keel run --host=0.0.0.0 --port=8080 --workers=8
```

`keel run` is Keel's own HTTP server. FrankenPHP and RoadRunner give each request a whole worker
while it waits on the database. `keel run` instead serves **many requests at once in each process**,
on an event loop with one fiber per connection. While one request waits on PostgreSQL, Redis or an HTTP
call, the others run.

| | FrankenPHP / RoadRunner workers | `keel run` |
| --- | --- | --- |
| Requests per process | One at a time | Many: a fiber per connection |
| Waiting on the database | The worker is blocked | Only that request's fiber waits (with the `pgsql-async` driver) |
| Concurrency is set by | The number of workers (threads, memory) | The connection pool size (`database.pool_size`) |
| WebSockets, server-sent events | SSE holds a worker per open stream; no WebSockets | Thousands of open connections per process |
| Shared state | Per worker | Per process, shared by all its requests |
| HTTPS, HTTP/2, HTTP/3 | Built in (Caddy) | Put Caddy, nginx or a load balancer in front |

Both run the same application: nothing in your code changes.

## How it works

- A supervisor opens the listening socket and starts `--workers` processes (default: one per CPU) that
  share it. A process that crashes is restarted. `SIGTERM` stops accepting, lets in-flight requests and
  streams finish (`server.shutdown_grace_seconds`, 30), closes WebSockets with code 1001, and exits.
- Each process runs an event loop. Every connection is a fiber. HTTP/1.1 keep-alive, chunked request and
  response bodies, `Expect: 100-continue`, and limits on header size, body size and slow clients (408)
  are handled by the server.
- Everything request-scoped stays per request: the container opens a scope per request exactly as in a
  worker. **Singletons are shared by all requests of the process**: that is the shared-memory model.
  A `#[Singleton]` cache or counter is visible to every concurrent request in the process.
- `KEEL_MAX_REQUESTS` (or `server.max_requests`) recycles a process after that many requests.

## Non-blocking database

Use the `pgsql-async:` DSN to make PostgreSQL queries non-blocking:

```php
'database' => [
    'dsn' => 'pgsql-async:host=db;port=5432;dbname=app;user=app',
    'pool_size' => 16,          // connections per process; 8 by default under keel run
    'pool_timeout_ms' => 5000,  // how long a request waits for a free connection
],
```

- Each request leases its own connection from the pool, so concurrent requests never share a session or a
  transaction. A connection returned while still in a transaction is rolled back.
- Statements are prepared once per connection. Values come back typed: integers, booleans and floats as
  PHP scalars, numerics as strings.
- The same DSN works under FrankenPHP and in tests (it then simply waits), so one configuration serves
  every runtime. The whole test suite runs against it.
- Other databases use PDO, which blocks the process while a query runs. Under `keel run` they still work
  correctly (each request gets its own connection), but they do not overlap.

Redis pub/sub for the real-time hub, HTTP calls (`ClientInterface`) and timers (`delay()`) are non-blocking
too.

## Shared memory

- **Within a process:** singletons. `InMemoryCache` under `keel run` is one cache shared by all requests
  of the process.
- **Across processes and nodes:** Redis (`->withRedisStores()`), as before.

Per-process state is lost when the process recycles. Keep anything that must survive in Redis or the
database.

## Deployment

Run it behind a proxy that terminates TLS and speaks HTTP/2 and HTTP/3 to clients:

```caddyfile
api.example.com {
	reverse_proxy 127.0.0.1:8080
}
```

```php
// app.php: the proxy's address is trusted for X-Forwarded-For
->trustProxy('127.0.0.1')
```

`keel run` binds to `127.0.0.1` unless you pass `--host`. Each process can hold `server.max_connections`
(900) connections; beyond that new connections get a 503. `stream_select` limits a process to about 1,000
sockets, so scale connections with `--workers`.

## Benchmark

`wrk -t4 -c<n> -d10s` on an Apple silicon Mac mini (10 cores), PHP 8.3, PostgreSQL 14 on the same machine.
`/db/10` runs `SELECT pg_sleep(0.01)`: a 10 ms query, standing in for real database latency.

| Server | `/health/live` | `/db/10`, 128 connections | `/db/10`, 512 connections | Memory |
| --- | ---: | ---: | ---: | ---: |
| FrankenPHP, 8 workers (PDO) | 51,985 req/s | 698 req/s | | |
| FrankenPHP, 64 workers (PDO) | | | 5,734 req/s | 212 MB |
| `keel run`, 8 processes, pool 8 (async) | 100,478 req/s | 3,164 req/s | 5,822 req/s | 242 MB |
| `keel run`, 8 processes, pool 24 (async) | | | 17,027 req/s | 244 MB |

What this shows:

- Without I/O, Keel's own server is about twice as fast as going through Caddy and FrankenPHP.
- With blocking I/O, throughput is bounded by how many requests can wait at once. FrankenPHP gets there
  with more workers (64 workers ≈ 64 concurrent queries); `keel run` gets there with a bigger pool and the
  same memory. FrankenPHP with ~200 workers was not measured.
- The load generator shared the machine, so treat the ratios, not the absolute numbers, as the result.
  Reproduce with `tests/Fixtures/Realtime` (`/db/{ms}`, `/slow/{ms}`).
