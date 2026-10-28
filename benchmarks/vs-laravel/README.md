# Keel vs Laravel Octane: end-to-end benchmark

Run on 2026-10-28 with Keel 0.1.0-dev (the initial commit, `4f5f67a`), before authentication existed: neither application
authenticated requests. To reproduce, check out that commit. Both applications run under the **same
FrankenPHP binary in worker mode, with 8 workers**,
and serve the same three endpoints against the same schema, first on SQLite, then on PostgreSQL.

## Results on SQLite

`wrk -t2 -c64 -d20s` after a 5 s warm-up. Each server was load-tested alone.

| Scenario | Keel req/s | Laravel req/s | Keel faster by | Keel p50 / p99 | Laravel p50 / p99 |
| --- | ---: | ---: | ---: | --- | --- |
| `GET` health (JSON, no I/O) | **55,841** | 10,635 | 5.3× | 1.08 / 2.88 ms | 5.86 / 10.09 ms |
| `GET` order by id (2 SQL reads) | **41,520** | 9,034 | 4.6× | 1.48 / 2.62 ms | 6.85 / 14.02 ms |
| `POST` invalid order → 422 (validation) | **51,447** | 6,394 | 8.0× | 1.16 / 2.65 ms | 9.86 / 14.31 ms |
| `POST` create order → 201 (validation, transaction, 4 inserts, event + listener) | **3,113** | 1,520 | 2.0× | 11.4 / 741 ms | 30.7 / 591 ms |

Server memory (RSS, whole FrankenPHP process with 8 workers) after the run: Keel 92 MB, Laravel 116 MB.
Data was consistent in both: every order had exactly one invoice (Keel 83,978; Laravel 40,323).

## Results on PostgreSQL

The same servers and load, with both applications on PostgreSQL 14 (`fsync` and `synchronous_commit` on, one
connection per worker, both databases on one local cluster). This removes SQLite's single-writer limit.

| Scenario | Keel req/s | Laravel req/s | Keel faster by | Keel p50 / p99 | Laravel p50 / p99 |
| --- | ---: | ---: | ---: | --- | --- |
| `GET` health (JSON, no I/O) | **56,565** | 12,611 | 4.5× | 1.05 / 2.89 ms | 4.91 / 9.23 ms |
| `GET` order by id (2 SQL reads) | **14,462** | 7,266 | 2.0× | 4.04 / 9.81 ms | 8.51 / 16.31 ms |
| `POST` invalid order → 422 (validation) | **46,172** | 7,378 | 6.3× | 1.26 / 3.74 ms | 8.33 / 16.27 ms |
| `POST` create order → 201 (transaction, 4 inserts, event + listener) | **7,946** | 3,467 | 2.3× | 7.9 / 13–16 ms | 17.8 / 23–411 ms |

The create row is the median of three 20 s rounds per framework, each after `VACUUM ANALYZE` and `CHECKPOINT`
(Keel: 7,838 / 7,980 / 7,946 req/s; Laravel: 3,550 / 3,415 / 3,467 req/s). The p99 range shows run-to-run
variance: before the database was reset between rounds, Keel also saw tail spikes (p99 up to 246 ms) and
Laravel saw them after reset (p99 411 ms during an autovacuum). These spikes came from database background
work on the shared machine, not from either framework; p50 and throughput were stable throughout.

Reads are slower on PostgreSQL than on SQLite for both frameworks because every query is a network round trip
instead of an in-process call, which narrows the gap to 2×. All 1,296,377 Keel orders and 473,493 Laravel
orders had exactly one invoice.

## Environment

- Mac mini, Apple silicon, 10 cores; load generator on the same machine.
- FrankenPHP 1.13.4, PHP 8.5.12 (embedded), opcache on.
- Keel 0.1.0-dev, environment `bench` (debug off, log level warning, rate limiting off).
- Laravel 13.39.0 + Octane 2.x, `APP_ENV=production`, `APP_DEBUG=false`, `php artisan optimize`,
  optimized autoloader, `--max-requests` high enough to never recycle during the run.
- SQLite with WAL and a 5 s busy timeout in both, then PostgreSQL 14.17 (`shared_buffers=256MB`).

## Read this before quoting the numbers

- **The SQLite `create` row is limited by SQLite**, which allows one writer at a time; its p99 latency is lock
  waiting, and Keel had 3 socket timeouts (waits over 2 s). Use the PostgreSQL results for writes.
- **Laravel was given the cheaper path**: the query builder, not Eloquent, and the minimal `api` middleware
  group. Keel ran its full default stack: request ids, a trace span and RED metrics per request, security
  headers, load shedding and the idempotency check.
- **Validation messages differ in shape**: Keel returns RFC 9457 problem details with every violation's
  path; Laravel returns its standard `{message, errors}` body.
- The load generator shares the CPU with the server; absolute numbers will be higher on separate machines.
  The ratios are the useful part.
- Measured on PHP 8.5; Keel's test suite has so far only been run on PHP 8.3.

## Reproduce

1. Download FrankenPHP (`curl https://frankenphp.dev/install.sh | sh`) and `brew install wrk`.
2. Keel: copy the repository to a local disk, `composer install -o`, then in `examples/shop` add
   `'rate_limit' => ['enabled' => $env->name !== 'bench']` to `config.php`, run
   `KEEL_ENV=bench ../../bin/keel migrate`, `sqlite3 var/shop.sqlite 'PRAGMA journal_mode=WAL;'`,
   `KEEL_ENV=bench ../../bin/keel build --env=bench` and `frankenphp run --config keel.Caddyfile`.
3. Laravel: `composer create-project laravel/laravel`, `composer require laravel/octane`, copy the files in
   `laravel/` (controller, event, listener, `routes/api.php`, migration), register `api:` routing in
   `bootstrap/app.php`, set the `.env` and SQLite options above, `php artisan migrate --force`,
   `php artisan optimize`, then
   `php artisan octane:frankenphp --workers=8 --max-requests=10000000 --port=8090`.
4. For PostgreSQL: set `DATABASE_DSN` in the worker `env` of `keel.Caddyfile` (for example
   `pgsql:host=127.0.0.1;port=5432;dbname=keel;user=bench`) and the `DB_*` variables in Laravel's `.env`, then
   migrate both again.
5. Create one order on each server and pass its id to
   `./run.sh <name> <health-url> <order-url-prefix> <post-url> <order-id>`.
