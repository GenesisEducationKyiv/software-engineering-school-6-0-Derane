# 1. Adopt FrankenPHP in worker mode for the HTTP runtime

- **Status:** Accepted
- **Date:** 2026-04-12
- **Scope:** HTTP entrypoint only. gRPC (RoadRunner) and the scanner CLI are unaffected.

## Context

The HTTP API ran under PHP's built-in dev server (`php -S`). Two problems:

1. **Not production-grade.** The PHP manual explicitly marks `php -S` as a
   development aid: single-threaded by default, no HTTP/2, no TLS, no proper
   process supervision.
2. **Cold bootstrap per request.** `public/index.php` re-ran autoload, dotenv,
   container construction, route registration and the migration check on
   every request — none of which is request-scoped.

The rest of the stack already runs as long-lived workers (RoadRunner for gRPC,
a CLI loop for the scanner), so the HTTP path was both the slowest and the
inconsistent one. We needed a production HTTP runtime without adding extra
processes or config files.

## Decision

Adopt **FrankenPHP in worker mode**, replacing `php -S`.

- Base image: `dunglas/frankenphp:1-php8.4` (was `php:8.4-cli`).
- One `Caddyfile` pointing Caddy at `bin/worker.php` (`php_server { worker /app/bin/worker.php }`).
- Slim bootstrap extracted from `public/index.php` into `config/app.php` so it
  runs **once per worker**, not per request.
- `bin/worker.php` loops on `frankenphp_handle_request()` and calls
  `$app->run()` on the already-built Slim app.
- `public/index.php` stays as a thin shim for non-worker SAPIs (CLI, tests).
- `install-php-extensions` replaces the old `docker-php-ext-install` / `pecl`
  mix.

Worker count is left at the FrankenPHP default of 2 per CPU. That means
bootstrap (and `RUN_MIGRATIONS_ON_BOOT`) runs N times per container start —
safe today because migrations sit behind a Postgres advisory lock.

## Alternatives considered

- **php-fpm + nginx/Caddy** — still pays per-request bootstrap; two processes
  and two config files to supervise.
- **RoadRunner for HTTP** — would unify the worker model with gRPC, but Slim
  doesn't fit RoadRunner's PSR-7 worker loop without an adapter.
- **OpenSwoole / Swoole** — intrusive (event loop, coroutine-aware libs);
  conflicts with sync PDO/Redis already in the codebase.
- **`php -S` + reverse proxy in front** — addresses TLS only, not the
  bootstrap cost or the "not for production" warning.

## Consequences

### Positive

- **Lower per-request latency.** Container, routes and DI are built once per
  worker; requests only run dispatch + handler.
- **No web/PHP network hop.** PHP runs as an embedded SAPI inside Caddy — no
  FastCGI, no socket between layers.
- **Single binary, single config.** Caddy + PHP in one process configured by
  one `Caddyfile`. Production HTTP/2, HTTP/3 and automatic TLS available out
  of the box.
- **Architectural consistency.** All three runtimes (HTTP, gRPC, scanner) are
  now long-lived processes.

### Negative

- **Worker-mode state leakage.** PHP-DI caches every resolved entry, so every
  service in `config/container.php` is effectively a singleton for the
  worker's lifetime. Concrete traps already present in the codebase:
  - `PDO` and `Predis\Client` are reused across requests. PDO has no auto
    reconnect (dropped connection ⇒ failed requests until the worker
    recycles); Predis auto-reconnects on `CommunicationException`.
  - `RedisGitHubCache::$readFailureLogged` / `$writeFailureLogged` suppress
    duplicate logs — but under worker mode they persist across requests, so
    subsequent failures get silently swallowed.
  - Any new service that mutates instance state inherits the same risk.
- **Bigger blast radius for bootstrap errors.** A fatal during `config/app.php`
  exits the worker before it serves anything; persistent failures trigger
  FrankenPHP's "too many consecutive failures" — no HTTP served at all.
  Uncaught exceptions inside handlers also end the worker run.
- **Operational unfamiliarity.** FrankenPHP is newer than php-fpm; worker-mode
  debugging (memory growth, output buffering, `exit`/`die`) needs
  FrankenPHP-specific knowledge.

## Follow-ups

- Audit DI bindings for mutable instance state; fix `RedisGitHubCache` log-suppression flags (must be request-scoped).
- Memory-leak CI job: long request stream, assert RSS budget.
- Cap requests per worker (`frankenphp { max_requests N }`) — also recycles stale PDO connections.
- Decide PDO reconnect strategy and exercise the Predis reconnect path end-to-end.
- Pin worker count in `Caddyfile` instead of inheriting 2-per-CPU.
- Tune opcache (`memory_consumption`, `jit`); add `php.ini-production` overlay.
- Document worker-mode rules for contributors: no per-request statics, careful with `exit` / `die` and output buffering.
