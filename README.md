# GitHub Release Notifier

GitHub Release Notifier is a modular PHP system that lets users subscribe to
GitHub repositories and receive email when a new release is published.

The runtime is now split into:

- monolith HTTP API on Slim 4 / FrankenPHP
- monolith gRPC API on RoadRunner
- monolith scanner worker
- notification microservice consuming `SendReleaseEmail/v1` from RabbitMQ

## Stack

- PHP 8.4 monolith, PHP 8.2 notification service
- PostgreSQL for monolith data: `subscriptions`, `repositories`
- PostgreSQL for notification data: `release_notifications`, `notification_metrics`
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
make resilience-proof
```

Architecture model:

```bash
make c4-up
make c4-validate
make c4-down
```

## Quality Gates

Monolith:

```bash
composer lint
./vendor/bin/phpunit --no-coverage --testsuite Unit
composer psalm
```

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
- `SendReleaseEmail/v1` integration payload

The monolith publishes the RabbitMQ command after release detection; the
notification service never calls back into the monolith database to send mail.

## Architecture Notes

- Clean Architecture + pragmatic DDD layout under `src/<Context>/<Module>/<Layer>`
- synchronous in-process PSR-14 domain events inside the monolith
- asynchronous cross-service integration through RabbitMQ
- no outbox: the scanner advances `last_seen_tag` only after publish succeeds
- data ownership split:
  - monolith DB: subscriptions and tracked repositories
  - notification DB: delivery ledger and delivery metrics

The LikeC4 model lives in [docs/architecture](docs/architecture/README.md). ADRs live in `docs/adr/`.
