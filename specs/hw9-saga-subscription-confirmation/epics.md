---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories', 'step-04-final-validation']
inputDocuments:
  - 'specs/hw9-saga-subscription-confirmation/prd.md'
  - 'specs/hw9-saga-subscription-confirmation/architecture.md'
  - 'specs/project-context.md'
  - 'CLAUDE.md'
artifact: epics
project: github-release-notifier
title: 'Epic & Story Breakdown — Orchestrated Saga: Confirmed-Subscription Welcome Email'
author: valerii
date: '2026-06-20'
status: draft
---

# github-release-notifier - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for **github-release-notifier**,
decomposing the requirements of the HW9 PRD and Architecture into implementable stories. HW9
implements one **distributed transaction** — "create a subscription and confirm it via a welcome
email" — as an **orchestrated Saga** spanning the Slim 4 + PHP-DI **monolith** (Postgres A,
`release_notifier`) and the extracted **notification service** (Postgres B, `release_notifications`),
coordinated by two new durable RabbitMQ messages. The orchestrator and its persisted state live in
the monolith; 2PC is **out of scope** entirely (PRD N1) — neither designed nor compared.

The saga follows the canonical *compensatable → pivot → retriable* shape: **T1** creates the
subscription `PENDING` (monolith-local, compensatable), **T2** sends the welcome email (notification
service, pivot — no rollback), **T3** transitions `PENDING → CONFIRMED` on a confirmed send, and
**C1** compensates `PENDING → CANCELLED` on terminal failure or timeout. Reliability rests on an
atomic subscription+saga write, an outbox-style relay, state-guarded conditional `UPDATE`s for
exactly-once *state*, and a timeout sweeper guaranteeing convergence — no permanently-`PENDING`
subscription, ever.

The plan is executed **dependency-ordered along the architecture P0→P10 migration ladder**
(architecture section-12), in five epics. Every story keeps the quality gates green: monolith
`composer lint` (PHPCS PSR-12 **+ deptrac**), `./vendor/bin/phpunit --no-coverage --testsuite Unit`,
`composer psalm` (100%); stories that touch `apps/notification` also run the service's equivalent
gates. The **deptrac baseline stays `{}`** (NFR6) — every new cross-context edge is an explicit,
minimal port edge. The public wire contracts (REST `/api/subscriptions` JSON, gRPC `SubscriptionReply`,
Behat scenarios) are preserved **additively**: a `status` field is *added*, never substituted.

> Epic-to-phase map: **Epic A = P0–P1**, **Epic B = P2–P3**, **Epic C = P4–P5**,
> **Epic D = P6**, **Epic E = P7–P10**.

## Requirements Inventory

### Functional Requirements

FR1: `POST /api/subscriptions` creates the subscription in state **`PENDING`** (T1) and records the
existing `SubscriptionCreated` domain event. The endpoint still returns `201` with the subscription;
it does not block on email delivery. A created subscription is readable with `status: PENDING`
immediately after `201`.

FR2: The subscription row **and** a saga instance are written **atomically** in a single
monolith-local transaction: no subscription without a saga, no saga without a subscription. The saga
is persisted in Postgres A in initial state `STARTED`, keyed 1:1 to the subscription (correlation key
= `subscriptionId`, plus a unique `sagaId`). A duplicate `POST` (same email+repository) is idempotent
— it returns the existing subscription and starts no second saga. A forced failure between the two
writes commits neither.

FR3: The saga is **started inside the subscription write path** (the command handler / a transactional
application service / the repository) **in the same DB transaction as T1** — not from a downstream
PSR-14 listener. The id-less `SubscriptionCreated` event cannot key a saga on `subscriptionId` (the id
does not exist at record time); a post-commit listener insert would be a second, non-atomic write.
Starting the saga must not fail or block the request thread (FR1); if the saga insert fails, the whole
`POST` fails and no subscription is committed.

FR4: The `SendWelcomeEmail` command is published **reliably and decoupled from the `POST`** via an
**outbox-style relay**: the persisted saga row is the source of truth and carries a publish-state
marker (the `STARTED`-vs-`AWAITING_CONFIRMATION` distinction). A relay running in a long-lived
monolith worker publishes `SendWelcomeEmail` for `STARTED`-but-not-yet-published saga rows and only
advances the saga to `AWAITING_CONFIRMATION` after a confirmed publish (publisher confirms); the relay
is idempotent under at-least-once. The command carries a correlation id (`sagaId`) and a reply address.
A broker outage delays confirmation but never fails the `POST` nor loses the saga.

FR5: The notification service consumes `SendWelcomeEmail` from a durable queue, renders the welcome
email from a **new welcome template** (distinct from the release-email template) and sends it via SMTP.
A consumed command produces exactly one welcome email to the subscriber address.

FR6: The welcome send is **idempotent**: before sending, the service claims the work in its ledger
(reusing the claim / fencing-token `NotificationLedger` pattern, keyed for welcome emails); a
redelivered or re-published command for an already-sent welcome is a no-op (deduped, no second email).

FR7: After processing, the service **publishes a reply** (`WelcomeEmailOutcome`) back to the monolith
carrying the outcome (`sent` | `failed`), the `subscriptionId`, and the correlation id (`sagaId`).
Transient send failures retry within the bounded `MAX_REDELIVERIES = 3` / DLQ envelope; only a terminal
failure (retries exhausted) yields an `outcome: failed` reply. Because the existing consumer silently
DLQs an exhausted message with no reply, this is a **new terminal-failure reply mechanism**: on the
final attempt the consumer publishes `WelcomeEmailOutcome{failed}` (with its own fail-closed publisher
confirm) before `nack`-ing to the DLQ.

FR8: A **new monolith reply consumer** consumes `WelcomeEmailOutcome` and hands it to the orchestrator,
which advances the saga by correlation id: `sent → T3` (subscription `PENDING → CONFIRMED`, saga
`COMPLETED`); terminal `failed → C1` (subscription `PENDING → CANCELLED`, saga `COMPENSATED`).

FR9: Reply processing is **idempotent via state-guarded conditional transitions**. Each transition is
a conditional `UPDATE` keyed on the current state (e.g. `UPDATE subscriptions SET status='CONFIRMED'
WHERE id=:id AND status='PENDING'`, plus the matching saga-row update) and is a no-op iff
`rowCount() = 0`. This is safe under concurrent redelivery: exactly one `UPDATE` matches
`status='PENDING'`; the other observes `rowCount()=0` and does nothing. Replayed `sent` on an
already-`CONFIRMED` subscription, replayed `failed` on an already-`CANCELLED` subscription, and a reply
for an unknown or already-terminal saga are all no-ops.

FR10: A **timeout sweeper** compensates sagas that never receive a reply within a deadline **T**. On
timeout the saga drives `AWAITING_CONFIRMATION → COMPENSATING → COMPENSATED` (subscription
`PENDING → CANCELLED`). **T** must exceed the notification service's terminal-retry envelope plus its
claim lease so the sweeper never compensates a still-in-flight send — concretely `T > (CLAIM_LEASE_SECONDS
+ Σ consumer retry-backoff)`. A secondary start-sweep over `STARTED` sagas past `T_start` is the
broker-down backstop. The saga always converges to a terminal state — never hangs, never loops.

FR11: **Compensation correctness.** C1 cancels the `PENDING` subscription consistently. If a send was
in flight at timeout, at most one welcome email may still go out (documented bound, NFR1) but the
subscription is `CANCELLED` regardless. No code path leaves a subscription `PENDING` permanently.

FR12: **Observability.** Both sides emit structured logs + metrics such that per-stage counts are
derivable: commands published, commands consumed, welcome emails sent/deduped/failed, replies
published/consumed, sagas confirmed, sagas cancelled (compensated), and timeouts swept. A single
happy-path subscribe increments published=1, sent=1, confirmed=1; a terminal-failure path increments
failed=1, cancelled=1.

FR13: **Wire-contract preservation (additive).** The existing `POST /api/subscriptions` JSON fields
`{id, email, repository, created_at}` are unchanged; a `status` field is added. The
`SendReleaseEmail/v1` contract is untouched. For gRPC, `SubscriptionReply` (`{id, email, repository,
created_at}`) gains a new additive field `string status = 5;`; stubs are regenerated and existing
gRPC tests are updated additively (existing field assertions unchanged, a `status` assertion added).
Behat scenarios stay green, with assertions adjusted only where the new `status` is asserted.

FR14: **Recipient resolution honours subscription status.** Release-email recipient resolution
(`findSubscribersByRepository`, today an unfiltered `SELECT … WHERE repository = :repository`) must
exclude non-`CONFIRMED` subscriptions — a `PENDING` or `CANCELLED` subscriber does not receive release
emails. This is a deliberate functional change to recipient selection (not additive-only), required so
the §9 semantic change holds end-to-end. A repo with one `CONFIRMED` and one `CANCELLED`/`PENDING`
subscriber resolves exactly one recipient.

### NonFunctional Requirements

NFR1: **Idempotency / at-least-once → exactly-once state.** RabbitMQ transport is at-least-once; every
step is idempotent (FR6, FR9). End-to-end the system guarantees **exactly-once state** (no
double-confirm, no double-cancel), conditional on the state-guarded transitions of FR9. The system does
**not** claim exactly-once *delivery*: two bounded, documented duplicate-email sources, each at most one
extra welcome email — (1) a worker crash between SMTP send and ledger write, (2) a send still in flight
when the timeout sweeper fires C1. Both bounds are documented; exactly-once delivery is not over-claimed.

NFR2: **Reliable saga start (dual-write).** Subscription creation is user-initiated and not
re-derivable, so the saga start needs **outbox-like reliability**: atomic subscription+saga write (FR2)
plus an outbox-style relay (FR4). This is the **one** place the system genuinely needs outbox-like
reliability — a deliberate contrast with the HW7 outbox-free, poll-driven release flow.

NFR3: **Convergence / no-hang.** The saga is guaranteed to reach a terminal state (`CONFIRMED` or
`CANCELLED`) for every subscription: terminal failure → immediate C1; no reply by deadline → swept C1.
No infinite retry loop, no permanently-`PENDING` subscription (FR10, FR11).

NFR4: **Independent deployability preserved.** No new synchronous coupling and no shared database
between the services; the only new coupling is two durable RabbitMQ messages. Each service builds,
migrates and runs against its own Postgres as today.

NFR5: **Observability.** Structured logs + metrics on both sides (FR12); a published → consumed → sent
→ replied → confirmed/cancelled funnel is derivable for operability, correlated by `sagaId`.

NFR6: **Quality gates.** Monolith `composer lint` (PHPCS PSR-12 + **deptrac**), `phpunit --testsuite
Unit`, `composer psalm` (errorLevel 1, 100% types). Notification service: its own equivalent
lint/deptrac/psalm/phpunit gates. The **deptrac baseline must not grow** (currently `{}`) — the new
saga-orchestration context fits with explicit, minimal port edges, not baseline exceptions.

NFR7: **Local dev.** `docker compose up` brings up the full stack (monolith REST, scanner,
notification consumer, the new monolith saga-worker, RabbitMQ, both Postgres, Redis, the MailHog
SMTP-capture stub) and demonstrates the saga end-to-end. No new service or database is introduced —
the saga-worker is monolith code; saga state lives in Postgres A.

### Additional Requirements

_(Architecture-derived technical requirements that shape implementation.)_

- **AR-SAGA1 — Persisted saga row is the single source of truth.** Never the broker, never an
  in-memory orchestrator. Every transition is a conditional `UPDATE … WHERE state = :expected`, a no-op
  iff `rowCount() = 0`; the broker only transports. This is what makes the saga survive a crash, broker
  outage, or redelivery. (arch section1, section2)
- **AR-SAGA2 — `EnrollmentSaga` aggregate + `SagaState` enum.** `EnrollmentSaga` extends
  `App\Shared\Domain\Aggregate\AggregateRoot` (mutable child — it advances state), identity `SagaId`,
  correlation key primitive `int $subscriptionId`, recording `SagaStarted` / `WelcomePublished` /
  `SagaCompleted` / `SagaCompensated`. `SagaState` (string-backed enum): `Started`,
  `AwaitingConfirmation`, `Completed`, `Compensating`, `Compensated`; `Started`-vs-`AwaitingConfirmation`
  **is** the FR4 publish-state marker (no separate boolean). (arch section5)
- **AR-SAGA3 — Per-consumer ISP saga ports.** `EnrollmentSagaStarter::start(int $subscriptionId)`,
  `EnrollmentSagaReader` (`dueForRelay`, `dueForSweep`, `dueForStartSweep`, `findBySubscriptionId`),
  `EnrollmentSagaWriter` (`markPublished`, `complete` [accepts `Started|AwaitingConfirmation`],
  `compensate`, `recordRelayFailure`), `WelcomeEmailRelay::publish`. Each transition returns `bool`
  (= `rowCount() > 0`). (arch section5)
- **AR-SAGA4 — Orchestrator + `SubscriptionConfirmationWriter`.** The reply-path command handler
  `HandleWelcomeEmailOutcomeHandler` wraps one Postgres-A transaction applying the paired conditional
  `UPDATE`s; the subscription `status='pending'` guard is the **true single-writer lock**, the saga-row
  update advisory. `SubscriptionConfirmationWriter::confirm(int)/cancel(int)` (Subscription.Domain port)
  is each a conditional `UPDATE … WHERE status='pending'`. A both-`rowCount()=0` reply is acked and
  dropped and increments `welcome_reply_noop_total`. (arch section5, section7)
- **AR-DEPTRAC — Boundary enforcement, baseline stays `{}`.** Three new layers (`Saga.Domain`,
  `Saga.Application`, `Saga.Infrastructure`); two opposite-direction port edges
  (`Subscription.Application → Saga.Domain` for start; `Saga.Application → Subscription.Domain` for
  confirm/cancel); the transaction boundary goes through the `Shared.Domain` `TransactionManager` port
  (no `*.Application → Shared.Infrastructure` edge). **Acyclicity invariant:** `Saga.Domain` and
  `Subscription.Domain` remain mutually independent — the crossing key is the primitive
  `int $subscriptionId`, never a foreign VO. `Apps.Monolith` allow-list gains the three layers. (arch
  section3)
- **AR-MQ1 — Welcome topology.** Two new queues + a reply binding on the existing topic exchange
  `notifications`, mirroring `notifications.send-email`. Constants (both `RabbitConnection`s):
  `ROUTING_KEY_WELCOME_EMAIL = subscription.welcome-email`, `QUEUE_WELCOME_EMAIL =
  notifications.welcome-email`, `.retry`, `.dlq`, `ROUTING_KEY_WELCOME_EMAIL_REPLY =
  subscription.welcome-email.reply`, `QUEUE_WELCOME_EMAIL_REPLY = notifications.welcome-email-reply`
  (hyphen — deliberate sibling, not a DLQ child). Reuses `notifications.dlx` + TTL-park retry. (arch
  section7)
- **AR-MQ2 — Two additive-only versioned messages.** `SendWelcomeEmail/v1` (monolith → notification;
  `correlation_id = sagaId`, `delivery_mode: 2`) and `WelcomeEmailOutcome/v1` (notification → monolith;
  `outcome ∈ {sent, failed}`, `error?`). Each has an `EXPECTED_SCHEMA` mapper gate, a golden file under
  `contracts/`, and producer/consumer contract tests; consumer tolerates unknown fields. Reply queue
  has no DLX — malformed reply is logged+acked-dropped, well-formed no-op reply acked-dropped. (arch
  section7)
- **AR-DATA1 — Saga state store (`enrollment_sagas`, Postgres A).** Migration `005`: `saga_id UUID
  UNIQUE`, `subscription_id INTEGER UNIQUE` (1:1 correlation, FR2), `state`, `awaiting_since` (primary
  sweep anchor), `attempts`/`last_error` (relay observability), `created_at` (start-sweep anchor);
  indexes `(state, created_at)` and `(state, awaiting_since)`. `ON CONFLICT (subscription_id) DO
  NOTHING` makes the in-tx start idempotent. No FK. (arch section9, section8)
- **AR-DATA2 — Subscription `status` projection.** Migration `004`: `subscriptions.status VARCHAR(16)
  NOT NULL DEFAULT 'pending'` + composite index `(repository, status)`. Multi-touch projection (C2):
  every `Subscription` read path (`PdoSubscriptionRepository::create` RETURNING + SELECT-fallback,
  `findById`, `findByEmailAndRepository`, `findByEmail`, `findAll`) selects `, status`;
  `Subscription::reconstitute` + `SubscriptionFactoryInterface::reconstitute` gain a `status` param;
  `SubscriptionResponse` + query-side response factory gain the field. (arch section9, section13)
- **AR-DATA3 — Welcome ledger (`welcome_notifications`, Postgres B).** Migration `007`: a dedicated
  table keyed `UNIQUE(subscription_id)` (one welcome per subscription) with the release-ledger
  `sent_at`/`claimed_at`/`claim_token` shape **plus** an HW9-only `terminal_failed_at` column set
  *before* the failed reply, gating re-claim. `CLAIM_LEASE_SECONDS = 300`, `MAX_REDELIVERIES = 3`. The
  welcome `claim()` returns a **`ClaimResult`** value object (mirroring the release ledger's
  `ClaimResult`, carrying the fencing `token` that `markSent`/`recordFailedAttempt` require) wrapping
  one of **four** outcome cases. **Enum decision (C2):** add the fourth case `AlreadyFailed` to the
  **shared** `App\Sending\Domain\ClaimOutcome` enum (today `Claimed`/`InFlight`/`AlreadySent`); the
  existing release-path `SendReleaseEmailHandler` reads `$claim->outcome === ClaimOutcome::X` via
  `if`-chains (not an exhaustive `match`) so the added case leaves every release branch correct.
  A bare-`ClaimOutcome` return is **not** buildable (it loses the token); the welcome ledger therefore
  returns `ClaimResult`. (arch section6, section9)
- **AR-DATA4 — Generic saga-metrics counter table (`saga_metrics`, Postgres A).** Migration `006`: a
  small generic `(metric_name PK, metric_value BIGINT)` counter table for event-shaped monolith metrics
  with no row to count — `welcome_command_published_total`, `welcome_reply_consumed_total`,
  `timeout_swept_total`, plus the `welcome_reply_noop_total` observability counter. Saga-state-derivable
  counters (`confirmed_total`, `cancelled_total`) are `Gauge`s via a new `EnrollmentSagaCountPort`. (arch
  section10)
- **AR-WORKER1 — Single saga-worker, three responsibilities.** `bin/saga-worker.php` + `SagaWorker`: one
  supervised loop modeled on `apps/notification/bin/consumer.php` (PCNTL SIGTERM/SIGINT graceful flag,
  `PCNTLHeartbeatSender`, `exit(1)` on connection-loss for supervised restart). Per tick: (1)
  `RelayPendingWelcomeEmails`, (2) `wait(timeout)` consume `WelcomeEmailOutcome`, (3) every N ticks
  `SweepTimedOutSagas` (primary `T=900s` `AwaitingConfirmation` + secondary `T_start=900s` `Started`
  start-sweep). **M4 fencing:** the monolith's dead, FQCN-identical `src/Shared/…/RabbitConsumer` must be
  **verified/replaced verbatim** against the live `apps/notification` one before wiring it at runtime —
  a P6 acceptance gate, not an assumption. (arch section10, section11)
- **AR-CONTRACT1 — `T` is a single source of truth.** `T = 900` via env `SAGA_TIMEOUT_SECONDS`
  (default 900) read by the sweeper; `T_start = SAGA_START_TIMEOUT_SECONDS` (default 900, `T_start ≥ T`).
  The inequality `T > CLAIM_LEASE_SECONDS + Σ retry-backoff` is satisfied with wide margin (effective
  floor ≈ 335s; `N_park ≤ 1` by construction). (arch section11)
- **AR-DOCS — Architecture docs sync.** `architecture.md` + the LikeC4 model in `docs/architecture/`
  (via `likec4-architecture-sync`) + ADR `0003-orchestrated-saga-subscription-confirmation.md`
  (continuing the `0001`/`0002` series) document the orchestrator, saga state model, the two messages +
  topology, the reply consumer, and the timeout sweeper. (arch section15)

### UX Design Requirements

_None._ This project has no UI surface beyond the REST/gRPC contract; there is no UX Design
Specification input. The only "interface" requirements are the **wire contracts** (the additive
`status` field on JSON and gRPC, FR13) and they are enforced as contract/Behat assertions, not as UX.
No UX-DRs apply.

### FR Coverage Map

- FR1 → **Epic B / B4, B5** (B4 adds the `status` projection + `status()` getter so `pending` is
  readable; B5 the subscription `PENDING` create + `status: pending` readable after `201`).
- FR2 → **Epic A / A1** (`enrollment_sagas` 1:1 store + `005`), **Epic A / A4** (`EnrollmentSagaStarter`
  port) + **Epic B / B5** (atomic subscription+saga write in `SubscribeCommandHandler`; `create()`
  participates in — does not nest/commit — the enclosing tx; dup-`POST` idempotent).
- FR3 → **Epic A / A4** (`EnrollmentSagaStarter` port), **Epic A / A5** (`StartEnrollmentSagaService`) +
  **Epic B / B5** (in-write-path start, not a PSR-14 listener).
- FR4 → **Epic D / D1, D2** (outbox-style relay `RabbitWelcomeEmailRelay` + `markPublished` on confirmed
  publish).
- FR5 → **Epic C / C3, C4** (`WelcomeEmailRenderer` + `SendWelcomeEmail` consumer/mapper in C3,
  `SendWelcomeEmailHandler` send flow in C4).
- FR6 → **Epic C / C2, C4** (`WelcomeNotificationLedger` claim/fence keyed `subscriptionId` in C2,
  claim-driven dedup in the handler in C4).
- FR7 → **Epic C / C5** (`RabbitWelcomeOutcomePublisher` + terminal-failure reply before DLQ).
- FR8 → **Epic D / D3** (`WelcomeEmailOutcomeConsumer` + `HandleWelcomeEmailOutcomeHandler`
  orchestrator).
- FR9 → **Epic B / B1** (writer-half conditional-`UPDATE` saga transitions), **Epic B / B3**
  (`SubscriptionConfirmationWriter` conditional confirm/cancel) ; proven in **Epic D / D3**
  (replay-3× no-op).
- FR10 → **Epic D / D4** (`SweepTimedOutSagas` primary + start-sweep, deadline `T`).
- FR11 → **Epic D / D3, D4** (C1 cancels consistently; in-flight bound) ; proven **E4**.
- FR12 → **Epic C / C6** (notification welcome metrics) + **Epic D / D5** (monolith saga metrics).
- FR13 → **Epic E / E2** (golden contracts) + **Epic E / E3** (additive JSON `status` + gRPC
  `status = 5`).
- FR14 → **Epic E / E1** (`findSubscribersByRepository` `AND status='confirmed'`).

NFR coverage: NFR1 → C2/C4/D3/D4 (ledger dedup + state-guard + replay/in-flight bounds) · NFR2 →
A1/B1/B5/D1/D2 (`enrollment_sagas` durable intent + atomic write + relay) · NFR3 → D4 (sweeper +
start-sweep) · NFR4 → A1/C1/E4 (own DB/app, two messages, no shared schema) · NFR5 → C6/D5 (metrics +
`sagaId` correlation), quality-gate note on every story · NFR6 → A2/A3 (deptrac edges; monolith baseline
`{}`, notification ruleset violation-free / no baseline file) + quality-gate note on **every** story ·
NFR7 → E4 (compose `saga-worker` + end-to-end demo).

## Epic List

### Epic A: Foundations — Migrations, Shared Kernel (SagaId / TransactionManager), Saga Domain
Lay the non-breaking foundations the whole saga stands on: the four additive migrations
(`004_add_subscription_status`, `005_create_enrollment_sagas`, `006_create_saga_metrics` on Postgres A;
`007_create_welcome_notifications` on Postgres B), the Shared-kernel `SagaId` value object and
`TransactionManager` port, the three new deptrac layers + two port edges (baseline kept `{}`), and the
pure `Saga.Domain` — `EnrollmentSaga` aggregate, `SagaState` enum, events, and the per-consumer ISP
ports. No runtime wiring, no behavior change; deptrac/psalm/unit stay green. (Phases P0–P1)
**FRs covered:** FR2, FR3 (port foundation) ; supports FR4–FR14 downstream. NFR2, NFR6.

### Epic B: Saga Persistence & Atomic Start — PDO Repos, Status Projection, Confirmation Writer
Make the saga durable and start it atomically. Build `PdoEnrollmentSagaRepository` (Starter+Reader+
Writer behind ISP aliases), `PdoTransactionManager`, the `SubscriptionConfirmationWriter` port + PDO
adapter, the multi-touch `status` projection (every `Subscription` read path + `reconstitute` + factory
+ response), and wrap `create + EnrollmentSagaStarter.start()` in one `TransactionManager.transactional`
closure inside `SubscribeCommandHandler`. After this epic a `POST` creates a `PENDING` subscription with
exactly one atomic saga row. (Phases P2–P3)
**FRs covered:** FR1, FR2, FR3 ; FR9 (writer half). NFR2, NFR6.

### Epic C: Notification Welcome Path — Topology, Ledger, Domain Ports, Handler, Outcome Publisher
Add the notification service's second consumer, its first-ever publisher, the welcome ledger and the
welcome template. Stories are ordered **ports-before-handler** (no forward deps): topology (C1) →
welcome ledger + `ClaimResult`/`AlreadyFailed` (C2) → welcome Domain ports/VOs + renderer + mapper
(`WelcomeEmail`, `WelcomeOutcomePublisher` port, `WelcomeEmailRenderer`, `SendWelcomeEmail` mapper — C3)
→ `SendWelcomeEmailHandler` send flow (C4) → outcome publisher adapter + terminal-failure reply (C5) →
metrics (C6). The flow: `SendWelcomeEmail` consume → claim (welcome ledger) → render → send → `markSent`
→ publish `WelcomeEmailOutcome{sent}`; on terminal failure `markTerminalFailed` → publish `{failed}`
(fail-closed confirm) → DLQ. All welcome Domain types reference **only `Sending\Domain`** (local
`App\Sending\Domain\EmailAddress`, no `Shared`) so `Notification.Domain: []` is preserved. (Phases P4–P5)
**FRs covered:** FR5, FR6, FR7 ; FR12 (notification half). NFR1, NFR4, NFR6.

### Epic D: Orchestrator Worker — Relay, Reply Consumer, Compensation, Timeout Sweeper
Build the monolith's first long-lived worker. `bin/saga-worker.php` + `SagaWorker` supervised loop runs
the outbox relay (`RabbitWelcomeEmailRelay` with confirms → `markPublished`), consumes
`WelcomeEmailOutcome` through `HandleWelcomeEmailOutcomeHandler` (T3 confirm / C1 compensate, ack-and-drop
no-op), and sweeps timeouts (`SweepTimedOutSagas` primary `T` + start-sweep `T_start`). Verify/replace the
dead monolith `RabbitConsumer` (M4) and wire the monolith saga metrics. Prove idempotency, compensation,
and the timeout/negative-guard tests. (Phase P6)
**FRs covered:** FR4, FR8, FR9 (proof), FR10, FR11 ; FR12 (monolith half). NFR1, NFR3, NFR5, NFR6.

### Epic E: Public Surface & Cutover — Recipient Filter, Contracts, Wire Status, Compose + Docs
Close the loop on the public surface: filter `findSubscribersByRepository` to `CONFIRMED`-only (FR14),
freeze the two messages as golden contracts with producer/consumer tests, add the additive `status`
field to the JSON response and the gRPC `SubscriptionReply` (`status = 5`, regenerated stubs, additive
Behat/gRPC assertions), wire the `saga-worker` compose service for an end-to-end demo, and sync
ADR-0003 + the LikeC4 model. (Phases P7–P10)
**FRs covered:** FR13, FR14 ; preserves the public contract additively. NFR4, NFR6, NFR7.

---

## Epic A: Foundations — Migrations, Shared Kernel (SagaId / TransactionManager), Saga Domain

**Phase:** P0–P1. **Goal:** lay the non-breaking migrations, shared kernel, deptrac wiring, and pure
Saga Domain the whole saga stands on. **Covers:** FR2, FR3 (port foundation), NFR2, NFR6.
**Depends on:** nothing (first epic; builds on the HW7 modular baseline).
**Quality gates (every story):** `composer lint` (PHPCS PSR-12 + deptrac), `./vendor/bin/phpunit
--no-coverage --testsuite Unit`, `composer psalm` (100%). Wire contracts untouched; deptrac baseline
stays `{}`.

### Story A1: Additive migrations — subscription status, enrollment_sagas, saga_metrics, welcome_notifications

As a platform maintainer,
I want the four additive SQL migrations created and runnable on a fresh DB,
So that the `status` column, the saga state store, the monolith counter table, and the welcome ledger
exist before any code references them — with existing rows defaulting to `pending`.

**Scope / files (per arch section9, section12 P0):**
- **Two distinct migration directories, two distinct runners.** Postgres A migrations live in
  `migrations/` and are applied by `make migrate` (`bin/migrate.php` in the `app` container); Postgres B
  migrations live in `apps/notification/migrations/` and are applied by `make migrate-notification`
  (`bin/migrate.php` in the `notification-svc` container). The numbers are **per-directory**, not a
  shared sequence: `migrations/` currently holds `001`–`003` (next free `004`); `apps/notification/migrations/`
  currently holds `001`–`006` (next free `007`). Do **not** place `007` in `migrations/` (Postgres A) —
  it must live under `apps/notification/migrations/` (Postgres B). **Architecture §11 says `make migrate`
  runs "`004` + `005`" — that is a stale omission of `006`; the correct Postgres-A migrate set is
  `004`–`006`.**
- **Postgres A (`migrations/`, `make migrate`):**
  - `migrations/004_add_subscription_status.sql`: `ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS
    status VARCHAR(16) NOT NULL DEFAULT 'pending'`; `CREATE INDEX IF NOT EXISTS
    idx_subscriptions_repository_status ON subscriptions(repository, status)`. (Note: `001` already
    creates `idx_subscriptions_repository`; the new `(repository, status)` composite serves the FR14
    confirmed-only recipient query and partially overlaps it — both are kept for now, the overlap is
    intentional, not a mistake.)
  - `migrations/005_create_enrollment_sagas.sql`: the saga state store — `saga_id UUID UNIQUE`,
    `subscription_id INTEGER UNIQUE`, `state VARCHAR(32) DEFAULT 'started'`, `awaiting_since`,
    `attempts`, `last_error`, `created_at`, `updated_at`; indexes `idx_enrollment_sagas_relay(state,
    created_at)` and `idx_enrollment_sagas_sweep(state, awaiting_since)`.
  - `migrations/006_create_saga_metrics.sql`: generic `(metric_name VARCHAR PRIMARY KEY, metric_value
    BIGINT NOT NULL DEFAULT 0)` counter table.
- **Postgres B (`apps/notification/migrations/`, `make migrate-notification`):**
  - `apps/notification/migrations/007_create_welcome_notifications.sql`: `welcome_notifications` keyed
    `UNIQUE(subscription_id)` with `sent_at`/`terminal_failed_at`/`last_error`/`attempt_count`/
    `claimed_at`/`claim_token`/`created_at`/`updated_at`; `idx_welcome_notifications_lookup(subscription_id)`.
- Seed the empty Saga context tree so the A2 deptrac layers resolve to real directories:
  `src/Saga/Enrollment/{Domain,Application,Infrastructure}/` (a `.gitkeep` per layer; the first real
  classes land in A4/A5). This mirrors the HW7 "module skeleton" story.
- Each migration uses `IF NOT EXISTS` and is additive — no existing column is renamed/dropped.

**Acceptance Criteria:**

**Given** a fresh Postgres A migrated by **`make migrate`** (which applies `migrations/004`, `005`, `006`)
**When** the schema is inspected
**Then** `subscriptions.status` exists `NOT NULL DEFAULT 'pending'`, `enrollment_sagas` exists with
`subscription_id` and `saga_id` both `UNIQUE`, and `saga_metrics(metric_name PK, metric_value)` exists
**And** all existing `subscriptions` rows read back `status = 'pending'`.

**Given** a fresh Postgres B migrated by **`make migrate-notification`** (which applies
`apps/notification/migrations/007`)
**When** the schema is inspected
**Then** `welcome_notifications` exists with `UNIQUE(subscription_id)` and a `terminal_failed_at` column.

**Given** a second `INSERT` for the same `subscription_id` into `enrollment_sagas` (or
`welcome_notifications`)
**When** attempted
**Then** the `UNIQUE` constraint rejects it (foundation for FR2 / FR6 idempotency).

**Given** the seeded `src/Saga/Enrollment/{Domain,Application,Infrastructure}/` tree
**When** `composer lint` runs
**Then** the three directories exist so A2's directory-collectors resolve (no behavior, no classes yet).

**Dependencies:** none.
**Quality gates:** lint+deptrac, phpunit, psalm; migration-applies-fresh-db via `make migrate`
(Postgres A: `004`–`006`) **and** `make migrate-notification` (Postgres B: `007`).

### Story A2: Three new deptrac layers + two opposite-direction port edges (baseline stays `{}`)

As a maintainer,
I want `Saga.Domain` / `Saga.Application` / `Saga.Infrastructure` declared in `deptrac.yaml` with the
two minimal port edges and the `Apps.Monolith` allow-list extended,
So that the saga context is machine-enforced from the first class, with the baseline kept `{}`.

**Scope / files (per arch section3):**
- `deptrac.yaml`: add layers `Saga.Domain` (`src/Saga/Enrollment/Domain/.*`), `Saga.Application`
  (`src/Saga/Enrollment/Application/.*`), `Saga.Infrastructure` (`src/Saga/Enrollment/Infrastructure/.*`)
  after the Scanning block.
- Ruleset edges with inline justification comments: `Saga.Domain → Shared.Domain` only;
  `Saga.Application → {Saga.Domain, Shared.Domain, Shared.Application, Subscription.Domain}`;
  `Saga.Infrastructure → {Saga.Application, Saga.Domain, Shared.Domain, Shared.Application,
  Shared.Infrastructure}`; add `Subscription.Application → Saga.Domain` (start edge); extend
  `Apps.Monolith` with the three new layers.
- `deptrac.baseline.yaml` stays `skip_violations: {}`.

**Acceptance Criteria:**

**Given** `deptrac.yaml` and the `src/Saga/Enrollment/{Domain,Application,Infrastructure}/` tree seeded
in A1
**When** `composer lint` runs
**Then** the directory-collectors resolve to real (empty-but-present) layers, deptrac exits 0, and
`deptrac.baseline.yaml` is unchanged at `skip_violations: {}`. (deptrac tolerates a layer with zero
tokens; A1's seeded tree ensures the collector paths exist.)

**Given** the two opposite-direction edges
**When** read
**Then** each carries an inline justification comment and both target a *Domain* layer (ports), so no
`Domain→Domain` or `Infra→Infra` cycle is introduced.

**Note (rule-proof deferred to A4):** the *negative* proof — a deliberate violation (a `Saga.Domain`
class importing a PDO adapter or `Subscription.Domain`) being reported as an error — cannot be
exercised at A2 because no `Saga.Domain` classes exist yet. It is asserted in **A4** (where the real
aggregate/ports exist), via a throwaway local edit reverted before commit. A2 only declares and wires
the layers/edges; A4 proves the inward-only rule and the acyclicity invariant are active.

**Dependencies:** A1 (seeds the `src/Saga/Enrollment/{Domain,Application,Infrastructure}/` tree the
collectors point at).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story A3: SagaId value object + TransactionManager port (Shared kernel)

As a platform maintainer,
I want a `SagaId` UUID value object and a `TransactionManager` port in the Shared kernel,
So that the Saga Domain can mint ids with no cross-context edge and both the atomic start and the reply
orchestrator share one transaction-boundary abstraction (no raw `PDO` in any Application layer).

**Scope / files (per arch section5):**
- `src/Shared/Domain/ValueObject/SagaId.php` — `final readonly class SagaId implements \Stringable`,
  self-validating ctor (throws `App\Shared\Domain\Exception\InvalidArgumentException` on a malformed
  UUID), `value()` / `__toString()` / `equals()` / `fromString()` / `generate()` (UUIDv4).
  **Generation mechanism:** UUIDv4 minted from `random_bytes(16)` with the version/variant bits set
  (the same pure-PHP approach the existing `UuidV4EventIdGenerator` in `Notification\Publishing` uses)
  — **no new Composer dependency** (`ramsey/uuid`/`symfony/uid` are not in `composer.json` and are not
  introduced).
- `src/Shared/Domain/TransactionManager.php` — port: `transactional(callable $work): mixed` (runs
  `$work` inside `beginTransaction()`/`commit()` with `rollBack()` on any throw).
- Mirror tests under `tests/Shared/Domain/ValueObject/` and `tests/Shared/Domain/`.

**Acceptance Criteria:**

**Given** a malformed UUID string
**When** `SagaId::fromString()` is called
**Then** it throws `InvalidArgumentException`; a valid UUID yields a `SagaId` exposing `value()` and a
matching `__toString()`, and `generate()` produces a valid UUIDv4.

**Given** the `TransactionManager` interface
**When** Psalm analyzes it
**Then** it is a `Shared.Domain` port with no Infrastructure dependency (deptrac green), 100% types,
`#[\Override]` on any interface methods.

**Dependencies:** A2 (deptrac layers, so the new files lint clean).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story A4: Saga.Domain — EnrollmentSaga aggregate, SagaState enum, events, ISP ports

As a domain modeler,
I want the pure `Saga.Domain` — the `EnrollmentSaga` aggregate, the `SagaState` enum, the four domain
events, and the per-consumer ISP ports — with no infrastructure,
So that the saga state machine and its contracts exist before any adapter, depending only on
`Shared.Domain`.

**Scope / files (per arch section5):**
- `src/Saga/Enrollment/Domain/EnrollmentSaga.php` (extends `App\Shared\Domain\Aggregate\AggregateRoot`;
  identity `SagaId`, correlation `int $subscriptionId`, holds `SagaState` + `awaitingSince`; records
  events; encodes legal transitions).
- `src/Saga/Enrollment/Domain/SagaState.php` (string-backed enum: `Started`, `AwaitingConfirmation`,
  `Completed`, `Compensating`, `Compensated`).
- `src/Saga/Enrollment/Domain/EnrollmentSagaStarter.php` (`start(int $subscriptionId): void`),
  `.../EnrollmentSagaReader.php` (`dueForRelay(int $limit): iterable`, `dueForSweep(\DateTimeImmutable
  $now, int $timeoutSeconds): iterable`, `dueForStartSweep(\DateTimeImmutable $now, int
  $startTimeoutSeconds): iterable`, `findBySubscriptionId(int): ?EnrollmentSaga`),
  `.../EnrollmentSagaWriter.php` (`markPublished(SagaId): bool`, `complete(SagaId): bool` [accepts
  `Started|AwaitingConfirmation`], `compensate(SagaId): bool` [accepts `Started|AwaitingConfirmation`],
  `recordRelayFailure(SagaId, string $error): void`), `.../WelcomeEmailRelay.php`
  (`publish(SendWelcomeEmail): void`), `.../SagaNotFoundException.php`.
- `src/Saga/Enrollment/Domain/events/{SagaStarted,WelcomePublished,SagaCompleted,SagaCompensated}.php`.
- **`SubscriptionConfirmationWriter` port interface** (the Subscription.Domain *interface only*, not the
  PDO adapter — that is B3): `src/Subscription/Subscriptions/Domain/SubscriptionConfirmationWriter.php`
  with `confirm(int $id): bool` / `cancel(int $id): bool`. Defining the **interface** here (a no-edge
  Domain port) lets A5's `HandleWelcomeEmailOutcomeHandler` skeleton typecheck against a real type
  rather than a phantom; the concrete adapter and DI wiring land in B3.
- **`ExceptionStatusMap` arm for `SagaNotFoundException`** (arch section10): add a `match` arm mapping
  `App\Saga\Enrollment\Domain\SagaNotFoundException` in
  `src/Shared/Infrastructure/Error/ExceptionStatusMap.php` (the single HTTP+gRPC map per CLAUDE.md) so an
  unmapped saga error never reaches a transport path unhandled. (The reply path is a worker, not a
  request, so this is defensive; the map stays the one place exceptions are mapped.)
- Tests under `tests/Saga/Enrollment/Domain/`.

**Acceptance Criteria:**

**Given** an `EnrollmentSaga` that records two events
**When** `pullDomainEvents()` is called
**Then** both events return in order and a second call returns an empty array (buffer drained).

**Given** the `SagaState` enum
**When** mapped against the PRD section1.1 saga lifecycle
**Then** it has exactly `Started`, `AwaitingConfirmation`, `Completed`, `Compensating`, `Compensated`,
with `Started`-vs-`AwaitingConfirmation` modelling the FR4 publish-state marker.

**Given** the Saga.Domain ports
**When** `composer lint` runs
**Then** deptrac reports `Saga.Domain → Shared.Domain` only (no edge to `Subscription.Domain`, no
infrastructure), preserving the acyclicity invariant; Psalm 100% with `#[\Override]` on implementations.

**Given** a deliberate test violation now that real `Saga.Domain` classes exist (a `Saga.Domain` class
importing a PDO adapter or `Subscription.Domain`, applied locally then reverted before commit)
**When** deptrac runs
**Then** it reports the violation as an error — proving the inward-only rule and the acyclicity
invariant declared in A2 are active (the negative proof A2 deferred to here).

**Given** `SagaNotFoundException`
**When** passed to `ExceptionStatusMap`
**Then** it resolves to a defined status (no unmapped-exception fallthrough), exercised by a unit test.

**Dependencies:** A2 (deptrac layers), A3 (`SagaId`).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story A5: Saga.Application use-case skeletons (Start, Relay, HandleOutcome, Sweep)

As an application developer,
I want the `Saga.Application` use-case classes and the reply-path CQRS command scaffolded against the
Domain ports,
So that Epic B/D adapters wire into stable application contracts and the orchestrator command is bus-ready.

**Scope / files (per arch section5):**
- `src/Saga/Enrollment/Application/Start/StartEnrollmentSagaService.php` (in-tx starter façade
  implementing the `EnrollmentSagaStarter` Domain port, delegating to the PDO repo bound later).
- `src/Saga/Enrollment/Application/Relay/RelayPendingWelcomeEmails.php` (outbox relay use-case skeleton:
  reads `dueForRelay`, publishes via `WelcomeEmailRelay`, `markPublished` on confirm, `recordRelayFailure`
  on throw).
- `src/Saga/Enrollment/Application/HandleOutcome/HandleWelcomeEmailOutcomeCommand.php` (CQRS command:
  `sagaId`, `subscriptionId`, `outcome`) + `HandleWelcomeEmailOutcomeHandler.php` (implements
  `CommandHandler<…>`; wraps one `TransactionManager.transactional`; paired conditional UPDATEs;
  both-`rowCount()=0` ⇒ success).
- `src/Saga/Enrollment/Application/Sweep/SweepTimedOutSagas.php` (sweeper use-case skeleton: primary
  `dueForSweep` + secondary `dueForStartSweep`, compensate each).
- Tests under `tests/Saga/Enrollment/Application/` (with mocked ports).

**Acceptance Criteria:**

**Given** the `HandleWelcomeEmailOutcomeHandler` with mocked writers where both conditional UPDATEs
return `rowCount() = 0`
**When** the command is handled
**Then** it returns success (no throw) — so the reply consumer can ack-and-drop a no-op (FR9).

**Given** the four use-case classes
**When** Psalm analyzes them
**Then** they depend on `Saga.Domain` + `Shared.{Domain,Application}` ports only (deptrac green), and
`Saga.Application → Subscription.Domain` is the sole cross-context edge (for confirm/cancel), 100% types.
(At A5 the edge is *declared and granted* but its only consumer — `HandleWelcomeEmailOutcomeHandler`
calling `SubscriptionConfirmationWriter` — is still mocked; the edge is *exercised by real code* in D3,
where the consumer is finalized. deptrac allows the unused grant in the interim.)

**Given** the relay/sweep skeletons
**When** unit-tested against mocked ports
**Then** the relay calls `markPublished` only after `WelcomeEmailRelay::publish` returns, and on a
publish throw calls `recordRelayFailure` and does not advance state.

**Dependencies:** A3 (`TransactionManager`), A4 (Domain ports — including the
`SubscriptionConfirmationWriter` *interface*, defined in A4 so this skeleton typechecks against a real
type; only the *interface* is needed here, the PDO adapter is B3 and is mocked in these tests).
**Quality gates:** lint+deptrac, phpunit, psalm.

---

## Epic B: Saga Persistence & Atomic Start — PDO Repos, Status Projection, Confirmation Writer

**Phase:** P2–P3. **Goal:** persist the saga, project `status` across every read path, and start the
saga atomically with the subscription in one DB transaction. **Covers:** FR1, FR2, FR3, FR9 (writer
half), NFR2, NFR6. **Depends on:** Epic A (migrations, `SagaId`, `TransactionManager`, Saga.Domain +
Application skeletons).
**Quality gates (every story):** lint+deptrac, phpunit, psalm 100%. Wire contracts preserved (JSON shape
unchanged in this epic — the `status` field surfaces additively in Epic E). Behat unchanged.

### Story B1: PdoEnrollmentSagaRepository (Starter + Reader + Writer, ISP-aliased)

As a maintainer,
I want `PdoEnrollmentSagaRepository` implementing the three saga ports with conditional-`UPDATE`
transitions,
So that saga state is durable in Postgres A and every transition is an atomic, idempotent, state-guarded
write returning `rowCount() > 0`.

**Scope / files (per arch section5, section8, section10):**
- `src/Saga/Enrollment/Infrastructure/Persistence/PdoEnrollmentSagaRepository.php` implementing
  `EnrollmentSagaStarter` + `EnrollmentSagaReader` + `EnrollmentSagaWriter` (constructed on `PDO::class`
  + `Clock`). `start()` = `INSERT enrollment_sagas(...) ON CONFLICT (subscription_id) DO NOTHING`;
  `dueForRelay` (state=`started`), `dueForSweep` / `dueForStartSweep`, `findBySubscriptionId`;
  `markPublished` (`started → awaiting_confirmation`, sets `awaiting_since`), `complete`
  (`started|awaiting_confirmation → completed`), `compensate` (`started|awaiting_confirmation →
  compensated`), `recordRelayFailure` (increments `attempts`, sets `last_error`).
- **`dueForSweep`/`dueForStartSweep` bind the passed `$now`, not SQL `NOW()`** (M5 determinism). The
  `EnrollmentSagaReader` signatures take `\DateTimeImmutable $now` + a timeout; the SQL must use that
  bound value — `WHERE state='awaiting_confirmation' AND awaiting_since < :now - make_interval(secs =>
  :timeout)` (and the `state='started' AND created_at < :now - …` analogue) — so the D4 negative-guard
  test ("not swept when `awaiting_since` newer than T") is deterministic with an injected `$now`. The
  port's `$now` parameter must **not** be ignored in favour of DB-clock `NOW()`.
- DI in `config/container.php`: `EnrollmentSagaStarter → PdoEnrollmentSagaRepository`, alias
  `EnrollmentSagaReader` / `EnrollmentSagaWriter` to it (ISP-alias).
- Integration tests under `tests/Saga/Enrollment/Infrastructure/Persistence/`.

**Acceptance Criteria:**

**Given** a saga in `awaiting_confirmation`
**When** `complete(sagaId)` runs once, then again
**Then** the first returns `true` (`rowCount()=1`) and transitions to `completed`; the second returns
`false` (`rowCount()=0`) and changes nothing (FR9 writer-side no-op).

**Given** a saga in `started` (relay's `markPublished` not yet committed)
**When** `complete(sagaId)` runs
**Then** it returns `true` (accepts `Started` as a legal pre-state — the reply-before-relay hole).

**Given** two `start()` calls for the same `subscription_id`
**When** both run
**Then** exactly one saga row exists (`ON CONFLICT (subscription_id) DO NOTHING`).

**Dependencies:** A1 (`005` table), A4 (ports). 
**Quality gates:** lint+deptrac, phpunit, psalm; migration-applies-fresh-db.

### Story B2: PdoTransactionManager over the shared PDO::class

As a maintainer,
I want `PdoTransactionManager` implementing the `TransactionManager` port over the single shared
`PDO::class`,
So that the atomic start and the reply orchestrator both run their work inside one real DB transaction
without any Application layer touching a raw `PDO`.

**Scope / files (per arch section5, section3):**
- `src/Shared/Infrastructure/Persistence/PdoTransactionManager.php` (`final readonly`, ctor `PDO`;
  `transactional(callable $work): mixed` = `beginTransaction()` → `$work()` → `commit()`, `rollBack()`
  + rethrow on any throw; nested-call safe via `inTransaction()` guard).
- DI: `TransactionManager → PdoTransactionManager(PDO::class)` — the **same** `PDO::class` instance the
  saga repo and subscription repo receive, so they enlist in the one transaction.
- Tests under `tests/Shared/Infrastructure/Persistence/`.

**Acceptance Criteria:**

**Given** a closure that throws inside `transactional()`
**When** invoked
**Then** the transaction is rolled back and the throwable propagates (no partial commit).

**Given** two PDO writes inside one `transactional()` closure sharing the bound `PDO::class`
**When** the first succeeds and the second throws
**Then** neither write is committed (atomicity — the FR2 guarantee mechanism).

**Given** deptrac
**When** `composer lint` runs
**Then** `PdoTransactionManager` is in `Shared.Infrastructure` and no `*.Application → Shared.Infrastructure`
edge exists (the boundary stays the Domain port).

**Dependencies:** A3 (`TransactionManager` port).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story B3: SubscriptionConfirmationWriter PDO adapter (conditional confirm/cancel)

As a maintainer,
I want the PDO adapter for the `SubscriptionConfirmationWriter` port (the interface was defined in A4),
So that the orchestrator can confirm/cancel a subscription as a state-guarded conditional `UPDATE` —
the `Saga.Application → Subscription.Domain` edge.

**Scope / files (per arch section5):**
- **Port interface already exists** (defined in A4):
  `src/Subscription/Subscriptions/Domain/SubscriptionConfirmationWriter.php` — `confirm(int $id): bool` /
  `cancel(int $id): bool`, each `UPDATE subscriptions SET status = :new WHERE id = :id AND status =
  'pending'` returning `rowCount() > 0`. B3 adds the **adapter + DI**, not the interface.
- `src/Subscription/Subscriptions/Infrastructure/Persistence/PdoSubscriptionConfirmationWriter.php`
  (`final readonly`, ctor `PDO::class`) — or folded into the existing `PdoSubscriptionRepository` per ISP.
- DI: `SubscriptionConfirmationWriter → PdoSubscriptionConfirmationWriter(PDO::class)`.
- Tests under `tests/Subscription/Subscriptions/Infrastructure/Persistence/`.

**Acceptance Criteria:**

**Given** a `pending` subscription
**When** `confirm(id)` runs once, then again
**Then** the first returns `true` and sets `status='confirmed'`; the second returns `false`
(`rowCount()=0`) and changes nothing (replayed `sent` is a no-op, FR9).

**Given** a `cancelled` subscription
**When** `confirm(id)` runs
**Then** it returns `false` (the `WHERE status='pending'` guard fails — no resurrection of a terminal
status).

**Given** deptrac
**When** lint runs
**Then** the port (from A4) is in `Subscription.Domain`, the adapter in `Subscription.Infrastructure`,
and the only consumer of the port is `Saga.Application` (the granted edge). (The edge is fully
*exercised by real code* once D3 finalizes `HandleWelcomeEmailOutcomeHandler` to call the concrete
adapter, closing the loop A5's "sole cross-context edge" AC opened.)

**Dependencies:** A1 (`004` status column), A4 (`SubscriptionConfirmationWriter` interface).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story B4: Subscription status projection — reads, status() getter, reconstitute, factory, response

As a maintainer,
I want every `Subscription` read path, `reconstitute`, the factory, and `SubscriptionResponse` to carry
`status`,
So that the committed `pending` status is reconstituted and available to surface (Epic E), with no read
path silently dropping the new column.

**Scope / files (per arch section9 C2, section13):**
- `src/Subscription/Subscriptions/Infrastructure/Persistence/PdoSubscriptionRepository.php`: extend the
  `SELECT id, email, repository, created_at` projection to `… , status` in **`create`** (both the
  RETURNING list and the SELECT-fallback), **`findById`**, **`findByEmailAndRepository`**, **`findByEmail`**,
  **`findAll`**. (`findSubscribersByRepository` is deliberately untouched here — its filter is Epic E.)
- **`Subscription` aggregate gains a `status` field + `status()` getter.** Today the aggregate has **no**
  `status()` getter and `subscribe()` / `reconstitute()` carry no status, so the wire path (`Subscription`
  → `SubscriptionResponseFactory::fromAggregate` → `SubscriptionResponse`) cannot surface status without
  it. Add: a `string $status` field; a `status(): string` getter; `subscribe(...)` defaults the new
  aggregate to `'pending'` (the create-path default, matching the `004` column default); `reconstitute(...)`
  threads `$row['status']`.
- `SubscriptionFactoryInterface::reconstitute(array $row)` gains the `status` parameter mapping
  `$row['status']`.
- **`SubscriptionResponseFactory::fromAggregate` passes `$subscription->status()` into the response**
  (today it reads `createdAt()` etc. but no status); `SubscriptionResponse` gains a `status` field
  (carried internally; *serialized* onto the wire in Epic E / E3). Without both the getter **and** the
  factory threading, `SubscriptionResponse` cannot be constructed with status and E3 has nothing to
  serialize.
- Update affected unit/integration tests additively.

**Acceptance Criteria:**

**Given** a freshly created subscription
**When** the query re-read in `SubscriptionController::create` runs
**Then** it sees the committed row with `status = 'pending'` (never a stale pre-insert state) — the
basis for AC1's "readable with `status: pending` immediately after `201`".

**Given** a `Subscription` built via `subscribe()` (create path, no DB read)
**When** `status()` is read
**Then** it returns `'pending'` (the default), so the create-path response carries status even before
the re-read.

**Given** each of `findById` / `findByEmailAndRepository` / `findByEmail` / `findAll`
**When** a row is reconstituted
**Then** the resulting `Subscription` / `SubscriptionResponse` carries the row's `status`
(via `status()` getter → `SubscriptionResponseFactory::fromAggregate`).

**Given** the existing JSON/gRPC wire shape
**When** the suite runs in this epic
**Then** it is still `{id, email, repository, created_at}` (the `status` field is reconstituted but not
yet serialized — surfaced additively in E2/E3), so Behat stays unchanged.

**Dependencies:** A1 (`004` status column), B3 (status semantics).
**Quality gates:** lint+deptrac, phpunit, psalm; behat-unchanged; migration-applies-fresh-db.

### Story B5: Atomic saga start in SubscribeCommandHandler (one transaction: T1 + saga)

As a maintainer,
I want `SubscribeCommandHandler` to wrap `repository->create()` and `EnrollmentSagaStarter.start()` in a
single `TransactionManager.transactional` closure on the shared `PDO`,
So that the subscription and its saga are written atomically (no subscription without a saga), a
duplicate `POST` starts no second saga, and the `POST` never blocks on the broker (FR1/FR2/FR3).

**Scope / files (per arch section5, section8, section12 P3, section13):**
- `src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommandHandler.php`: inject
  `EnrollmentSagaStarter` (Saga.Domain) + `TransactionManager` (Shared.Domain). Today the handler calls
  `$this->repository->create($subscription)` and **discards** the returned reconstituted `Subscription`;
  HW9 must **capture** it. Wrap: `(1) $created = $repository->create($subscription); $id =
  $created->id();` (RETURNING-id on insert, or the SELECT-fallback id on dup `POST` — `create()` already
  returns the right id in **both** branches, so **no change to `create()`'s signature is needed**, only
  capturing its result); `(2) $sagaStarter->start($id)`. Still record/dispatch the existing
  `SubscriptionCreated` PSR-14 event (observability listener unchanged). No broker work in the request
  thread.
- **Precondition (atomicity, FR2/FR3): `create()` must *participate in*, not *nest/commit*, the
  enclosing `TransactionManager` transaction.** Today `PdoSubscriptionRepository::create` runs `INSERT …
  ON CONFLICT (email, repository) DO NOTHING RETURNING id` (and the SELECT-fallback) on the shared
  `PDO::class` with **no explicit inner `beginTransaction`/`commit`** — so it naturally enlists in the
  outer tx the `TransactionManager` opened. The story must **verify `create()` opens/commits no inner
  transaction** that would defeat the outer one, and that the dup-`POST` SELECT-fallback **runs inside
  the same tx** (it reads its own uncommitted insert / the committed existing row). If `create()` ever
  grew an inner tx, the "forced failure between the two writes rolls back both" AC would be untestable.
- **`Subscription::id()` is `?int` (nullable).** `EnrollmentSagaStarter::start(int $subscriptionId)`
  needs a non-null int, so the handler must **assert/guard `$id !== null`** after `create()` (a committed
  row — insert or SELECT-fallback — always has an id; a null id is a programmer error and throws). State
  this so psalm's `?int → int` narrowing is explicit, not silently coerced.
- DI: `SubscribeCommandHandler` gains the two constructor dependencies; both repos share the same
  `PDO::class` (B1/B2 wiring) so they enlist in the one tx.
- Integration tests under `tests/Subscription/Subscriptions/Application/Subscribe/`.

**Acceptance Criteria:**

**Given** a valid `POST /api/subscriptions`
**When** handled
**Then** the response is `201`, the subscription is `pending`, exactly one `enrollment_sagas` row exists
in `started`, atomic with the subscription, and the request never touched the broker (FR1/FR2/FR3).

**Given** a forced failure between the subscription INSERT and the saga INSERT (e.g. `start()` throws),
**and** given `create()` participates in (does not commit) the enclosing tx
**When** the `POST` runs
**Then** the transaction rolls back — neither the subscription nor the saga is committed, and the `POST`
fails (FR3). (Untestable unless `create()` runs no inner commit — the precondition above.)

**Given** a duplicate `POST` for the same `(email, repository)`
**When** handled
**Then** `create()`'s SELECT-fallback (running in-tx) returns the existing id, the handler asserts it is
non-null, and `ON CONFLICT (subscription_id) DO NOTHING` keeps exactly one saga row — no second saga
(FR2 idempotent create).

**Dependencies:** A4/A5 (`EnrollmentSagaStarter`), A3 (`TransactionManager`), B1 (PDO saga repo), B2
(`PdoTransactionManager`), B4 (status projection so the re-read returns `pending`).
**Quality gates:** lint+deptrac, phpunit, psalm; behat-unchanged.

---

## Epic C: Notification Welcome Path — Topology, Ledger, Domain Ports, Handler, Outcome Publisher

**Phase:** P4–P5. **Goal:** stand up the notification service's welcome path — a second consumer, the
welcome ledger, the welcome template, the first-ever outcome publisher, and the welcome metrics, ordered
**ports-before-handler** (C1 topology → C2 ledger → C3 Domain ports/VOs + renderer + mapper → C4 handler
→ C5 publisher + terminal reply → C6 metrics) so no story forward-depends.
**Covers:** FR5, FR6, FR7, FR12 (notification half), NFR1, NFR4, NFR6. **Depends on:** Epic A
(`007_create_welcome_notifications`).
**Quality gates (every story):** the **service's own** lint+deptrac, phpunit, psalm 100% (+ monolith
gates where shared topology constants are touched). Service builds/migrates/runs without the monolith DB
(NFR4). Wire contracts preserved (`SendReleaseEmail/v1` untouched). **Notification deptrac note:**
`apps/notification` has a `deptrac.yaml` ruleset but **no `deptrac.baseline.yaml`** — it enforces zero
violations directly through the ruleset (`Notification.Domain: []` — depends on **nothing**, not even
`Shared`). The service-side gate is therefore "the notification deptrac ruleset stays violation-free (no
baseline file exists, none is introduced)", **not** "baseline stays `{}`" (that file does not exist on
the service side; only the monolith has `deptrac.baseline.yaml: skip_violations: {}`). All welcome Domain
types (`WelcomeNotificationLedger`, `WelcomeOutcomePublisher`, `WelcomeEmail`, `WelcomeNotificationKey`)
must reference **only `Sending\Domain`** — the local `App\Sending\Domain\EmailAddress`, never the Shared
VO — to keep `Notification.Domain: []` intact (no new cross-layer edge, arch §3).

### Story C1: Welcome topology on both RabbitConnections + second basic_consume

As a platform developer,
I want the welcome-email queue family + reply binding declared idempotently on **both**
`RabbitConnection`s and a second `basic_consume` registered in `bin/consumer.php`,
So that the welcome command/reply messages have a durable topology mirroring `notifications.send-email`,
declared once as a single source of truth on each side.

**Scope / files (per arch section7, section11):**
- `apps/notification/src/Shared/.../RabbitConnection.php` **and** monolith `src/Shared/.../RabbitConnection.php`:
  add constants `ROUTING_KEY_WELCOME_EMAIL = subscription.welcome-email`, `QUEUE_WELCOME_EMAIL =
  notifications.welcome-email`, `QUEUE_WELCOME_EMAIL_RETRY = notifications.welcome-email.retry`,
  `QUEUE_WELCOME_EMAIL_DLQ = notifications.welcome-email.dlq`, `ROUTING_KEY_WELCOME_EMAIL_REPLY =
  subscription.welcome-email.reply`, `QUEUE_WELCOME_EMAIL_REPLY = notifications.welcome-email-reply`
  (hyphen — do **not** "correct"); declare them in `assertTopology()` (work queue with
  `x-dead-letter-exchange: notifications.dlx`, TTL-park retry, plain durable reply queue, no DLX).
- `apps/notification/bin/consumer.php`: register a **second** `basic_consume` callback (welcome) beside
  the existing release one — one consumer process, two consumers.

**Acceptance Criteria:**

**Given** either side calls `assertTopology()` twice
**When** the broker is inspected
**Then** `notifications.welcome-email` (durable, DLX → `notifications.dlx`), `.retry`, `.dlq`, and the
plain durable `notifications.welcome-email-reply` exist, idempotently (no error on re-declare).

**Given** the reply queue
**When** inspected
**Then** it is bound on `subscription.welcome-email.reply` (hyphen sibling, not a dotted DLQ child) and
has no DLX of its own.

**Given** `bin/consumer.php`
**When** the worker boots
**Then** it registers two `basic_consume` callbacks (release + welcome) in one process.

**Dependencies:** none structurally (infra); sequenced first in Epic C.
**Quality gates:** service lint+deptrac, psalm, phpunit; monolith gates still green (shared constants);
compose-boots.

### Story C2: WelcomeNotificationLedger port + PDO adapter (ClaimResult, 4 ClaimOutcome cases, terminal_failed_at)

As a service developer,
I want a `WelcomeNotificationLedger` keyed by `subscriptionId` with a `terminal_failed_at` guard, whose
`claim()` returns a token-carrying `ClaimResult` resolving to one of four `ClaimOutcome` cases,
So that a welcome send is claimed/fenced idempotently (with the fencing token `markSent` requires) and a
terminally-failed welcome is never re-claimed or re-sent (FR6).

**Scope / files (per arch section6, section9):**
- **`claim()` returns `ClaimResult`, not a bare `ClaimOutcome`.** The existing release ledger's `claim()`
  returns a `ClaimResult` VO that **wraps** the `ClaimOutcome` enum **and carries the fencing `token`**
  that `markSent`/`recordFailedAttempt` require (`$claim->token()`). A bare-enum return would lose the
  token and break fencing — so the welcome ledger mirrors the release pattern: `claim(key, email):
  ClaimResult`.
- **Enum decision: add the 4th case `AlreadyFailed` to the *shared* `App\Sending\Domain\ClaimOutcome`
  enum** (today `Claimed`/`InFlight`/`AlreadySent`). The release-path `SendReleaseEmailHandler` reads
  `$claim->outcome === ClaimOutcome::X` via `if`-chains (verified: no exhaustive `match`), so the new
  case leaves every release branch correct; psalm stays green (the release handler never switches on the
  new case). Extend `ClaimResult` with an `alreadyFailed()` named constructor (token-less, like
  `alreadySent()`/`inFlight()`). The alternative — a separate `WelcomeClaimResult`/`WelcomeClaimOutcome`
  — is **rejected** here as needless duplication; one shared enum + the existing `ClaimResult` is the
  buildable choice and is stated explicitly so implementers do not invent a parallel type.
- `apps/notification/src/Sending/Domain/WelcomeNotificationKey.php` (keyed `subscriptionId` only),
  `.../WelcomeNotificationLedger.php` (port: `claim(WelcomeNotificationKey, EmailAddress): ClaimResult`,
  `markSent(..., string $claimToken): bool`, `markTerminalFailed(key, error): void`,
  `recordFailedAttempt(..., string $claimToken): void`). **`EmailAddress` here is the local
  `App\Sending\Domain\EmailAddress`** (as `NotificationLedger` already uses) — **never the Shared VO** —
  so `Notification.Domain: []` is preserved (no `Shared` edge).
- `apps/notification/src/Sending/Infrastructure/Persistence/PdoWelcomeNotificationLedger.php` — a
  near-clone of `PdoNotificationLedger` keyed on `subscription_id`, with the `INSERT … ON CONFLICT … DO
  UPDATE … WHERE sent_at IS NULL AND terminal_failed_at IS NULL AND (claimed_at IS NULL OR claimed_at <
  NOW() - INTERVAL '300 seconds') RETURNING id` claim and the `terminal_failed_at IS NULL` guard;
  returns the token-carrying `ClaimResult`.
- DI in `apps/notification/config/container.php`. Tests under
  `tests/Sending/Infrastructure/Persistence/`.

**Acceptance Criteria:**

**Given** a fresh `(subscriptionId)`
**When** `claim` runs
**Then** it returns a `ClaimResult` whose `outcome` is `ClaimOutcome::Claimed`, stamps
`claimed_at`/`claim_token`, and `->token()` yields the fencing token `markSent` will require.

**Given** a row with `sent_at IS NOT NULL`
**When** `claim` runs
**Then** the `ClaimResult` outcome is `ClaimOutcome::AlreadySent` (dedup, FR6) without re-stamping a
lease (a token-less result).

**Given** a row with `terminal_failed_at IS NOT NULL`
**When** `claim` runs
**Then** the `ClaimResult` outcome is `ClaimOutcome::AlreadyFailed` — never re-claimed, never re-sent.

**Given** the existing release-path `SendReleaseEmailHandler`
**When** `ClaimOutcome` gains `AlreadyFailed` and the suite runs
**Then** the release handler's `if`-chain branches and psalm stay green (the shared enum change is
backward-compatible for the release path).

**Given** deptrac on the service
**When** lint runs
**Then** the welcome Domain types reference only `Sending\Domain` (local `EmailAddress`, no `Shared`) and
the ruleset stays violation-free (`Notification.Domain: []` intact).

**Dependencies:** A1 (`007` table).
**Quality gates:** service lint+deptrac, phpunit, psalm; migration-applies-fresh-db.

### Story C3: Welcome Domain ports/VOs + WelcomeEmailRenderer + SendWelcomeEmail mapper

As a service developer,
I want the welcome Domain types (`WelcomeEmail` VO, `WelcomeOutcomePublisher` port), the
`WelcomeEmailRenderer` template, and the `SendWelcomeEmail` mapper/consumer — **all the ports and
collaborators the handler depends on, before the handler exists**,
So that C4's `SendWelcomeEmailHandler` wires into stable Domain ports with no forward dependency, the
welcome email renders from a distinct template, and the wire message is anti-corruption-mapped to a
Domain VO (FR5). (This is the fix for the old C3→C4/C5 forward dependency: the `WelcomeOutcomePublisher`
**port** and the renderer/mapper now precede the handler.)

**Scope / files (per arch section4, section6, section7):**
- `apps/notification/src/Sending/Domain/WelcomeEmail.php` (VO snapshot of the message — `subscriptionId`,
  local `App\Sending\Domain\EmailAddress`, `repository`, `sagaId`; **no `Shared` reference**, preserving
  `Notification.Domain: []`).
- `apps/notification/src/Sending/Domain/WelcomeOutcomePublisher.php` (**port**, defined here so the C4
  handler can depend on the interface): `publish(sagaId, subscriptionId, outcome, error?): void`. Its
  Rabbit **adapter** is C5 — only the interface lands here.
- `apps/notification/src/Sending/Infrastructure/Mail/WelcomeEmailRenderer.php` (implements `EmailRenderer`;
  subject e.g. `"Welcome — you're subscribed to {repository}"`, htmlspecialchars-escaped HTML + text;
  distinct from `ReleaseEmailRenderer`).
- `apps/notification/src/Sending/Infrastructure/Rabbit/SendWelcomeEmailConsumer.php` +
  `.../SendWelcomeEmailMessageMapper.php` (`EXPECTED_SCHEMA = 'SendWelcomeEmail/v1'`; tolerates unknown
  fields; maps wire → `WelcomeEmail` VO; malformed → DLQ). The consumer dispatches the C4 handler (wired
  in C4) — at C3 the consumer/mapper are exercised against a mocked or thin handler seam.
- DI in `apps/notification/config/container.php`. Tests under `tests/Sending/Infrastructure/` +
  `tests/Sending/Domain/`.

**Acceptance Criteria:**

**Given** a `SendWelcomeEmail/v1` payload
**When** the mapper runs
**Then** it produces a `WelcomeEmail` VO (tolerating unknown fields); a payload failing `EXPECTED_SCHEMA`
is rejected to the DLQ.

**Given** a `WelcomeEmail`
**When** `WelcomeEmailRenderer::render` runs
**Then** it returns a rendered email with the welcome subject/body, all interpolated values
htmlspecialchars-escaped, distinct from the release template.

**Given** the welcome Domain types (`WelcomeEmail`, `WelcomeOutcomePublisher`)
**When** service deptrac runs
**Then** they reference only `Sending\Domain` (local `EmailAddress`, no `Shared`), keeping
`Notification.Domain: []` violation-free.

**Dependencies:** C1 (topology + second consumer), C2 (`WelcomeNotificationKey`/ledger types the
`WelcomeEmail` flow keys on).
**Quality gates:** service lint+deptrac, phpunit, psalm.

### Story C4: SendWelcomeEmailHandler — claim → render → send → markSent (idempotent)

As a service developer,
I want the `SendWelcomeEmailHandler` use-case implementing the claim/render/send/markSent flow keyed for
welcome,
So that consuming a `SendWelcomeEmail` produces exactly one welcome email per subscription and is a
no-op on redelivery (FR5, FR6).

**Scope / files (per arch section6):**
- `apps/notification/src/Sending/Application/SendWelcomeEmailHandler.php::handle(WelcomeEmail $msg):
  void` — `(1)` key `WelcomeNotificationKey(subscriptionId)`; `(2)` `claim` (reads the C2 `ClaimResult`'s
  `outcome`, keeping `->token()` for `markSent`); `(3)` `AlreadySent` → `recordWelcomeDeduped()` +
  publish `sent` + return; `(4)` `AlreadyFailed` → publish `failed` + `nack` (the terminal-reply branch
  is completed in C5); `(5)` `InFlight` → throw (park `CLAIM_LEASE_SECONDS`); `(6)` `Claimed` → render +
  `mailer.send`, on throw `recordFailedAttempt` + rethrow (bounded retry); `(7)` `markSent($claimToken)`
  true → `recordWelcomeSent()` + publish `sent`, false (fenced) → `recordWelcomeSuperseded()` + return.
- Wire the C3 consumer to dispatch this handler; DI in `apps/notification/config/container.php`.
- Tests under `tests/Sending/Application/` (mocked ledger/mailer/renderer/`WelcomeOutcomePublisher` —
  all ports exist from C2/C3).

**Acceptance Criteria:**

**Given** a `SendWelcomeEmail` whose subscription is already `sent`
**When** the handler runs
**Then** it does not call the mailer and (via `AlreadySent`) publishes `sent`, deduped (FR6) — no second
email.

**Given** a fresh `SendWelcomeEmail`
**When** the handler runs
**Then** it claims, renders, sends via `Mailer`, `markSent` (presenting the `ClaimResult` token), and
publishes `sent` (FR5) — exactly one email.

**Given** a transient mailer failure on a `Claimed` send
**When** the handler runs
**Then** it records the attempt + `last_error`, clears the lease, and rethrows for bounded retry (FR7
transient path).

**Given** the consumer
**When** a valid welcome message arrives
**Then** it dispatches `SendWelcomeEmailHandler` and acks on success.

**Dependencies:** C2 (ledger + `ClaimResult`), C3 (`WelcomeEmail` VO, `WelcomeOutcomePublisher` port,
renderer, mapper/consumer — all the ports this handler needs, mocked in unit tests; only the
*interfaces*, not the C5 adapter, are required here).
**Quality gates:** service lint+deptrac, phpunit, psalm.

### Story C5: RabbitWelcomeOutcomePublisher — first publisher + terminal-failure reply (FR7)

As a service developer,
I want `RabbitWelcomeOutcomePublisher` (the service's first publisher) and the terminal-failure reply
mechanism,
So that every processed welcome publishes a `WelcomeEmailOutcome` reply, and a terminally-undeliverable
welcome emits exactly one `failed` reply before the DLQ (FR7).

**Scope / files (per arch section6, section7):**
- **`WelcomeOutcomePublisher` port already exists** (defined in C3,
  `apps/notification/src/Sending/Domain/WelcomeOutcomePublisher.php`, `publish(sagaId, subscriptionId,
  outcome, error?): void`). C5 adds the **adapter + terminal branch**, not the port.
- `apps/notification/src/Sending/Infrastructure/Rabbit/RabbitWelcomeOutcomePublisher.php` (implements
  `WelcomeOutcomePublisher`) — opens a dedicated confirm-mode channel (`confirm_select` +
  `set_nack_handler` + `wait_for_pending_acks` fail-closed, the `RabbitConsumer::republishDelayed`
  pattern); publishes `WelcomeEmailOutcome/v1` to `notifications` on `subscription.welcome-email.reply`.
- Terminal-failure branch in `SendWelcomeEmailHandler`/consumer: when `shouldRouteToDlq($message,
  MAX_REDELIVERIES)`, `(1)` `markTerminalFailed(key, error)`, `(2)` publish `WelcomeEmailOutcome{failed}`
  (fail-closed confirm — on unconfirmed publish, do **not** nack, exit for supervised restart), `(3)`
  `nack(requeue:false)` to DLQ.
- Tests under `tests/Sending/Infrastructure/Rabbit/`.

**Acceptance Criteria:**

**Given** a successful welcome send
**When** the handler completes
**Then** exactly one `WelcomeEmailOutcome{sent}` is published (confirmed) carrying `sagaId` +
`subscriptionId`.

**Given** a welcome whose retries are exhausted (terminal)
**When** the failure branch runs
**Then** `terminal_failed_at` is persisted **before** the reply, exactly one `WelcomeEmailOutcome{failed}`
is published (with its own confirm), then the message is `nack`ed to the DLQ (FR7).

**Given** a redelivery after a prior failed-reply-publish failure
**When** the handler runs
**Then** `claim` returns `AlreadyFailed`, it re-publishes `failed` and does **not** re-send the email
(NFR1 bound preserved).

**Dependencies:** C2 (`terminal_failed_at` / `AlreadyFailed`), C3 (`WelcomeOutcomePublisher` port),
C4 (handler), C1 (reply topology).
**Quality gates:** service lint+deptrac, phpunit, psalm.

### Story C6: Notification welcome metrics (5 NotificationMetric cases + recorder/reader/store)

As an operator,
I want the five welcome counters wired through the notification metrics machinery,
So that the notification-side funnel (consumed/sent/deduped/failed/reply-published) is derivable (FR12,
NFR5).

**Scope / files (per arch section6, section10; PRD AC7):**
- Extend `NotificationMetric` (`Sending\Infrastructure\Persistence` enum) with
  `WelcomeConsumed='welcome_consumed_total'`, `WelcomeSent='welcome_sent_total'`,
  `WelcomeDeduped='welcome_deduped_total'`, `WelcomeFailed='welcome_failed_total'`,
  `WelcomeReplyPublished='welcome_reply_published_total'`.
- Add recorder methods to `MessageProcessingStatsRecorder` (`Sending\Application`) +
  `PdoNotificationMetricsStore` (`Sending\Infrastructure\Persistence`); reader methods on
  `NotificationMetricsReader` (`Sending\Application`); rows in `MetricsService::collect`
  (`Sending\Infrastructure\Metrics`). Persist in the existing generic `notification_metrics(metric_name
  PK, metric_value)` table — no migration.
- Increment the recorders at the C4 (consumed/sent/deduped) and C5 (failed/reply-published) call sites.
  Tests under `tests/Sending/`.

**Acceptance Criteria:**

**Given** a happy-path welcome
**When** processed
**Then** `welcome_consumed_total`, `welcome_sent_total`, and `welcome_reply_published_total` each
increment by 1; `/metrics` exposes all five spellings exactly as named.

**Given** a redelivered already-sent welcome
**When** processed
**Then** `welcome_deduped_total` increments and `welcome_sent_total` does not (no second send).

**Given** a terminal failure
**When** processed
**Then** `welcome_failed_total` and `welcome_reply_published_total` increment.

**Dependencies:** C4 (consumed/sent/deduped sites), C5 (failed/reply-published sites).
**Quality gates:** service lint+deptrac, phpunit, psalm.

---

## Epic D: Orchestrator Worker — Relay, Reply Consumer, Compensation, Timeout Sweeper

**Phase:** P6. **Goal:** build the monolith's first long-lived worker — outbox relay, reply consumer
(T3/C1), and timeout sweeper — and prove idempotency, compensation, and timeout convergence.
**Covers:** FR4, FR8, FR9 (proof), FR10, FR11, FR12 (monolith half), NFR1, NFR3, NFR5, NFR6.
**Depends on:** Epic A (Saga.Application skeletons), Epic B (PDO saga repo, transaction manager,
confirmation writer), Epic C (the notification side replying).
**Quality gates (every story):** monolith lint+deptrac, phpunit, psalm 100%; deptrac baseline stays
`{}`. Wire contracts preserved.

### Story D1: RabbitWelcomeEmailRelay (outbox relay with publisher confirms) + SendWelcomeEmail serializer

As an integration developer,
I want `RabbitWelcomeEmailRelay` publishing `SendWelcomeEmail` with publisher confirms and a serializer,
So that the relay is the reliable, decoupled publisher of the welcome command from the durable saga row
(FR4).

**Scope / files (per arch section5, section7, section10):**
- `src/Saga/Enrollment/Infrastructure/Rabbit/RabbitWelcomeEmailRelay.php` (implements `WelcomeEmailRelay`;
  publishes via `RabbitPublisher` with confirms; throws on an unconfirmed publish so the saga is **not**
  advanced) + `.../SendWelcomeEmailSerializer.php` (`schema=SendWelcomeEmail/v1`, `sagaId`,
  `subscriptionId`, `email`, `repository`, `occurredAt`; `content_type: application/json`,
  `delivery_mode: 2`, AMQP `correlation_id = sagaId`).
- DI: `WelcomeEmailRelay → RabbitWelcomeEmailRelay(RabbitPublisher, SendWelcomeEmailSerializer, Logger)`.
- Tests under `tests/Saga/Enrollment/Infrastructure/Rabbit/` (mocked channel).

**Acceptance Criteria:**

**Given** a `STARTED` saga and a reachable broker
**When** the relay publishes
**Then** it publishes a `SendWelcomeEmail/v1` message (`correlation_id = sagaId`, persistent) and returns
only on broker ack.

**Given** an unconfirmed publish (negative confirm / timeout)
**When** the relay runs
**Then** it throws — so the caller does **not** advance the saga (it re-tries next tick after
`recordRelayFailure`).

**Given** deptrac
**When** lint runs
**Then** the relay is in `Saga.Infrastructure`, the `WelcomeEmailRelay` port stays in `Saga.Domain`.

**Dependencies:** A4 (`WelcomeEmailRelay` port), B1 (saga repo for `recordRelayFailure`), C1 (welcome
topology so the publish lands).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story D2: SagaWorker loop + bin/saga-worker.php — relay tick + markPublished (FR4), M4 RabbitConsumer fence

As an operator,
I want `bin/saga-worker.php` and a supervised `SagaWorker` loop that runs the relay and advances
`markPublished` on a confirmed publish, with the dead monolith `RabbitConsumer` verified/replaced,
So that the monolith has a reliable first long-lived worker driving the outbox relay (FR4) — and AC5's
broker-down case is covered (the `POST` never publishes; the relay does, once recovered).

**Scope / files (per arch section10, section11):**
- `bin/saga-worker.php` → `SagaWorker::run()`; `src/Saga/Enrollment/Infrastructure/Worker/SagaWorker.php`
  — supervised loop modeled on `apps/notification/bin/consumer.php`: PCNTL SIGTERM/SIGINT graceful flag,
  `PCNTLHeartbeatSender`, `exit(1)` on `AMQPConnectionClosedException | AMQPIOException |
  AMQPHeartbeatMissedException`. Per tick: run `RelayPendingWelcomeEmails` (publish `STARTED` sagas;
  `markPublished` on confirm; `recordRelayFailure` on throw); `wait(timeout)` (reply consumer wired in
  D3); sweep every N ticks (D4).
- **M4 fence — full collaborator set, not just one file.** Verify the dead monolith
  `src/Shared/.../RabbitConsumer` is functionally equivalent to the live
  `apps/notification/src/Shared/.../RabbitConsumer`; the safe path is to **replace the monolith copy with
  a verbatim copy** (same FQCN → deptrac unaffected). A verbatim `RabbitConsumer` copy pulls in the
  **whole `Shared\Infrastructure\Messaging\Rabbit` collaborator set it references** — enumerate and
  verify/replace each in the monolith, not `RabbitConsumer` alone:
  - the `MessageConsumer` **interface** `RabbitConsumer` implements (there is **no** `MessageConsumer`
    interface under monolith `src/` today — only `apps/notification/src/Shared/.../MessageConsumer.php`;
    the verbatim swap requires the monolith to gain the FQCN-identical interface too);
  - the retry/DLQ helpers it calls (`shouldRouteToDlq`, `requeueWithRetry` /
    `requeueWithoutRetryIncrement`, `republishDelayed` fail-closed confirms);
  - `RetryPublishFailedException` and any `MessageMapper` base / collaborators the consumer references.
  The dead monolith copies of each must be confirmed equivalent (or replaced verbatim) so the swap
  **compiles and runs**, not merely type-checks. New DI `MessageConsumer → RabbitConsumer` + `SagaWorker`
  wiring in `config/container.php`. This is the P6 acceptance gate the architecture flags — under-scoping
  it to `RabbitConsumer` alone would break the first runtime boot.

**Acceptance Criteria:**

**Given** a `STARTED` saga and a reachable broker
**When** a relay tick runs
**Then** `SendWelcomeEmail` is published (confirmed) and the saga advances to `AWAITING_CONFIRMATION`
with `awaiting_since = NOW()` (FR4); a re-tick does not re-publish the already-`AWAITING_CONFIRMATION`
saga.

**Given** the `POST` write path (AC5, **unit-level half**)
**When** the `SubscribeCommandHandler` runs
**Then** it has **no publisher dependency** and the request thread never touches the broker — provable by
unit gates here (the handler holds only `EnrollmentSagaStarter` + `TransactionManager`, no relay/
publisher). The **behavioral recovery half** of AC5 (broker down at `POST`, then recovers → next relay
tick publishes → saga reaches a terminal outcome) needs the compose stack and is proven **end-to-end in
E4**, not by D2's mocked-channel unit gates.

**Given** the M4 fence (full collaborator set)
**When** P6 verification runs
**Then** the monolith `RabbitConsumer` **and each `Shared\…\Rabbit` collaborator it references**
(`MessageConsumer` interface, retry/DLQ helpers, `RetryPublishFailedException`, mapper base) are proven
equivalent to (or replaced verbatim by) the live notification ones and the worker **boots** before being
wired at runtime (acceptance gate, not assumption).

**Dependencies:** A5 (`RelayPendingWelcomeEmails`), D1 (relay adapter), B1 (saga repo).
**Quality gates:** lint+deptrac, phpunit, psalm; compose-boots. (AC5's full behavioral proof routes to
E4's end-to-end smoke.)

### Story D3: WelcomeEmailOutcomeConsumer + orchestrator (T3 confirm / C1 compensate, idempotent)

As an integration developer,
I want the reply consumer, mapper, and `HandleWelcomeEmailOutcomeHandler` orchestrator wired into the
worker,
So that a `WelcomeEmailOutcome` advances the saga and subscription as paired conditional `UPDATE`s —
`sent → T3 CONFIRMED/COMPLETED`, `failed → C1 CANCELLED/COMPENSATED` — idempotently, and a no-op reply is
acked-and-dropped (FR8, FR9, FR11).

**Scope / files (per arch section5, section7, section10):**
- `src/Saga/Enrollment/Infrastructure/Rabbit/WelcomeEmailOutcomeConsumer.php` +
  `.../WelcomeEmailOutcomeMessageMapper.php` (`EXPECTED_SCHEMA = 'WelcomeEmailOutcome/v1'`; malformed →
  log + ack-drop; well-formed → dispatch `HandleWelcomeEmailOutcomeCommand` on the bus).
- `HandleWelcomeEmailOutcomeHandler` (from A5) finalized: one `TransactionManager.transactional` applying
  `SubscriptionConfirmationWriter::confirm/cancel` (the `WHERE status='pending'` single-writer lock) +
  `EnrollmentSagaWriter::complete/compensate`; both-`rowCount()=0` ⇒ success → consumer acks and drops +
  increments `welcome_reply_noop_total`. **This is where the `Saga.Application → Subscription.Domain`
  edge declared in A2 and claimed-sole in A5 is first *exercised by real code* — the concrete
  `SubscriptionConfirmationWriter` adapter (B3) is now called, not mocked — closing the loop A5's AC
  opened.**
- **`welcome_reply_consumed_total` increment site (M1 ownership + semantics).** The increment lives at
  **this D3 reply-consumer call-site** (every **well-formed** `WelcomeEmailOutcome` that passes
  `EXPECTED_SCHEMA` and is dispatched increments `welcome_reply_consumed_total` — **including** no-op
  replies). `welcome_reply_noop_total` is a **subset** counter incremented additionally on the
  both-`rowCount()=0` no-op path. So `consumed_total` is the funnel's "replies consumed" total and
  `consumed_total − noop_total` = state-changing replies. (D5 *wires the counter through the metrics
  store*; D3 *owns the call-site*. Malformed replies — failing the schema gate — are logged + ack-dropped
  and do **not** increment `consumed_total`.)
- DI: add `HandleWelcomeEmailOutcomeCommand → Handler` to the `InMemoryCommandBus` map; wire the consumer
  into `SagaWorker`. Tests under `tests/Saga/Enrollment/`.

**Acceptance Criteria:**

**Given** a `WelcomeEmailOutcome{sent}` for a `pending` subscription
**When** consumed
**Then** the subscription becomes `confirmed` and the saga `completed` in one transaction (T3, FR8).

**Given** a terminal `WelcomeEmailOutcome{failed}`
**When** consumed
**Then** the subscription becomes `cancelled` and the saga `compensated` (C1, FR8/FR11) — no subscription
left `pending`.

**Given** the same reply replayed 3× (or two concurrent copies)
**When** processed
**Then** exactly one transition occurs (`confirmed_total = 1` for replayed `sent`, `cancelled_total = 1`
for replayed `failed`); the extra copies are `rowCount()=0` no-ops, acked, `welcome_reply_noop_total`
increments (FR9, AC3) — backed by the conditional-`UPDATE`/`rowCount()` guard.

**Dependencies:** A5 (handler skeleton + command), B1 (saga writer), B2 (transaction manager), B3
(confirmation writer), C1 (reply topology), D2 (worker loop to host the consumer).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story D4: SweepTimedOutSagas — primary T sweep + secondary T_start start-sweep (FR10/NFR3)

As an operator,
I want the timeout sweeper compensating sagas with no reply by deadline **T**, plus a start-sweep over
never-published `STARTED` sagas at `T_start`,
So that every saga converges to a terminal state — no permanently-`PENDING` subscription — with `T`
configured as a single source of truth exceeding the retry+claim envelope (FR10, FR11, NFR3).

**Scope / files (per arch section8, section10, section11):**
- `src/Saga/Enrollment/Application/Sweep/SweepTimedOutSagas.php` finalized: primary query over
  `AWAITING_CONFIRMATION` sagas past `awaiting_since + T`; secondary query over `STARTED` sagas past
  `created_at + T_start` (the broker-down backstop, `awaiting_since` NULL). Each compensates via the
  orchestrator path (subscription `cancel` + saga `compensate`) and increments `timeout_swept_total`.
- **The sweeper obtains `now` from the injected `Clock` and passes it to
  `EnrollmentSagaReader::dueForSweep($now, T)` / `dueForStartSweep($now, T_start)`** — and B1's PDO impl
  **binds that `$now`** (not DB-clock `NOW()`), so the negative-guard test below is deterministic against
  an injected clock (M5). The port `$now` parameter is honoured end-to-end (reconciled with B1).
- `T = SAGA_TIMEOUT_SECONDS` (env, default 900), `T_start = SAGA_START_TIMEOUT_SECONDS` (env, default
  900, `T_start ≥ T`), read by the sweeper. Run every N ticks by `SagaWorker`.
- Tests under `tests/Saga/Enrollment/Application/Sweep/`.

**Acceptance Criteria:**

**Given** a saga `AWAITING_CONFIRMATION` with `awaiting_since` older than `T`
**When** the sweeper runs
**Then** it is swept to `compensated` and the subscription to `cancelled`; `timeout_swept_total`
increments (AC4 positive).

**Given** a saga still within the retry/claim envelope (`awaiting_since` newer than `T`)
**When** the sweeper runs
**Then** it is **not** swept (AC4 negative guard — no premature compensation of an in-flight send,
satisfying `T > CLAIM_LEASE_SECONDS + Σ retry-backoff`).

**Given** a never-published `STARTED` saga older than `T_start` (broker stayed down)
**When** the start-sweep runs
**Then** it is compensated so the subscription cannot hang `PENDING` forever (NFR3).

**Dependencies:** A5 (sweep skeleton), B1 (`dueForSweep`/`dueForStartSweep`/`compensate`), B3 (cancel),
D3 (compensation path).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story D5: Monolith saga metrics — saga_metrics counters + saga-state gauges (FR12/AC7)

As an operator,
I want the five monolith saga counters wired — event-shaped ones on the `saga_metrics` table, state-
shaped ones as gauges from a count port,
So that the monolith-side funnel (published/reply-consumed/confirmed/cancelled/timeout-swept) is
derivable (FR12, NFR5, AC7).

**Scope / files (per arch section10):**
- Event-shaped on the `006` `saga_metrics` table: `welcome_command_published_total` (relay),
  `welcome_reply_consumed_total` (reply consumer), `timeout_swept_total` (sweeper), plus the
  `welcome_reply_noop_total` observability counter — a small store + recorder incrementing the generic
  table, exposed as `Gauge`s by `MetricsService`.
- State-shaped gauges: a new `EnrollmentSagaCountPort` (Saga.Domain) — `confirmed_total = COUNT(*) WHERE
  state='completed'`, `cancelled_total` from `state='compensated'` — read by `MetricsService` exactly
  like `SubscriptionCountPort`/`RepositoryCountPort`.
- **Call-site ownership (cross-ref):** D5 wires the **store + recorder + reader** for these counters;
  the **increment call-sites** live where the events happen — `welcome_command_published_total` at the
  D1 relay, `welcome_reply_consumed_total` **at the D3 reply-consumer call-site** (consumed counts every
  well-formed reply incl. no-ops; `welcome_reply_noop_total` is the no-op subset), `timeout_swept_total`
  at the D4 sweeper. D5 does not introduce a second consumer — it persists the increments D3/D1/D4 make.
- Tests under `tests/Saga/` + `tests/Shared/`.

**Acceptance Criteria:**

**Given** a single happy-path subscribe end-to-end
**When** `/metrics` is scraped
**Then** `welcome_command_published_total`, `welcome_reply_consumed_total`, and `confirmed_total` each
read 1 (FR12 happy-path).

**Given** a terminal-failure path
**When** `/metrics` is scraped
**Then** `cancelled_total` (and, on a swept timeout, `timeout_swept_total`) increments (FR12 failure
path).

**Given** deptrac
**When** lint runs
**Then** `EnrollmentSagaCountPort` is a `Saga.Domain` port read by `MetricsService` (no cross-context
table reach-in); baseline stays `{}`.

**Dependencies:** A1 (`006` table), B1 (state reads), D1/D3/D4 (the incrementing call sites).
**Quality gates:** lint+deptrac, phpunit, psalm.

---

## Epic E: Public Surface & Cutover — Recipient Filter, Contracts, Wire Status, Compose + Docs

**Phase:** P7–P10. **Goal:** filter release recipients to `CONFIRMED`-only, freeze the two messages as
golden contracts, surface `status` additively on JSON + gRPC, wire the compose `saga-worker`, and sync
docs/ADR/LikeC4. **Covers:** FR13, FR14, NFR4, NFR6, NFR7 ; preserves the public contract additively.
**Depends on:** Epic B (status projection), Epic C + Epic D (the live saga to demo).
**Quality gates (every story):** lint+deptrac, phpunit, psalm 100%; Behat green (additive `status`
assertions only); contract tests; full suite where the wire shape changes.

### Story E1: Recipient-resolution filter — findSubscribersByRepository CONFIRMED-only (FR14/AC6a)

As a maintainer,
I want `findSubscribersByRepository` to select `AND status = 'confirmed'`,
So that release emails go only to confirmed subscribers — `PENDING` and `CANCELLED` subscribers are
excluded (FR14, the §9 functional change), while read/list endpoints stay unfiltered.

**Scope / files (per arch section9, section12 P7, section13):**
- `src/Subscription/Subscriptions/Infrastructure/Persistence/PdoSubscriptionRepository.php`:
  `findSubscribersByRepository` SELECT gains `AND status = 'confirmed'` — **this SELECT only** (served by
  `idx_subscriptions_repository_status` from migration `004`). Read/list SELECTs (B4) stay unfiltered, so
  owners still see their own `pending`/`cancelled` subscriptions.
- Unit + integration tests under `tests/Subscription/Subscriptions/Infrastructure/Persistence/`.

**Acceptance Criteria:**

**Given** a repository with one `CONFIRMED` and one `CANCELLED` (or `PENDING`) subscriber
**When** `findSubscribersByRepository` resolves recipients
**Then** it returns exactly the `CONFIRMED` subscriber (FR14, AC6a) — proven by test.

**Given** the read/list endpoints
**When** queried for the same owner
**Then** they still return the `pending`/`cancelled` rows (only recipient resolution is filtered, not the
reads).

**Given** deptrac + the full suite
**When** run
**Then** they pass and no other recipient path is affected.

**Dependencies:** A1 (`004` index + column), B4 (status projection in place).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story E2: Golden contracts + producer/consumer tests for the two messages

As an integration developer,
I want golden files and producer/consumer contract tests for `SendWelcomeEmail/v1` and
`WelcomeEmailOutcome/v1`,
So that both messages are frozen additive-only contracts, following the existing
`SendReleaseEmailWireContractTest` discipline.

**Scope / files (per arch section7, section12 P8):**
- `contracts/send-welcome-email.v1.json` and `contracts/welcome-email-outcome.v1.json` golden files
  (the exact §7 schemas).
- Producer test: the monolith `SendWelcomeEmailSerializer` output matches the welcome-command golden;
  the notification `RabbitWelcomeOutcomePublisher` payload matches the outcome golden.
- Consumer test: the notification `SendWelcomeEmailMessageMapper` and the monolith
  `WelcomeEmailOutcomeMessageMapper` tolerate unknown fields and reject wrong-`schema` payloads.
- Assert the `SendReleaseEmail/v1` golden is unchanged.

**Acceptance Criteria:**

**Given** the serializer/publisher output
**When** compared to the golden files
**Then** it matches exactly (`schema`, `sagaId`, `subscriptionId`, fields, `outcome` enum).

**Given** a payload with an extra unknown field
**When** the consumer mapper runs
**Then** it tolerates the unknown field; a payload with a wrong `schema` is rejected.

**Given** the `SendReleaseEmail/v1` golden
**When** the contract suite runs
**Then** it is unchanged (FR13 — release contract untouched).

**Dependencies:** D1 (serializer), C5 (publisher), C4/D3 (mappers).
**Quality gates:** lint+deptrac, phpunit, psalm; contract-tests.

### Story E3: Additive wire status — JSON field + gRPC SubscriptionReply status = 5 (FR13/AC6)

As an API maintainer,
I want a `status` field added to the `POST /api/subscriptions` JSON response and a `string status = 5`
field to the gRPC `SubscriptionReply`,
So that clients observe the saga state additively — existing fields unchanged, stubs regenerated,
Behat/gRPC assertions extended additively (FR13, AC6).

**Scope / files (per arch section12 P9, section13):**
- JSON: `SubscriptionResponse` 5th field + the query-side response factory + controller `toArray`
  serialize `status` (`pending | confirmed | cancelled`) — builds on B4's reconstituted status.
- gRPC: `proto/release_notifier.proto` — `SubscriptionReply` gains `string status = 5`; regenerate stubs
  in `generated/Grpc` + `generated/GPBMetadata`; the gRPC handler populates `status`; existing gRPC test
  assertions unchanged, a `status` assertion **added**.
- Behat: existing scenarios stay green; a `status` assertion is **added** additively where appropriate.

**Acceptance Criteria:**

**Given** `POST /api/subscriptions`
**When** the response is read
**Then** it retains `{id, email, repository, created_at}` and **adds** `status: pending` (no existing
field renamed/removed) — AC6.

**Given** the gRPC `CreateSubscription` / `GetSubscription` calls
**When** invoked with regenerated stubs
**Then** `SubscriptionReply` retains fields 1–4 and adds `status = 5`; old clients ignore the unknown
field (wire-compatible) — AC6.

**Given** the Behat + gRPC suites
**When** run
**Then** existing assertions pass unchanged and the new `status` assertions pass (additive only).

**Dependencies:** B4 (status reconstituted into the response), E1 (status semantics live end-to-end).
**Quality gates:** lint+deptrac, phpunit, psalm; behat-unchanged (additive `status` only); full suite.

### Story E4: Compose saga-worker service + end-to-end saga demo (NFR7/AC1/AC2)

As an operator,
I want a `saga-worker` docker-compose service and an end-to-end happy-path + compensation demo,
So that `docker compose up` brings up the full stack and the saga is demonstrated end-to-end with no new
service or database (NFR7, AC1, AC2).

**Scope / files (per arch section11, section12 P10):**
- `docker-compose.yml`: new `saga-worker` service (clone the `scanner` block — `build: .`, `command: php
  bin/saga-worker.php`, `env_file: [.env]`, `depends_on: postgres + rabbitmq healthy`, `restart:
  unless-stopped`). No new DB; saga state in the existing `postgres` (Postgres A). Welcome ledger `007`
  runs via the existing `make migrate-notification` / `start.sh`; `004`–`006` via `make migrate`.
- Makefile/env wiring (`SAGA_TIMEOUT_SECONDS`, `SAGA_START_TIMEOUT_SECONDS`).
- Smoke flow: `POST` → MailHog shows exactly one welcome email → subscription `confirmed`; and a forced
  terminal-failure path → subscription `cancelled`; plus the broker-down-at-`POST`-then-recover flow
  (the behavioral half of AC5 routed here from D2).

**Acceptance Criteria:**

**Given** `docker compose up` with `saga-worker`
**When** a `POST /api/subscriptions` is made
**Then** the notification service sends exactly one welcome email (visible in MailHog), publishes
`outcome: sent`, and the monolith transitions the subscription to `confirmed` / saga `completed` (AC1).

**Given** the same single happy-path subscribe (FR12 end-to-end counter check, M2)
**When** both services' `/metrics` are scraped after it settles
**Then** the funnel reads `welcome_command_published_total = 1` **and** `welcome_consumed_total = 1` /
`welcome_sent_total = 1` (notification side, C6) **and** `confirmed_total = 1` (monolith side, D5) — the
one end-to-end assertion that the `published=1, sent=1, confirmed=1` funnel holds across both sides
(C6 + D5 each assert their half; E4 asserts them together).

**Given** RabbitMQ unavailable at `POST` time, then recovered (AC5 behavioral half)
**When** the `POST` returns `201` with a persisted `STARTED` saga and the broker later comes back
**Then** the next relay tick publishes `SendWelcomeEmail` and the saga proceeds to a terminal outcome
(`confirmed` or `cancelled`) — the `POST` never failed and never touched the broker (AC5 end-to-end,
the half D2 proves only at unit level).

**Given** a terminally undeliverable welcome
**When** retries exhaust → DLQ → `outcome: failed`
**Then** the monolith transitions the subscription to `cancelled` / saga `compensated`; no subscription
left `pending` (AC2).

**Given** the compose stack
**When** inspected
**Then** no new database is introduced (saga state in Postgres A; `saga-worker` is monolith code) —
NFR4/NFR7.

**Dependencies:** D2 (`bin/saga-worker.php`), C1–C6 (notification welcome path + metrics), D3/D4/D5
(reply + sweep + monolith metrics), B5 (atomic start so the saga exists to demo).
**Quality gates:** compose-boots; monolith + service gates green; end-to-end smoke passes; cross-side
counter funnel asserts (M2).

### Story E5: ADR-0003 + LikeC4 architecture model sync (AC9)

As a maintainer,
I want ADR-0003 written and the LikeC4 model in `docs/architecture/` synced,
So that the documented architecture matches the deployed saga — orchestrator, saga state model, the two
messages + topology, the reply consumer, and the timeout sweeper (AC9).

**Scope / files (per arch section15; CLAUDE.md `likec4-architecture-sync`):**
- `docs/adr/0003-orchestrated-saga-subscription-confirmation.md` (Context / Decision / Alternatives
  considered [poll-PENDING] / Consequences [bounded duplicate, first monolith consumer] / Follow-ups
  [re-confirm path]), continuing the `0001`/`0002` series.
- `docs/architecture/` LikeC4: add the `saga-worker` container, the `Saga / Enrollment` context, the two
  new messages, the welcome consumer + outcome publisher, and the `welcome_notifications` store; show the
  publish→consume→send→reply→confirm/cancel flow. Use the `likec4-architecture-sync` skill.

**Acceptance Criteria:**

**Given** the LikeC4 model
**When** rendered/validated
**Then** it shows the `saga-worker`, the `Saga / Enrollment` context, both new messages + topology, the
welcome consumer + outcome publisher, and the welcome ledger (AC9).

**Given** ADR-0003
**When** read
**Then** it records the orchestrated-saga decision, the poll-PENDING alternative, the bounded-duplicate
and first-monolith-consumer consequences, and the deferred re-confirm follow-up.

**Given** the docs change
**When** the gates run
**Then** lint/phpunit/psalm confirm nothing broke and the LikeC4 model validates.

**Dependencies:** E1–E4 (documents the final state).
**Quality gates:** docs; run lint/phpunit/psalm; likec4-validates.

---

## Validation Summary

**FR coverage — every FR maps to at least one story (reconciled with the Requirements-Inventory FR
Coverage Map — both cite the same stories per FR):**
FR1 → B4, B5 · FR2 → A1, A4, B5 · FR3 → A4, A5, B5 · FR4 → D1, D2 · FR5 → C3, C4 · FR6 → C2, C4 · FR7 →
C5 · FR8 → D3 · FR9 → B1, B3, D3 (proof) · FR10 → D4 · FR11 → D3, D4, E4 (proof) · FR12 → C6, D5 ·
FR13 → E2, E3 · FR14 → E1. (Every FR1–FR14 covered.)

**NFR coverage:** NFR1 → C2/C4/D3/D4 (ledger dedup, state-guard, in-flight + replay bounds) · NFR2 →
A1/B1/B5/D1 (durable `enrollment_sagas` + atomic write + relay) · NFR3 → D4 (primary sweep + start-sweep
convergence) · NFR4 → A1/C1/E4 (own DB/app, two messages, no shared schema) · NFR5 → C6/D5 (funnel
metrics + `sagaId` correlation), reinforced by the quality-gate note on every story · NFR6 → A2/A3
(deptrac edges; monolith baseline `{}`, notification ruleset violation-free / no baseline file) + the
quality-gate note on **every** story · NFR7 → E4 (compose `saga-worker`, end-to-end demo, no new DB).

**AC coverage — every PRD AC (incl. AC6a) maps to a story:** AC1 → E4 (happy path, MailHog) · AC2 → E4
(compensation) · AC3 → D3 (replay-3× / concurrent no-op, counters once) · AC4 → D4 (positive sweep +
negative in-envelope guard, deterministic via injected `$now`) · AC5 → **D2 (unit-level: `POST` holds no
publisher / never touches broker) + E4 (behavioral: broker-down-then-recover relays end-to-end)** ·
AC6 → E3 (additive JSON + gRPC `status = 5`) · AC6a → E1 (CONFIRMED-only recipient resolution) · AC7 →
C6 (notification counters) + D5 (monolith counters), with the cross-side `published=1/sent=1/confirmed=1`
funnel asserted together in **E4** · AC8 → quality-gate note on every story (lint+deptrac/phpunit/psalm;
monolith baseline `{}`, notification ruleset clean) · AC9 → E5 (architecture.md + LikeC4 + ADR-0003).

**Architecture-specific items — all covered:** TransactionManager port → A3 (port) / B2 (adapter) ·
SagaId VO (`random_bytes` UUIDv4, no new dep) → A3 · two migration dirs/runners, Postgres-A `004`–`006`
via `make migrate`, Postgres-B `007` via `make migrate-notification` → A1 (and the §11 "`004`+`005`"
text flagged stale — correct set is `004`–`006`) · welcome `ClaimResult` + shared-enum `AlreadyFailed`
(release `if`-chains stay green) + `terminal_failed_at` → C2 (ledger) / C5 (terminal reply) · welcome
Domain types reference only `Sending\Domain` (local `EmailAddress`), `Notification.Domain: []` intact →
C2/C3 · `Subscription` `status()` getter + `subscribe()` default + `SubscriptionResponseFactory::
fromAggregate` threading → B4 · `SubscriptionConfirmationWriter` *port interface* → A4, *PDO adapter* →
B3, edge *exercised by real code* → D3 · `ExceptionStatusMap` arm for `SagaNotFoundException` → A4 ·
`src/Saga/Enrollment/{Domain,Application,Infrastructure}` skeleton seeded → A1 (so A2's collectors
resolve) · deptrac negative-proof (deliberate violation reported) → A4 (real classes exist) · M4
`RabbitConsumer` full collaborator-set verify/replace (incl. monolith-absent `MessageConsumer`
interface) → D2 · two deptrac edges + acyclicity invariant → A2 (declared) / A4 (kept independent) ·
`dueForSweep` binds injected `$now` (not DB `NOW()`) → B1/D4 · `T=900s` / `T_start` single source of
truth → D4 (AR-CONTRACT1) · recipient-resolution filter → E1 · golden contracts + tests → E2 · additive
JSON + gRPC status → E3 · docker-compose `saga-worker` → E4 · ADR-0003 + LikeC4 sync → E5.

**Dependency ordering (Strangler, no forward deps within an epic):**
A (no deps) → B (needs A) → C (needs A's `007`) → D (needs A/B/C) → E (needs B/C/D). Within each epic,
stories depend only on **earlier** stories (or earlier epics) per the explicit Dependencies note — e.g.
B5 depends on B1–B4 + A3–A5; **Epic C is reordered ports-before-handler so C4 (handler) depends only on
C2/C3 (ledger + Domain ports/VOs/renderer/mapper), and C5 (publisher adapter) depends on the C3
`WelcomeOutcomePublisher` *port* — removing the old C3→C4/C5 forward dependency**; D3 depends on
A5/B1–B3/C1/D2; E3 depends on B4/E1. The two opposite-direction deptrac edges are introduced together
(A2), kept acyclic by A4 keeping `Saga.Domain` independent of `Subscription.Domain` (primitive crossing
key), and the `Saga.Application → Subscription.Domain` edge is *exercised by real code* once D3 finalizes
the orchestrator (the A5 "sole cross-context edge" loop closes in D3).

**Architecture compliance:** No "create everything upfront then wire" — each migration is additive
(`status DEFAULT 'pending'`) and the saga is built Domain → Application → Infrastructure → worker → public
surface along the P0–P10 ladder, every phase independently shippable behind the existing gates. deptrac
enforces the boundaries from A2 onward; the monolith baseline stays `{}` and the notification ruleset
stays violation-free (no baseline file there). Wire contracts stay frozen through Epics A–D and change
only additively in E2/E3 (JSON `status`, gRPC `status = 5`, additive Behat/gRPC assertions);
`SendReleaseEmail/v1` is untouched throughout. 2PC is out of scope (PRD N1) and appears nowhere in the
plan.

**File-churn note:** Epics are sliced by **migration phase / bounded layer**, not by file type. The
phased sequence deliberately revisits `config/container.php` (B1/B2/B3/B5/D2/D3), `PdoSubscriptionRepository`
(B4 projection then E1 filter), `SubscribeCommandHandler` (B5), `bin/consumer.php` (C1/C3/C4),
`SendWelcomeEmailHandler` (C4/C5), and `SagaWorker` (D2/D3/D4) across stories — this is intentional
incremental wiring (port → adapter → worker tick → cut over), not avoidable churn, and matches the
architecture's P0–P10 plan. The dead monolith `RabbitConsumer` (and its full collaborator set) is
verified/replaced exactly once (D2, M4) rather than assumed working.
