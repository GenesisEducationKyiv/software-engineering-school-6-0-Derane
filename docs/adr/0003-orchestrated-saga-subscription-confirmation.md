# 3. Confirm subscriptions with an orchestrated saga across the monolith and notification service

- **Status:** Accepted
- **Date:** 2026-06-20
- **Scope:** Subscription enrollment lifecycle, the welcome-email confirmation flow, and the cross-service saga that drives it.

## Context

HW7 left subscriptions in a single state: a `POST /api/subscriptions` inserted a
row and the subscriber was immediately a release recipient. There was no
confirmation step, so a mistyped or unwanted address still received release mail,
and a failed welcome had no terminal disposition.

We want a subscription to become a *confirmed* recipient only after the
notification service has actually delivered a welcome email, and to be
*cancelled* if that welcome terminally fails — without coupling the HTTP request
to the broker or the SMTP path, and without adding a new database or a second
deployable. The two runtimes already split along a RabbitMQ boundary (ADR-0002)
and share no PHP code, so the confirmation flow has to span two services and two
Postgres databases while keeping the `POST` fast and crash-safe.

The constraint that shapes everything: subscription creation is **user-initiated
and not re-derivable**, unlike the HW7 release flow whose only state is the
recomputable `repositories.last_seen_tag` high-water mark. A lost publish in the
release flow self-heals on the next scan; a lost welcome intent cannot be
re-derived, so the confirmation flow needs outbox-like reliability.

## Decision

Drive the confirmation flow with one concrete **orchestrated saga** (Richardson,
*Microservices Patterns* ch. 4 — orchestration with a persistent coordinator and
the compensatable → pivot → retriable shape). This is one specific saga, not a
reusable engine: no Symfony, no Messenger, no saga framework — just our in-house
CQRS bus, the PSR-14 in-process plane, PDO, and RabbitMQ.

### The orchestrator owns the saga state

The monolith is the coordinator. A durable `enrollment_sagas` row (Postgres A)
holds the saga lifecycle (`started → awaiting_confirmation → completed`, or
`→ compensating → compensated`) separately from the subscription `status`
(`pending | confirmed | cancelled`). The notification service stays a pure
participant: it sends and replies, it does not know there is a saga.

### Atomic start + outbox-style relay (no synchronous broker call on `POST`)

`SubscribeCommandHandler` writes the subscription **and** the
`enrollment_sagas` row in **one** transaction (a `Shared.Domain`
`TransactionManager` port owns the single explicit DB transaction; no raw `PDO`
leaks into Application). The `POST` never touches RabbitMQ, so it cannot fail or
block on a down broker — it returns `201` with `status: pending` and a persisted
`STARTED` saga.

A long-lived `saga-worker` then relays the durable saga row: it selects
`STARTED` sagas, publishes `SendWelcomeEmail` with **publisher confirms**, and
only on a confirmed publish advances the saga to `AWAITING_CONFIRMATION`
(stamping `awaiting_since`). The saga row is the one place this system accepts
outbox-like reliability; `enrollment_sagas.state` is that record. There is no
synchronous fast-path — the relay is the sole publisher.

### Two messages + topology

- `SendWelcomeEmail/v1` (monolith → notification), published on
  `subscription.welcome-email` to the durable `notifications.welcome-email` queue
  with the same bounded-retry/DLQ envelope as the release queue.
- `WelcomeEmailOutcome/v1` (notification → monolith), published on
  `subscription.welcome-email.reply` to the durable, DLX-less
  `notifications.welcome-email-reply` queue.

Correlation is by AMQP `correlation_id = sagaId` plus the primitive
`subscriptionId` (the cross-DB business key); no per-message `reply_to` and no
RPC — the reply address is a fixed topology constant. Both messages are frozen as
additive-only golden contracts (`contracts/send-welcome-email.v1.json`,
`contracts/welcome-email-outcome.v1.json`) with producer/consumer contract tests,
mirroring the `SendReleaseEmail/v1` discipline.

### Idempotency and compensation (exactly-once *state*, not delivery)

The flow is at-least-once on the wire, exactly-once on state:

- **Send dedup** — the welcome ledger's `UNIQUE(subscription_id)` claim makes a
  redelivered or re-relayed `SendWelcomeEmail` a no-op that still re-emits its
  prior reply.
- **Reply dedup** — the subscription `status = 'pending'` guard is the true
  single-writer lock. The orchestrator runs paired conditional UPDATEs in one
  transaction (`UPDATE subscriptions … WHERE status = 'pending'` +
  `UPDATE saga … WHERE state IN ('started','awaiting_confirmation')`); a
  redelivered or late reply returns `rowCount() = 0` on both and is acked-and-dropped.
  Replaying `sent` 3× confirms once; replaying `failed` 3× cancels once.
- **Compensation** — a `WelcomeEmailOutcome{failed}` (emitted before the DLQ) or a
  timeout sweep cancels the subscription and compensates the saga. The sweeper
  runs two queries: a primary one over `AWAITING_CONFIRMATION` sagas past
  `awaiting_since + T`, and a secondary one over `STARTED` sagas past
  `created_at + T_start` (the broker-down backstop whose `awaiting_since` is still
  `NULL`), with `T_start ≥ T` so a slow-but-recovering broker is not compensated
  prematurely.

`complete()` accepts both `Started` and `AwaitingConfirmation`, so a `sent` reply
that races ahead of the relay's own `markPublished` commit still converges.

### One worker, two consumers

Relay, reply consumer, and timeout sweep fold into a single supervised
`saga-worker` process; the notification side adds the welcome consumer as a
second `basic_consume` on its existing consumer. This keeps the container count
flat. All three saga concerns are idempotent, so a supervised `exit(1)`/restart
re-derives every pending unit of work from the durable saga rows.

### Confirmed-only recipient resolution

`findSubscribersByRepository` gains `AND status = 'confirmed'` (served by
`idx_subscriptions_repository_status`) — this one SELECT, the recipient
resolution path, is filtered. The read/list SELECTs stay unfiltered so an owner
still sees their own `pending`/`cancelled` rows.

## Alternatives considered

- **Poll PENDING and re-derive the saga.** Skip the saga row at create time and
  have the relay derive work from `subscriptions WHERE status = 'pending'`
  (outbox-free, like the release flow). Rejected: the relay still needs to tell a
  never-published PENDING from an already-published one — without a publish-state
  marker it would re-publish on every tick and lean entirely on the service-side
  ledger to dedup, and it adds a window where a PENDING subscription has no
  recorded publish state. The atomic saga row buys precise, crash-safe
  publish-state and a clean timeout anchor (`awaiting_since`) for one extra in-tx
  INSERT.
- **Two-phase commit across the two databases.** Out of scope per the task: 2PC
  couples the runtimes and the databases far more tightly than the saga, and the
  stack (PDO + RabbitMQ, two independent Postgres) has no distributed-transaction
  coordinator. The saga's compensatable → pivot → retriable shape is the explicit
  alternative to 2PC here.
- **Start the saga from the post-commit `SubscriptionCreated` listener.** A second,
  non-atomic, id-less write after the insert; rejected for a single in-tx start.
- **A third database / `apps/saga` deployable.** Saga state co-locates in Postgres
  A; no new database, no new deployable.

## Consequences

### Positive

- The `POST` is fast and broker-independent: it commits two local rows and
  returns, never failing or blocking on RabbitMQ or SMTP.
- Confirmation is crash-safe: the durable saga row is the outbox-style intent, and
  the relay replays it until a confirmed publish.
- Exactly-once *state* under at-least-once delivery: the `status = 'pending'`
  guard plus the welcome ledger make replays and races converge to one outcome.
- No new database and no new deployable — saga state lives in Postgres A and the
  `saga-worker` is monolith code; the container count stays flat.
- Both new messages are frozen additive-only contracts with producer/consumer
  tests, so the two independently deployed services cannot silently drift.

### Negative

- **Bounded duplicate welcome at timeout.** If a send is still in flight when the
  sweeper fires compensation at `T`, the subscription is consistently `CANCELLED`
  but at most one welcome email may still go out; the late `sent` reply is a
  `rowCount() = 0` no-op. With `T = 900s` over a ~335s effective floor this is
  vanishingly rare — a documented, bounded duplicate, not a correctness bug. The
  system claims exactly-once *state*, not exactly-once *delivery*.
- **First long-lived monolith consumer.** The monolith was publish-only; the
  `saga-worker` is its first consumer and folds three failure modes (relay, reply,
  sweep) into one supervised process — mitigated by their shared idempotency and
  restart-driven re-derivation.
- The confirmation flow now spans two services, a queue, and two databases, so
  observability has to track the publish, consume, reply, and sweep sides together.

## Follow-ups

- **Re-confirm path (deferred).** Because `create()` uses `ON CONFLICT (email,
  repository) DO NOTHING`, a re-`POST` after a `CANCELLED` subscription returns the
  stale row with no new saga, and the welcome ledger's `UNIQUE(subscription_id)`
  would block a second welcome for a reused id. A re-confirm / resurrection path is
  future work, not in this phase.
- **Deferred docker end-to-end + LikeC4 sync.** The `saga-worker` compose service,
  the additive JSON/gRPC `status` field, and the end-to-end happy-path +
  compensation demo are a later docker-enabled pass; the LikeC4 model in
  `docs/architecture/` (the `saga-worker` container, the `Saga / Enrollment`
  context, the two messages + topology, the welcome consumer + outcome publisher,
  and the `welcome_notifications` store) is synced in that same pass.
- Keep the two welcome contracts additive-only, as with `SendReleaseEmail/v1`.
