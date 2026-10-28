# Tooling

Install the command once, globally:

```bash
composer global require el-hendawy/keel
export PATH="$(composer global config bin-dir --absolute):$PATH"   # add to your shell profile
```

Inside a project, `keel` runs that project's own version (`vendor/bin/keel`), so each project uses the Keel
it locked. `vendor/bin/keel` works too.

Every command runs from the application root (where `app.php` is). `--env=<name>` selects the environment
(default `KEEL_ENV` or `dev`); `--app=<file>` another application file.

## Running

| Command | What it does |
| --- | --- |
| `keel serve` | Development server (PHP's built-in server); rebuilds on every change |
| `keel run [--host=127.0.0.1] [--port=8080] [--workers=N]` | The Keel server: many requests per process, WebSockets and SSE ([guide](server.md)) |

## Building and inspecting

| Command | What it does |
| --- | --- |
| `keel build --env=prod` | Compiles the application into `var/build/<env>/`. Run it in your deploy pipeline. |
| `keel check --env=prod` | Runs the production boot checks without serving traffic |
| `keel routes` | Lists routes |
| `keel inspect` | Prints `metadata.json`: routes (with auth), services, modules, listeners, jobs, schedule, config keys |
| `keel openapi` | Prints the OpenAPI 3.1 document |

## Generating code

```bash
keel make:endpoint Orders/CancelOrder --method=POST --path=/orders/{id}/cancel --scope=orders:write
keel make:migration "add refunds"
```

`make:endpoint` writes the endpoint, its input (for POST/PUT/PATCH) and its result class into the namespace
from `composer.json`, with `#[Authorize]` when you pass `--scope`. The endpoint returns 501 until you
implement it. Nothing is overwritten.

## Client SDK

```bash
keel sdk --out=web/src/api/client.ts
```

Writes a dependency-free TypeScript client from the OpenAPI document: one interface per schema and one typed
method per route, named after the route name (`orders.create` becomes `ordersCreate`). Errors throw `ApiError`
with the RFC 9457 problem document, including validation errors with their paths.

```ts
import { createClient, ApiError } from './api/client';

const api = createClient({ baseUrl: 'https://api.example.com', token: () => session.token });
try {
  const order = await api.ordersCreate({ merchantId, items });
} catch (e) {
  if (e instanceof ApiError && e.status === 422) showErrors(e.problem.errors);
}
```

Regenerate it in CI and fail the build if it changed without being committed, so the frontend can never drift
from the API.

## Database

| Command | What it does |
| --- | --- |
| `keel migrate` | Applies the framework's migrations for the features you enabled (`keel_tokens`, `keel_jobs`, `keel_locks`), then `./migrations` |

## ORM

| Command | What it does |
| --- | --- |
| `keel orm:schema` | Prints CREATE TABLE and CREATE INDEX for the `#[Entity]` classes, in the connected database's dialect |
| `keel orm:diff [--write]` | Prints what the database lacks for the entities (forward-only), or writes it as a migration |

## FIX

| Command | What it does |
| --- | --- |
| `keel fix:run [--session=a,b]` | Runs the FIX engine for the sessions in `fix.sessions` |

## Auth

| Command | What it does |
| --- | --- |
| `keel key:generate` | Prints a new `app.key` (`base64:...`) |
| `keel token:issue --principal=ID [--tenant=ID] [--scopes=a,b] [--kind=api_key] [--ttl=0] [--name=..]` | Issues a token, bound to the first client that uses it. Shown once. |
| `keel token:revoke <id>` | Revokes a token |

## Background work

| Command | What it does |
| --- | --- |
| `keel queue:work [--queue=a,b] [--once] [--max-jobs=N] [--visibility=300]` | Processes jobs |
| `keel queue:failed` | Lists failed jobs |
| `keel queue:retry <id\|all>` | Re-queues failed jobs |
| `keel schedule:work` | Runs scheduled tasks continuously |
| `keel schedule:run` | Runs the tasks due this minute (for cron) |
