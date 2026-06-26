# Architecture (LikeC4)

The authoritative architecture model lives in the `.c4` files in this
directory and is rendered with [LikeC4](https://likec4.dev).

## Current system

The project now has four runtime roles:

- `app` — monolith HTTP API
- `grpc` — monolith gRPC API
- `scanner` — monolith release-detection worker
- `notification-svc` — notification-delivery worker

Supporting infrastructure:

- monolith Postgres for `subscriptions` and `repositories`
- notification Postgres for `release_notifications` and `notification_metrics`
- Redis for cached GitHub API responses
- RabbitMQ for `SendReleaseEmail/v1`
- MailHog as the local SMTP sink

## Model files

| File | What it describes |
|------|-------------------|
| `specification.c4` | element/relationship styling |
| `landscape.c4` | subscriber, GitHub, and the system boundary |
| `containers.c4` | deployables and infrastructure in the final split stack |
| `components.c4` | key components inside HTTP, gRPC, scanner, and notification service |
| `views.c4` | rendered views and dynamic flows |

## Core flow

1. HTTP or gRPC creates a subscription in the monolith database.
2. `scanner` polls GitHub and detects a new release.
3. The monolith resolves subscribers and publishes one `SendReleaseEmail/v1`
   command per subscriber to RabbitMQ.
4. `notification-svc` consumes the queue, checks its own delivery ledger,
   renders the message, and sends email through MailHog/SMTP.
5. Delivery state is recorded in the notification database, not the monolith
   database.

## Architectural decisions reflected in the model

- Clean Architecture boundaries inside the monolith and notification service
- synchronous in-process PSR-14 events for monolith domain flow
- asynchronous cross-service integration through RabbitMQ
- no outbox: scanner marker advancement is gated by publish success
- split data ownership between monolith Postgres and notification Postgres

## Working with the model

Validate the model from the repo root:

```bash
make c4-validate
```

Preview it locally:

```bash
make c4-up
make c4-down
```
