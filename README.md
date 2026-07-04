# GitHub Release Notifier

GitHub Release Notifier is a modular PHP system that lets users subscribe to
GitHub repositories and receive email when a new release is published.

The runtime is now split into:

- monolith HTTP API on Slim 4 / FrankenPHP
- monolith gRPC API on RoadRunner
- monolith scanner worker
- monolith saga worker (enrollment-saga orchestrator: outbox relay, outcome consumer, timeout sweeper)
- notification microservice consuming `SendReleaseEmail/v1` and `SendWelcomeEmail/v1` from RabbitMQ (plus a sync REST `:8081` / gRPC `:9002` welcome-email surface)

## Stack

- PHP 8.4 monolith, PHP 8.2 notification service
- PostgreSQL for monolith data: `subscriptions`, `repositories`, `enrollment_sagas`, `saga_metrics`
- PostgreSQL for notification data: `release_notifications`, `welcome_notifications`, `notification_metrics`
- Redis for GitHub API caching
- RabbitMQ for cross-service delivery
- MailHog as the local SMTP sink

## Quick Start

Bring the local stack up and migrate both databases:

```bash
make up
make migrate
make migrate-notification
```

Main local endpoints:

- HTTP API: `http://localhost:8080`
- HTML form: `http://localhost:8080/`
- Monolith health: `http://localhost:8080/health`
- Monolith metrics: `http://localhost:8080/metrics`
- gRPC: `localhost:9001`
- MailHog UI: `http://localhost:8025`
- RabbitMQ management: `http://localhost:15672`

## Compose Topology

`make up` starts:

- `app` — monolith HTTP runtime
- `grpc` — monolith gRPC runtime
- `scanner` — monolith scan loop
- `saga-worker` — monolith enrollment-saga loop (relay / outcome consumer / sweeper)
- `postgres` — monolith database
- `redis` — GitHub cache
- `rabbitmq` — durable queue / DLQ transport
- `notification-db` — notification-service database
- `notification-svc` — notification worker
- `mailhog` — local SMTP sink

Release flow:

1. HTTP/gRPC creates subscriptions in the monolith database.
2. `scanner` polls GitHub and detects a new release.
3. The monolith publishes one `SendReleaseEmail/v1` message per subscriber to RabbitMQ.
4. `notification-svc` consumes the queue, dedupes in its own database, renders the email, and sends it to MailHog/SMTP.

There is no longer an in-process monolith notifier or monolith-side
`release_notifications` ledger.

## Useful Commands

Core:

```bash
make up
make down
make restart
make logs
make migrate
make migrate-notification
```

Notification-service operations:

```bash
make logs-notification-svc
make logs-rabbitmq
make logs-notification-db
make notification-smoke
make scanner-smoke
```

Architecture model:

```bash
make c4-up
make c4-validate
make c4-down
```

## REST → gRPC: welcome-email send

The HW9 enrollment saga's **welcome-email send** (monolith saga relay → notification
service) travels over three interchangeable transports, chosen by one env flag —
a controlled REST→gRPC migration that keeps the async default fully intact:

| `WELCOME_EMAIL_TRANSPORT` | Wire | Service B surface | Notes |
| --- | --- | --- | --- |
| `rabbit` *(default)* | AMQP (async) | `SendWelcomeEmailConsumer` | HW9 path, unchanged: broker buffer + timeout sweeper |
| `rest` | HTTP/1.1 + JSON | `POST /internal/welcome-emails` (`php -S` :8081) | introduced sync baseline |
| `grpc` | HTTP/2 + protobuf | `notification.welcome.v1.WelcomeEmailService/SendWelcomeEmail` (RoadRunner :9002) | the migrated call |

All three drive the **same unchanged** `SendWelcomeEmailHandler`; the contract is
`proto/notification/welcome/v1/welcome.proto`, buf-toolchained into `gen/` (`make buf-lint`,
`make buf-generate`). The gRPC client (`grpc/grpc` + PECL `ext-grpc`) lives only in the
monolith image; Service B's gRPC server is RoadRunner (no `ext-grpc`). On the sync paths
the relay blocks for the `sent|failed` outcome and drives the saga in-thread (the async
RabbitMQ reply remains an idempotent backstop). Details in **ADR-0004**.

```bash
# switch transport (the saga-worker reads it from .env), then recreate the worker:
echo "WELCOME_EMAIL_TRANSPORT=grpc" >> .env && docker compose up -d --force-recreate saga-worker
```

### Benchmark — REST vs gRPC (k6)

Both transports hit the **same** handler on the notification service; k6 drives HTTP for
REST and `k6/net/grpc` for gRPC (`make bench-rest` / `make bench-grpc`, `VUS=`/`DURATION=`
overridable). Measured locally via docker compose:

**Matched load (8 VUs, 15s, 100% success on both)** — reproduce with `make bench-rest VUS=8 DURATION=15s` and `make bench-grpc VUS=8 DURATION=15s`:

| Metric | REST (`php -S`) | gRPC (RoadRunner) | gRPC vs REST |
| --- | --- | --- | --- |
| throughput | 16.5 req/s | **301.8 req/s** | ~18× |
| latency p50 | 465 ms | **25 ms** | ~19× lower |
| latency p95 | 615 ms | **43 ms** | ~14× lower |
| latency p99 | 729 ms | **62 ms** | ~12× lower |

**High load (50 VUs, 30s — the bare `make bench-rest` / `make bench-grpc` default):** REST stayed flat at ~18 req/s (its `php -S` ceiling, 100%
success); the gRPC wire sustained ~250+ req/s, but at that rate the **downstream
synchronous AMQP outcome-reply publish** (a confirm-publish per send) saturated and the
service returned `UNAVAILABLE` — i.e. under heavy load the bottleneck moves *off the wire*
to the reply leg, not the gRPC transport.

**Why gRPC wins here:**

- **Server concurrency model (dominant factor):** the REST baseline is PHP's built-in
  `php -S` — single-threaded, one request at a time (~18 req/s ceiling); the gRPC server
  is RoadRunner with a persistent worker pool. This is the honest, real-deployment
  difference and the largest contributor to the gap.
- **HTTP/2 multiplexing:** one connection carries many concurrent streams (the k6 gRPC
  client reuses a single connection per VU) versus serial HTTP/1.1 request/response.
- **Binary protobuf vs text JSON:** smaller frames and no per-request JSON parse or schema
  re-resolution.

So the numbers reflect transport **and** server model together (the realistic end-to-end
picture), not a wire-only microbenchmark.

## Quality Gates

Monolith:

```bash
composer lint
./vendor/bin/phpunit --no-coverage --testsuite Unit
composer psalm
```

`composer lint` runs PHPCS (PSR-12) and deptrac (architecture boundaries). Deptrac's report also
shows an `Uncovered` count — classes not matched by any layer regex (e.g. generated classes, test
helpers outside a declared layer); this is not a violation and drifts as the Strangler migration
progresses. The gate passes as long as the Violations count remains 0 (the baseline is empty).

Notification service:

```bash
cd apps/notification
composer lint
./vendor/bin/phpunit --no-coverage
composer psalm
```

## Contracts

Public wire contracts are frozen unless explicitly changed:

- REST `/api/subscriptions`
- gRPC `proto/release_notifier.proto`
- `SendReleaseEmail/v1`, `SendWelcomeEmail/v1`, `WelcomeEmailOutcome/v1` integration payloads (`contracts/*.json`)
- welcome-email gRPC `proto/notification/welcome/v1/welcome.proto` and REST `POST /internal/welcome-emails`

The monolith publishes the RabbitMQ command after release detection; the
notification service never calls back into the monolith database to send mail.

## Architecture Notes

- Clean Architecture + pragmatic DDD layout under `src/<Context>/<Module>/<Layer>`,
  enforced by deptrac as architecture tests (`deptrac.yaml` + `apps/notification/deptrac.yaml`, empty baseline)
- synchronous in-process PSR-14 domain events inside the monolith
- asynchronous cross-service integration through RabbitMQ
- release flow is outbox-free: the scanner advances `last_seen_tag` only after publish succeeds;
  the enrollment saga's welcome-email path uses an outbox-style relay over `enrollment_sagas`
  (publisher confirms + timeout sweeper)
- data ownership split:
  - monolith DB: subscriptions, tracked repositories, saga state (`enrollment_sagas`, `saga_metrics`)
  - notification DB: delivery + welcome ledgers and delivery metrics

The LikeC4 model lives in [docs/architecture](docs/architecture/README.md) — including the
bounded-context map and Clean Architecture layer views (`layers.c4`). ADRs live in `docs/adr/`.
