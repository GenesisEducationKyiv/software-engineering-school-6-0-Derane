---
artifact: prd
project: github-release-notifier
title: 'Modular architecture refactor + extract Notification into a microservice'
author: valerii
date: '2026-06-03'
status: draft
related: ['_bmad-output/project-context.md', 'analysis: domain-boundary report (2026-06-03)']
---

# PRD — Modular Architecture Refactor & Notification Microservice

## 1. Context & Problem

`github-release-notifier` is a Slim 4 + PHP-DI application that watches GitHub
repositories for new releases and emails subscribers. It already runs as three
processes (`app` REST, `grpc`, `scanner`) but they share **one codebase, one
Postgres, one Redis, one DI container** — modularity exists by file-type layering
(`Controller/`, `Service/`, `Repository/`…), not by domain. Domains leak into each
other: subscription writes reach into GitHub + tracked-repo state, notification
delivery reaches into subscription data, and metrics query two domains' tables
directly.

We need to (a) restructure the monolith along **domain module boundaries** with
explicit contracts between modules, and (b) extract **at least one domain into a
separate microservice**. The chosen domain to extract is **Notification / Email
delivery**.

## 2. Goals & Non-Goals

### Goals
- G1. Reorganize `src/` into **domain modules** with clear ownership and
  explicit, contract-only boundaries (no cross-module reach-through).
- G2. Extract the **Notification/Email** domain into a standalone, independently
  deployable microservice (`notification-service`) with its **own database**.
- G3. Integrate monolith → service **asynchronously via RabbitMQ** (durable,
  at-least-once), with the service as an idempotent consumer.
- G4. Preserve the existing **public contracts** (REST JSON shape, gRPC reply,
  Behat acceptance) of the monolith — extraction is internal.
- G5. Keep all quality gates green: `composer lint`, `phpunit`, `composer psalm`
  (100% types), plus acceptance.

### Non-Goals
- N1. No new product features for end users.
- N2. Not extracting GitHub-fetch, Subscription, or Tracked-Repo domains in this
  phase (documented as future phases).
- N3. No change to the email content/template or SMTP provider.
- N4. No Kubernetes / service mesh / API gateway introduction.

## 3. Scope

### 3.1 Modular monolith (remaining domains)
Restructure remaining code into modules, each owning its Domain/Service/Repository
internals and exposing only interfaces to others:
- **Subscription** (`subscriptions` table) — public REST/gRPC surface.
- **TrackedRepository** (`repositories` table) — scan registry + progress.
- **GitHub** (Redis cache) — release fetching behind `GitHubServiceInterface`.
- **Scanner** — orchestration (process manager) wiring GitHub → Notification → progress.
- **Notification (client side)** — a thin publisher module that emits events to
  RabbitMQ; replaces the in-process `NotificationDispatcher`.
- **Shared/Platform** — cross-cutting: error mapping, metrics, health, middleware,
  messaging infrastructure.

### 3.2 Notification microservice (extracted domain)
A new standalone PHP service consuming RabbitMQ and sending email. It owns and
moves: `NotifierService`, `ReleaseEmailRenderer`/`RenderedEmail`, `SmtpMailer`
(+ `MailerInterface`, `PHPMailerFactory`, `SmtpConfig`), the idempotency ledger
(`NotificationLedger` + `release_notifications` table) → **into its own Postgres**.

## 4. Target Topology (high level)

```
[app REST]   [grpc]                 RabbitMQ                 [notification-service]
     \         /                   (durable queue)                  worker
   Subscription/TrackedRepo/GitHub        ^                          |
            |                             |  publish per-recipient   |  consume + send
        [scanner] --- resolve subs (A) ---+  SendReleaseEmail event  +--> SMTP
            |                                                         |
       Postgres (monolith: subscriptions, repositories)         Postgres (notif: release_notifications)
       Redis (github cache)
```

## 5. Functional Requirements

### Monolith — module boundaries
- FR1. Each domain module exposes its capabilities only through interfaces; no
  module references another module's concrete repository/DTO internals.
  Cross-module contracts live at module edges.
- FR2. `MetricsRepository` must stop querying foreign domains' tables directly;
  metrics are sourced via each owning module's contract (or a per-module counter).

### Monolith — notification publishing
- FR3. When the scanner detects a new release, it resolves the recipient list
  for that repository via the Subscription module (`SubscriberFinderInterface`)
  and **publishes one `SendReleaseEmail` event per recipient** to RabbitMQ.
- FR4. The event payload carries everything the service needs to send and to
  dedupe without calling back: `subscriptionId`, `email`, `repository`,
  `release` (tag, name, url, published_at), and an `eventId`.
- FR5. Publishing must be reliable: if publishing the batch for a repository
  fails, the scanner must NOT advance `last_seen_tag` for that repo (it retries
  next cycle). Successful publish ⇒ advance marker.
- FR6. The in-process `NotificationDispatcher` and the monolith's dependency on
  `NotificationLedgerInterface` / SMTP are removed from the monolith.

### Notification service
- FR7. The service consumes `SendReleaseEmail` from a durable RabbitMQ queue,
  renders the email, and sends it via SMTP.
- FR8. The service is **idempotent**: before sending it checks its own ledger
  (`hasSuccessfulNotification(subscriptionId, repository, tag)`); after sending
  it records the result (`recordResult`). Duplicate deliveries (RabbitMQ
  redelivery or re-publish across scan cycles) must not send duplicate emails.
- FR9. On transient send failure the message is negatively acknowledged for
  retry (bounded) with a dead-letter path after N attempts; the ledger records
  attempts and `last_error`, mirroring today's behavior.
- FR10. The service exposes its own health check and metrics endpoint.

### Compatibility
- FR11. REST `/subscriptions` JSON shape, gRPC reply shape, and existing Behat
  scenarios remain unchanged.

## 6. Non-Functional Requirements

- NFR1. **Idempotency / at-least-once**: end-to-end exactly-once *effect* (no
  duplicate emails) via the service-side ledger; the transport is at-least-once.
  Caveat: a worker crash *between* the SMTP send and the ledger write can still
  emit one duplicate (bounded by the claim lease) — true exactly-once would need
  an SMTP-side idempotency key the transport does not provide. The ledger
  guarantees exactly-once *state* and no duplicates on redelivery/re-publish.
- NFR2. **Independent deployability**: service builds, migrates, and runs without
  the monolith's database; monolith runs without the service's database.
- NFR3. **Resilience** — *Descoped 2026-06-15.* The notification-outage resilience
  proof was removed; broker/service-down liveness and queued-delivery-on-recovery
  are no longer tracked requirements. (NFR4–NFR6 keep their numbers.)
- NFR4. **Observability**: structured logs + metrics on both sides; a published
  vs. consumed vs. delivered count is derivable.
- NFR5. **Quality gates** (lint, phpunit, psalm 100%, acceptance) pass for the
  monolith; the service has its own equivalent test suite + gates.
- NFR6. **Local dev**: a single `docker compose up` brings up monolith, service,
  RabbitMQ, both Postgres, Redis, MailHog/SMTP stub.

## 7. Integration Contract (summary — detail in architecture doc)

- Exchange/queue: `notifications` (durable), routing key `release.email`,
  dead-letter `notifications.dlx`.
- Message `SendReleaseEmail` v1: `{ eventId, occurredAt, subscriptionId, email,
  repository, release: { tagName, name, htmlUrl, publishedAt } }`.
- Versioned, additive-only schema; consumer tolerates unknown fields.

## 8. Data Ownership & Migration

- D1. `release_notifications` moves to the **notification service's Postgres**.
- D2. The FK `release_notifications.subscription_id → subscriptions(id) ON DELETE
  CASCADE` is **removed**; `subscription_id` becomes a plain reference column.
- D3. Subscription deletion no longer cascades the ledger. Orphan ledger rows are
  acceptable for an idempotency log (out-of-scope: a `SubscriptionDeleted` event
  for cleanup — future phase).
- D4. Monolith migrations drop the `release_notifications` table; service
  migrations create it (same columns + indexes, minus the FK).

## 9. Semantic Change (call out explicitly)

Today `ScannerService` advances `last_seen_tag` **only when all emails were
delivered synchronously** (`dispatch()` returns `bool $allDelivered`). After the
async extraction, the monolith cannot know delivery outcome synchronously.
New rule (FR5): the marker advances when notifications are **successfully
published** to the durable queue; **delivery reliability becomes RabbitMQ +
service retries + ledger idempotency**. This trades synchronous
delivery-confirmation for queue-backed eventual delivery. **No outbox is needed**:
the marker is a recomputable checkpoint written *after* publish, so any crash
window self-heals via re-detection next cycle + idempotent consumer (architecture §14).

## 10. Out of Scope / Future Phases

- Extract GitHub-fetch domain (sync gRPC) — phase 2.
- Subscription read-model replication / `SubscriptionDeleted` cleanup events.
- Transactional outbox — only if non-re-derivable events are later added
  (e.g. `SubscriptionCreated/Deleted` from the HTTP path); not needed for the
  poll-driven notification flow.
- Splitting the shared monolith Postgres further (Subscription vs TrackedRepo).

## 11. Risks & Mitigations

- R1. **Lost notifications if publish fails mid-batch** → FR5 (don't advance
  marker on publish failure) + idempotent re-publish next cycle. No outbox
  needed: re-detection is the recovery path.
- R2. **Duplicate emails** (re-publish + at-least-once) → service-side ledger
  dedupe (FR8) with `UNIQUE(subscription_id, repository, tag_name)`.
- R3. **Wire-contract regressions** → keep Behat/JSON/gRPC unchanged (FR11);
  contract tests on the event schema.
- R4. **Scope creep into full modular rewrite** → module moves are mechanical and
  contract-preserving; behavior changes limited to the notification path.
- R5. **Two-database local complexity** → docker compose profile + Makefile targets.

## 12. Acceptance Criteria (definition of done)

- AC1. `src/` is organized into domain modules; no module imports another's
  concrete repository/DTO; verified by Psalm/architecture check.
- AC2. A separate `notification-service` exists, builds, migrates its own DB, and
  runs independently.
- AC3. Detecting a new release publishes per-recipient events; the service
  consumes them and sends email (demonstrated end-to-end via docker compose +
  MailHog).
- AC4. Re-running a scan / redelivering a message sends **no duplicate email**
  (idempotency proven by test).
- AC5. *Descoped 2026-06-15.* Resilience proof removed — outage liveness and
  queued-delivery-after-recovery are no longer tracked acceptance criteria.
- AC6. All monolith quality gates green; service has its own green gates.
- AC7. Architecture docs (LikeC4 model + ADR) updated to show the new service,
  RabbitMQ, and the two databases.
