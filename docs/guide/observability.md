# Observability

Everything here is on by default.

## Logs

One JSON object per line on stderr, with `trace_id` and `span_id` when a span is active:

```json
{"time":"2026-10-28T14:59:47.115+00:00","level":"info","message":"POST /orders 201 3.1ms","service":"shop","trace_id":"08d2…","span_id":"250e…","context":{"method":"POST","route":"/orders","status":201,"duration_ms":3.1,"request_id":"01a1…","client_ip":"10.0.0.7"}}
```

Config: `log.level` (default `info`; `warning` in tests), `log.stream` (default `php://stderr`). Inject
`Psr\Log\LoggerInterface`.

## Traces

- A server span per request (`GET /orders/{id}`), continuing an incoming W3C `traceparent`.
- A client span per outbound HTTP call, with `traceparent` propagated.
- A span per SQL statement (`db.query`, with `db.query.text`).
- Your own: `$tracer->trace('pricing.quote', fn (Span $span) => ..., ['tenant' => $id])`.

Export with `->withOtlpTracing()` in `app.php` and `tracing.otlp_endpoint` (default
`http://127.0.0.1:4318/v1/traces`). Spans are exported after the response is sent. `tracing.sample_ratio`
samples new traces; incoming sampling decisions are honoured.

## Metrics

`GET /metrics` in Prometheus text format. Built in:

| Metric | Labels |
| --- | --- |
| `http_requests_total` | `method`, `route`, `status`, `worker` |
| `http_request_duration_seconds` (histogram) | `method`, `route`, `worker` |
| `http_client_requests_total` | `host`, `outcome` (`ok`, `4xx`, `5xx`, `error`, `circuit_open`, `bulkhead_full`) |
| `http_client_duration_seconds` (histogram) | `host` |

Add your own with `Metrics::increment()` and `Metrics::observe()`. Each worker reports its own series; sum
across `worker` in queries. Protect the endpoint with `metrics.token` (required in production by the boot
check) or disable it with `metrics.enabled = false`.

## Health

- `GET /health/live`: the worker is up (uptime, requests handled).
- `GET /health/ready`: runs every service implementing `HealthCheck` (the database adds one automatically)
  and returns 503 while a check fails or the worker is draining after SIGTERM.
