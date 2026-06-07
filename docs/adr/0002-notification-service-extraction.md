# 2. Extract notification delivery into a RabbitMQ-backed microservice

- **Status:** Accepted
- **Date:** 2026-06-08
- **Scope:** Release-notification delivery, delivery ledger ownership, and the publish/consume runtime split.

## Context

The original system sent release emails inside the monolith scanner process.
That design mixed three concerns in one deployable:

1. detecting releases from GitHub
2. deciding which subscribers should be notified
3. performing SMTP delivery and delivery deduplication

It also coupled the monolith database to delivery state through
`release_notifications`, making SMTP failures part of the scanner's runtime
surface and blocking independent evolution of the email-delivery path.

## Decision

Extract notification delivery into a separate service under `apps/notification`
and connect the two runtimes through RabbitMQ.

### Runtime split

- The monolith owns subscription management and release detection.
- The notification service owns email rendering, SMTP delivery, and delivery
  deduplication.

### Integration contract

- The monolith publishes one additive-only JSON command per subscriber:
  `SendReleaseEmail/v1`.
- RabbitMQ is configured with a durable queue and a dead-letter path.
- The notification service consumes that queue and applies bounded retry plus
  DLQ routing.

### Delivery semantics

- We intentionally keep the system **outbox-free**.
- `NewReleaseDetected` is dispatched synchronously inside the monolith scan
  cycle.
- The scanner advances `repositories.last_seen_tag` only after the RabbitMQ
  publish succeeds.
- If publish fails, the marker stays unchanged and the next scan cycle
  re-detects the same release.
- Duplicate publish or redelivery is absorbed by the notification service's own
  ledger (`UNIQUE(subscription_id, repository, tag_name)`).

### Data ownership

- Monolith Postgres owns only `subscriptions` and `repositories`.
- Notification Postgres owns `release_notifications` and
  `notification_metrics`.
- The monolith no longer stores delivery rows and no longer sends SMTP mail.

## Alternatives considered

- **Keep SMTP delivery in the monolith**: simpler deployment, but poor
  ownership boundaries and no independent scaling or failure isolation.
- **Add an outbox to the monolith**: stronger delivery bookkeeping, but extra
  operational and schema complexity that the current synchronous publish gate
  avoids.
- **Call the notification service over HTTP/gRPC**: tighter runtime coupling
  and worse buffering/recovery semantics than RabbitMQ.

## Consequences

### Positive

- Delivery failures are isolated from the monolith's HTTP and gRPC surfaces.
- The notification path has its own database, metrics, and retry policy.
- RabbitMQ provides durable buffering while the notification service is down.
- The monolith architecture is cleaner: publish integration commands, do not
  own SMTP.

### Negative

- The local and production stacks now require RabbitMQ and a second Postgres.
- Observability now spans two runtimes and a queue, so operators must track the
  publish side and consume side together.
- The no-outbox choice accepts a re-detection window instead of exactly-once
  transport semantics.

## Follow-ups

- Keep README and LikeC4 in sync with the split runtime.
- Preserve the `SendReleaseEmail/v1` contract as additive-only.
- Extend service-side operational dashboards around queue depth, consumer
  failures, and DLQ volume.
