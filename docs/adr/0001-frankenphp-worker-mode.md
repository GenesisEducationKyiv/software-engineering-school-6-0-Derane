# 1. Adopt FrankenPHP in worker mode for the HTTP runtime

- **Status:** Accepted
- **Date:** 2026-04-12
- **Deciders:** Project owner
- **Scope:** HTTP entrypoint of `github-release-notifier`.
  Does not affect the gRPC service (RoadRunner) or the scanner CLI process.

## Context

The HTTP API is built on Slim 4 and was originally served inside the application
container by PHP's built-in development server:

```dockerfile
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
```

This setup had several problems that became visible once the project moved past
the prototype stage:

1. **Not intended for production.**
   The PHP manual is explicit: *"This web server is designed to aid application
   development. … It is not intended to be a full-featured web server. It should
   not be used on a public network."* The server is single-threaded by default
   (the `PHP_CLI_SERVER_WORKERS` env var added in PHP 7.4 spawns multiple
   workers, but the manual itself flags it as experimental and "not intended for
   production usage"). It ships no HTTP/2, no TLS, no proper static-file caching
   layer, and no process supervision.

2. **Cold bootstrap on every request.**
   `public/index.php` re-ran the full bootstrap on every request:
   - autoload
   - dotenv parsing
   - container construction
   - route registration
   - migration check (when `run_migrations_on_boot` was enabled)

   None of this state is request-scoped, so the work was pure overhead.

3. **Opcache effectively off.**
   The built-in CLI server is itself a long-lived process, but opcache is
   disabled in the CLI SAPI by default (`opcache.enable_cli = 0`). Even when
   forced on, the CLI opcache configuration is not the tuned production opcache
   used under php-fpm or a dedicated app server. The net result is that the hot
   path runs without the compiled-opcode cache that production PHP normally
   relies on.

4. **Mismatch with the rest of the stack.**
   The gRPC service already runs as a long-lived RoadRunner worker
   (`bin/grpc.php`), and the scanner runs as a long-lived CLI process.
   The HTTP path was the only one whose **application bootstrap** ran per
   request (`php -S` itself is a long-lived server process, but the router
   script that builds the Slim app re-executed on every hit). That made the
   HTTP runtime inconsistent with the rest of the stack and the slowest of the
   three.

A production-grade HTTP runtime was needed without introducing operational
complexity (extra processes, extra config files, extra container images).

## Decision

Adopt **FrankenPHP** as the HTTP runtime, in **worker mode**, replacing `php -S`.

Concretely:

- Switch the base image from `php:8.4-cli` to `dunglas/frankenphp:1-php8.4`.

- Add a single `Caddyfile` that points the embedded Caddy server at
  `bin/worker.php`:
  ```
  :8080 {
      root * /app/public
      php_server {
          worker /app/bin/worker.php
      }
  }
  ```

- Extract the Slim bootstrap from `public/index.php` into `config/app.php` so it
  can be reused. The bootstrap (autoload, env, container, routes, optional
  migrations) now runs **once per worker process**, not once per request.

  Note: the current `Caddyfile` declares `worker /app/bin/worker.php` without an
  explicit count. Per the FrankenPHP docs, the default is **2 workers per CPU**.
  That means the bootstrap (and `RUN_MIGRATIONS_ON_BOOT`, when enabled) runs
  *N times per container start*, not once. This is safe today only because
  migrations are guarded by a Postgres advisory lock (see README → Migrations);
  any future bootstrap-time side effect must be similarly idempotent across
  concurrent worker starts.

- Add `bin/worker.php` as the worker entrypoint. It loops on
  `frankenphp_handle_request()` and executes `$app->run()` per request against
  the already-built Slim app.

- Keep `public/index.php` as a thin shim that requires `config/app.php` and
  calls `$app->run()`. The same bootstrap still works under non-worker SAPIs
  (CLI, tests, fallback).

- Replace manual `docker-php-ext-install` / `pecl install` invocations with
  `install-php-extensions`, which ships in the FrankenPHP image.

## Alternatives considered

### php-fpm + nginx (or php-fpm + Caddy)

The classical production setup. Rejected because:

- Two processes to supervise inside one container, or two containers to wire up.
- Two configuration files (fpm pool + web server) instead of one.
- Per-request bootstrap is still paid. FPM keeps PHP processes alive (so opcache
  works and extensions stay loaded), but each request re-executes the script
  from the top — autoload, container build, route registration all run again.
- Does not solve the cold-bootstrap problem, only the "not for production"
  problem.

### RoadRunner for HTTP (in addition to the existing gRPC use)

RoadRunner is already used for gRPC, so reusing it for HTTP would have given a
single worker model across both transports. Rejected because:

- RoadRunner's HTTP worker requires the application to consume PSR-7 requests
  from the worker loop and emit PSR-7 responses back manually.
  Slim's standard `$app->run()` flow does not fit this without an adapter layer.

- FrankenPHP's `frankenphp_handle_request()` lets an unmodified Slim app run
  inside the worker loop with almost no code changes.
  The bootstrap is built once, and each request calls the same `$app->run()`
  that the non-worker entrypoint uses. This minimised the blast radius of the
  change.

- Caddy comes bundled with FrankenPHP, so HTTP/2, HTTP/3, automatic TLS, and
  static-file serving are available without an extra component.

### OpenSwoole / Swoole

Rejected because of:

- Intrusive code changes (event-loop programming model, coroutine-aware
  libraries).
- Incompatibility risks with synchronous PDO/Redis usage already in the
  codebase.
- The project is not I/O-bound enough to justify that cost.

### Stay on `php -S` and only add a reverse proxy

Would have addressed TLS/HTTP2 in front, but not the per-request bootstrap cost
or the "not for production" warning. Rejected as a non-solution to the
underlying problem.

## Consequences

### Positive

- **Lower per-request latency.**
  The Slim app, container, and routes are built once per worker.
  Each request only runs route dispatch and handler logic.

- **Single binary, single config.**
  Caddy + PHP runtime live in one process, configured by one `Caddyfile`.
  The Dockerfile gets shorter, not longer.

- **No network hop between web server and PHP.**
  PHP runs as an embedded SAPI inside the Caddy process. There is:
  - no FastCGI over TCP
  - no Unix socket
  - no protocol serialisation between the HTTP server and the PHP runtime

  Compared to a php-fpm setup the per-request handover is an in-process function
  call, not an inter-process round trip. This eliminates one class of latency,
  one failure surface (socket exhaustion, FastCGI timeouts), and one piece of
  configuration to tune.

- **Production-capable HTTP server out of the box.**
  HTTP/2, HTTP/3, automatic TLS, and proper static-file serving are available
  if needed later.

- **Simpler extension installation.**
  `install-php-extensions` replaces the previous mix of `docker-php-ext-install`
  and `pecl install` calls.

- **Migrations on boot run once per worker start**, not per request, when
  `run_migrations_on_boot` is enabled.

- **Architectural consistency.**
  All three runtimes (HTTP, gRPC, scanner) are now long-lived processes.

### Negative / risks

- **Worker-mode state leakage — broader than just request-scoped statics.**
  PHP-DI caches resolved entries, so every service registered in
  `config/container.php` is effectively a singleton for the worker's lifetime.
  In practice that means:
  - **Long-lived connections.**
    Both `PDO` and `Predis\Client` are built once and reused across requests,
    but their failure modes differ:
    - **PDO has no automatic reconnect.** A dropped Postgres connection or a
      Postgres restart will surface as request failures until the worker is
      recycled. Repository code does not currently retry-with-reconnect.
    - **Predis disconnects on `CommunicationException` when
      `shouldResetConnection()` returns true** (see
      `Predis\CommunicationException::handle()`), so the next command lazily
      opens a fresh connection. The first failed command still degrades its
      own request, and — combined with the suppression flags described below
      — subsequent failures may go unnoticed in logs. The reconnect path is
      worth validating end-to-end before relying on it.
  - **Mutable service state surviving requests.**
    `RedisGitHubCache::$readFailureLogged` / `$writeFailureLogged` are set
    after the first Redis failure to suppress duplicate log lines. Under
    `php -S` this state was scoped to a single request; under worker mode it
    persists for the lifetime of the worker, so subsequent failures in
    *different* requests are silently swallowed.
  - **General singleton risk.** Any future service that mutates instance
    properties (counters, flags, in-memory caches) inherits the same lifetime
    and the same trap.

  Code that is safe under `php -S` is not automatically safe here. New code in
  the HTTP path must either avoid mutable instance state, or explicitly reset
  it between requests.

- **Larger blast radius for bootstrap errors.**
  - Under `php -S`, `config/app.php` was loaded per request. A fatal in the
    bootstrap broke that single request and left the server process alive.
  - Under worker mode, `bin/worker.php` requires `config/app.php` exactly once
    before entering the request loop. A fatal there exits the worker before it
    serves anything.
  - Per the FrankenPHP docs: *"If a worker script crashes with a non-zero exit
    code, FrankenPHP will restart it with an exponential backoff strategy."*
    If the failure is persistent (e.g. a typo or broken config), the docs warn
    that FrankenPHP will eventually crash with `too many consecutive failures`
    — meaning **no HTTP requests are served at all** until a fix is deployed.
  - The same restart-on-exit behaviour applies to uncaught exceptions inside
    request handlers. The docs note that `set_exception_handler` is only called
    when the worker script *ends*, so any exception not caught inside the
    handler callback ends the worker run, not just the request.

- **Operational unfamiliarity.**
  FrankenPHP is newer than php-fpm. Debugging worker-mode-specific issues
  (request lifecycle, output buffering, memory growth across requests) requires
  FrankenPHP-specific knowledge that is less widely documented.

### Neutral

- The gRPC service remains on RoadRunner. Unifying on a single worker runtime
  was explicitly not a goal of this decision — the two transports keep
  independent runtimes.

- The scanner remains a plain long-lived CLI process and is unaffected.

## Follow-ups

- Audit DI container bindings for request-scoped state that may leak across
  requests in worker mode.

- Document worker-mode constraints for future contributors:
  - no per-request statics
  - careful with output buffering
  - careful with `exit` / `die`

- Add memory-leak tests for the HTTP worker.
  Drive the worker with a long, repeated request stream (e.g. several thousand
  iterations against representative endpoints) and assert that resident memory
  growth stays below a defined budget. The goal is to catch state that
  accumulates across requests inside one worker process — the failure mode that
  worker mode introduces and `php -S` could not have.

- Wire those memory-leak tests into CI as a dedicated job, separate from
  `make test` / `make behat` (they are slower and have a different failure
  signal). Treat a budget breach as a build failure so regressions are caught
  before deploy.

- Add an operational mitigation alongside the test: cap the number of requests
  served before a worker / PHP thread is recycled. The FrankenPHP docs
  explicitly recommend this as a workaround for legacy code that leaks memory.
  Two paths exist and they are not equivalent:
  - **Caddyfile-level (experimental):**
    `frankenphp { max_requests N }` recycles **PHP threads** after N requests.
    No application-code change required. Lowest-friction path; the right
    starting point.
  - **Env var + worker loop:** the `MAX_REQUESTS` env var combined with the
    `for ($n = 0; !$max || $n < $max; ++$n)` loop pattern from the docs
    recycles the **worker script** specifically. Requires editing
    `bin/worker.php`. Use this if finer control over per-worker lifecycle is
    needed.

  Either way, finite recycling bounds the impact of any leak that escapes the
  test, and as a side effect resets stale `PDO` connections that have gone bad
  (Predis already auto-reconnects, see above).

- Define a connection-lifecycle / reconnect strategy. The two clients have
  very different failure modes today and should be addressed separately:
  - **PDO — real 5xx risk.**
    There is no automatic reconnect. A dropped Postgres connection or a
    Postgres restart turns subsequent queries into `PDOException` and bubbles
    up as 5xx until the worker is recycled. Options to evaluate:
    (a) `ATTR_PERSISTENT` plus a ping-on-checkout pattern;
    (b) wrap repository calls in a retry that rebuilds the connection on
    `PDOException`;
    (c) rely on the per-worker request cap as a coarse circuit-breaker.
  - **Predis — validate reconnect path, fix log-suppression scope.**
    `Predis\CommunicationException::handle()` already disconnects on
    `shouldResetConnection()`, so the next command lazily reconnects. A
    dropped Redis connection therefore degrades only the *first* request,
    not every subsequent one — and `RedisGitHubCache::get()` /
    `set()` already catch `\Throwable`, return `null` / no-op, and let the
    rest of the request proceed (cache miss, not 5xx).
    The real bug here is the log-suppression flags
    (`$readFailureLogged`, `$writeFailureLogged`) being scoped to the
    *worker* lifetime instead of the request: after one failure, every
    subsequent failure across every subsequent request is silently
    swallowed. The fix is to either reset those flags between requests or
    move them to a request-scoped store. The reconnect path itself should
    also be exercised end-to-end (kill Redis mid-flight, confirm next
    request succeeds) before relying on it in production.

- Explicitly decide on the worker count rather than inheriting the FrankenPHP
  default of "2 workers per CPU". The right count depends on the deployment
  shape (single-instance EC2 today, possibly multiple replicas later) and the
  database connection budget. Either pin a number in the `Caddyfile`
  (`worker /app/bin/worker.php N`) or document the rationale for keeping the
  default.

- Verify and tune opcache for the SAPI runtime. The `dunglas/frankenphp` image
  ships opcache enabled out of the box (`opcache.enable=1`,
  `opcache.memory_consumption=128`), so the basic motivation in
  *Context — Opcache effectively off* is realised automatically once we move
  off `php -S`. What is **not** tuned today:
  - `opcache.memory_consumption` is at the PHP default of 128 MB; large
    codebases benefit from raising it.
  - `opcache.jit` is `disable`d; enabling JIT is worth benchmarking for the
    hot path.
  - There is no `php.ini-production` overlay copied in the `Dockerfile`, so
    other production-grade defaults (e.g. `display_errors=Off`,
    `expose_php=Off`) rely on the base image's choices and should be audited.
