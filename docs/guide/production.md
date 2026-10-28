# Production

## Build in the pipeline

```bash
composer install --no-dev --classmap-authoritative
KEEL_ENV=prod vendor/bin/keel build --env=prod
KEEL_ENV=prod vendor/bin/keel check --env=prod
KEEL_ENV=prod vendor/bin/keel migrate
```

Set `app.key` from a secret (e.g. `APP_KEY`), generated once with `keel key:generate`. It derives the keys
for token fingerprints, signed cursors and Keel-issued JWTs. Rotating it invalidates cursors and Keel JWTs and
forces re-binding of fingerprints, so rotate deliberately.

In production the runtime never compiles: a missing build is a boot error.

## Run workers

FrankenPHP (worker mode):

```caddyfile
{
	frankenphp {
		worker {
			file ./public/index.php
			num 8
			env KEEL_ENV prod
		}
	}
}

:8080 {
	root * public/
	php_server
}
```

RoadRunner: `composer require spiral/roadrunner-http` and point `.rr.yaml`'s `server.command` at
`php public/index.php`. FPM works unchanged as a fallback.

Worker recycling: `KEEL_MAX_REQUESTS` (default 1000) and `KEEL_MAX_MEMORY_MB` (default 256). On SIGTERM the
worker finishes the request in flight, readiness starts failing, and the worker exits.

## Production boot checks

A production worker refuses to start when:

- debug is on (stack traces would leak);
- rate limits, idempotency keys or auth tokens use in-memory stores (they would differ per node);
- the application has job handlers but no real queue, or scheduled tasks but no shared lock store;
- `trustProxy()` trusts every address;
- `/metrics` is enabled without `metrics.token`;
- `app.key` is missing or shorter than 32 bytes.

Use Redis-backed stores in production:

```php
->bindIn('prod', RateLimitStore::class, RedisRateLimitStore::class)
->bindIn('prod', IdempotencyStore::class, RedisIdempotencyStore::class)
```

with `redis.url` in config.

## Background processes

Run these next to the web workers, each as its own supervised process (systemd, a Kubernetes Deployment):

```bash
KEEL_ENV=prod vendor/bin/keel queue:work          # as many replicas as your job volume needs
KEEL_ENV=prod vendor/bin/keel schedule:work       # one or more; each task still runs once per minute
```

Both stop cleanly on SIGTERM. `KEEL_MAX_REQUESTS` and `KEEL_MAX_MEMORY_MB` do not apply to them; restart
workers on deploy so they load the new build.

## Behind a load balancer

- `->trustProxy('10.0.0.0/8')` to accept `X-Forwarded-For` from your proxies only.
- To add a TLS fingerprint to token binding, have the proxy that terminates TLS send the client's JA4 in
  `X-JA4-Fingerprint` (Keel only reads it from trusted proxies). See [auth](auth.md#token-binding).
- Send `X-Request-Start` from the proxy to enable queue-time load shedding
  (`load_shedding.max_queue_ms`, default 2000).
- Send `X-Request-Id` and `traceparent` to correlate with edge logs and traces.

## Defaults you can tune

| Key | Default |
| --- | --- |
| `rate_limit.enabled` / `.capacity` / `.per_seconds` / `.exempt` | on / 120 / 60 / `/health/`, `/metrics` |
| `idempotency.ttl_seconds` | 86400 |
| `http.max_body_bytes` | 1 MiB |
| `security.hsts` | off (turn on when TLS terminates in front of you) |
| `http_client.timeout_ms` / `.connect_timeout_ms` | 10000 / 2000 |
| `http_client.breaker_threshold` / `.breaker_cooldown_seconds` / `.max_concurrent_per_host` | 5 / 30 / 20 |

`keel inspect` lists every key your application actually reads.
