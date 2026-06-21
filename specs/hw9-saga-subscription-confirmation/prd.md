---
artifact: prd
project: github-release-notifier
title: 'Orchestrated Saga — confirmed-subscription welcome-email distributed transaction'
author: valerii
date: '2026-06-20'
status: draft
related:
  [
    'specs/hw7-clean-architecture-microservices/prd.md',
    'specs/project-context.md',
    'specs/hw9-saga-subscription-confirmation/architecture.md (to be authored next)',
  ]
---

# PRD — Orchestrated Saga: Confirmed-Subscription Welcome Email

## 1. Context & Problem

`github-release-notifier` is now a two-service system (HW7): a Slim 4 + PHP-DI
**monolith** (Postgres A, db `release_notifier`) that owns subscriptions, tracked
repositories and release scanning, plus an extracted **notification service**
(Postgres B, db `release_notifications`) that consumes RabbitMQ and sends email.
Today the two services coordinate in exactly **one direction and one place**: the
scanner publishes `SendReleaseEmail` integration commands fire-and-forget, the
notification service sends, and **the monolith never learns the outcome**. The
release flow is deliberately *outbox-free* — a poll re-detects un-marked releases,
so the monolith never needs to know whether an individual email landed.

Subscription creation is the opposite story. `POST /api/subscriptions` is a
**monolith-local write only** — it inserts a row, records the existing
`SubscriptionCreated` domain event (PSR-14), and returns `201`. It sends **no
welcome email** and spans no service boundary. There is no business operation in
the system that must commit a **local write in one service** *and* a **remote
side effect in the other** as a single logical unit — which is exactly what a
distributed transaction is.

HW9 introduces one: a **"Confirmed Subscription" (double opt-in welcome email)**.
A subscription becomes valid/active only once the notification service has
successfully **dispatched** its welcome email. This single business operation now
spans **both services and both physically separate Postgres databases**, and we
implement it as an **orchestrated Saga with explicit compensation**, per the HW9
task ("Реалізувати розподілену транзакцію між двома мікросервісами за допомогою
оркестрованої Saga" — implement a distributed transaction between two
microservices using an **orchestrated** Saga).

**Why this is a genuine *orchestrated* saga.** The **orchestrator lives in the
monolith** (it owns the subscription lifecycle and the HTTP entrypoint) and keeps
**explicit persisted saga state** in Postgres A — central coordinator + durable
state is the defining trait of orchestration versus choreography. The saga follows
the canonical *compensatable → pivot → retriable* shape (Garcia-Molina;
Richardson):

- **T1** (monolith, local, **compensatable**) — create the subscription in state
  `PENDING`.
- **T2** (notification service, remote, **pivot / point-of-no-return**) — send the
  welcome email. An SMTP send is a real-world side effect with no rollback, so it
  is the pivot.
- **T3** (monolith, local, **retriable**) — on a confirmed send, transition the
  subscription `PENDING → CONFIRMED` and close the saga `COMPLETED`.
- **C1** (compensation for T1) — on a terminal send failure or timeout, transition
  the subscription `PENDING → CANCELLED` and close the saga `COMPENSATED`. This is
  a **true compensating transaction** that undoes the committed local write — not
  a withhold-commit.

### 1.1 Canonical state model

Two distinct state machines, deliberately kept separate (the saga *lifecycle* is
orchestrator bookkeeping; the *subscription status* is the user-visible business
state). These names are used consistently in every FR/AC below.

| Machine | States | Terminal states |
| --- | --- | --- |
| **Saga lifecycle** (orchestrator-owned, Postgres A) | `STARTED → AWAITING_CONFIRMATION → { COMPLETED \| COMPENSATING → COMPENSATED }` | `COMPLETED`, `COMPENSATED` |
| **Subscription status** (`subscriptions` table, Postgres A) | `PENDING → { CONFIRMED \| CANCELLED }` | `CONFIRMED`, `CANCELLED` |

Transition mapping (each step advances **both** machines as a pair):

| Step | Trigger | Saga lifecycle | Subscription status |
| --- | --- | --- | --- |
| Start | `SubscribeCommand` (same DB tx as T1) | `STARTED` | `PENDING` |
| Publish relay | `SendWelcomeEmail` published | `STARTED → AWAITING_CONFIRMATION` | `PENDING` |
| **T3** | `WelcomeEmailOutcome{sent}` | `AWAITING_CONFIRMATION → COMPLETED` | `PENDING → CONFIRMED` |
| **C1** (terminal fail) | `WelcomeEmailOutcome{failed}` | `AWAITING_CONFIRMATION → COMPENSATING → COMPENSATED` | `PENDING → CANCELLED` |
| **C1** (timeout) | sweeper, no reply by **T** | `AWAITING_CONFIRMATION → COMPENSATING → COMPENSATED` | `PENDING → CANCELLED` |

`CANCELLED` is a **subscription** status; the saga's compensated terminal is
`COMPENSATED`. The saga always converges to `COMPLETED` or `COMPENSATED` — it
never hangs and never loops.

## 2. Goals & Non-Goals

### Goals

- **G1.** Implement "create subscription + confirm it via welcome email" as a
  single **orchestrated saga** spanning the monolith and the notification service,
  with the orchestrator and its persisted state owned by the monolith.
- **G2.** Make subscription state reflect the cross-service outcome:
  `PENDING → CONFIRMED` on a dispatched welcome email, `PENDING → CANCELLED` on a
  terminal failure or timeout — a real compensating transaction, never a hung
  subscription.
- **G3.** Start the saga **reliably** despite the dual-write problem: the
  subscription row and the saga record are written atomically, and the
  `SendWelcomeEmail` command is published outbox-style, so a broker outage delays
  confirmation but never fails the `POST` nor loses the saga.
- **G4.** Make every step **idempotent under at-least-once** delivery: redelivered
  commands send no duplicate welcome email, redelivered replies cause no double
  transition (exactly-once *state*).
- **G5.** Preserve the public wire contracts **additively** — existing JSON fields,
  the gRPC reply, and existing Behat scenarios stay green; a `status` field is
  added, not substituted.
- **G6.** Keep all quality gates green on both sides (lint + deptrac, phpunit Unit,
  psalm 100%); the deptrac baseline (currently `{}`) must **not** grow.

### Non-Goals

- **N1.** No 2PC (two-phase commit) — out of scope entirely, not even a documented
  comparison in this PRD (the task asks specifically for an orchestrated saga; 2PC
  was removed from scope by the user).
- **N2.** No human double-opt-in handshake. "Confirmed" means the notification
  service **successfully dispatched** the welcome email — there is **no user
  clicking a confirmation link**. This is a backend-only saga.
- **N3.** No new product features beyond the welcome-email confirmation transaction
  itself.
- **N4.** No change to the `SendReleaseEmail/v1` release-email contract or the
  release-scan flow (the welcome email is a **new, separate** message).
- **N5.** No Kubernetes / service mesh / API gateway; no new SMTP provider; no third
  database.

## 3. Scope

### 3.1 Monolith — orchestrator (new)
- A new **saga-orchestration context** (forward-referenced; exact context name,
  classes and deptrac edges → architecture.md) that owns: the persisted saga
  instance written **in the same DB transaction as the subscription** (FR2), an
  **outbox-style publish relay** that emits `SendWelcomeEmail` from the persisted
  saga row decoupled from the request (FR4), and a **new reply consumer** (the
  monolith **runs no consumer process today** — it only publishes; a dead
  deptrac-only `RabbitConsumer` class exists but is unwired at runtime) that
  advances the saga on the reply.
- **Saga start is transactional, not listener-driven.** The saga row is created
  inside the subscription write path (command handler / transactional application
  service) so it is atomic with T1 (FR2). The existing `SubscriptionCreated`
  PSR-14 event — which is **id-less** and already has the
  `WhenSubscriptionCreatedThenLog` observability listener — is **not** the
  saga-start trigger; reusing it would be a post-commit second write (the exact
  dual-write this saga eliminates) and could not key on `subscriptionId` (the id
  is unknown at event-record time). The analogy to
  `WhenNewReleaseDetectedThenPublishReleaseEmails` is therefore **not** adopted for
  saga *start*: that listener consumes a fully-resolved `NewReleaseDetected`,
  whereas saga start needs the persisted subscription id and the same transaction.
- Subscription lifecycle gains the `PENDING / CONFIRMED / CANCELLED` states and the
  transitions T3 / C1.
- A **timeout sweeper** that compensates sagas which never receive a reply.

### 3.2 Notification service — welcome-email sender (new path)
- Consume the new `SendWelcomeEmail` command from a durable queue, render and send a
  **new welcome-email template** (reusing the existing `EmailRenderer` / `Mailer`),
  idempotent via the existing claim/fencing-token ledger pattern keyed for welcome
  emails.
- Publish a **reply** (`WelcomeEmailOutcome`) — the service runs **no publisher
  today** (its `SendReleaseEmail` consumer only acks/nacks; an exhausted-retry
  send is `nack`ed to the DLQ and silently dropped), so the publisher *and* the
  terminal-failure reply path are both net-new (FR7). The reply carries the
  outcome and the correlation id back to the monolith.

### 3.3 Shared / contracts
- Two new versioned, additive-only integration messages under `contracts/`
  (§7). New RabbitMQ topology for the welcome-email command + reply, mirroring the
  existing `notifications.send-email` topology with its own DLX/retry (mechanism →
  architecture.md).

## 4. Target Topology (high level)

```
                         POST /api/subscriptions  →  201 {…, status: PENDING}
                                    |
        ┌──────────────────────────────────────────────── MONOLITH (Postgres A) ────────┐
        |   SubscribeCommand → Subscription(PENDING)  +  Saga(STARTED)                   |
        |        (one local DB transaction: T1 + saga record, atomic — FR2)              |
        |        (saga started in the write path, NOT from a post-commit PSR-14 listener)|
        |                                                                                |
        |   ┌── outbox-style relay ──► publish SendWelcomeEmail (sagaId, reply addr) ──┐ |
        |   │                                                                          │ |
        |   │   reply consumer  ◄─────────────── WelcomeEmailOutcome (sagaId, sent|failed)
        |   │        │                                                                │ |
        |   │   advance saga: sent → T3 CONFIRMED/COMPLETED                            │ |
        |   │                 failed/timeout → C1 CANCELLED/COMPENSATED               │ |
        |   │   timeout sweeper ──► compensates sagas with no reply by deadline T     │ |
        |   └──────────────────────────────────────────────────────────────────────────┘ |
        └────────────────────────────────────────────────────────────────────────────────┘
                    │ SendWelcomeEmail                       ▲ WelcomeEmailOutcome
                    ▼ (durable, own queue + DLX/retry)       │ (durable, own queue)
        ┌──────────────────────────── RabbitMQ (topic exchange `notifications`) ─────────┐
        └────────────────────────────────────────────────────────────────────────────────┘
                    │                                        │
                    ▼                                        │
        ┌──────────────────── NOTIFICATION SERVICE (Postgres B) ─────────────────────────┐
        |   consume SendWelcomeEmail → claim (welcome ledger) → render+send welcome ──► SMTP
        |   publish WelcomeEmailOutcome(outcome=sent|failed, sagaId)                      |
        └────────────────────────────────────────────────────────────────────────────────┘
```

Two physically separate Postgres instances (A: `release_notifier` owns
`subscriptions` + saga state; B: `release_notifications` owns the welcome-email
ledger). No synchronous cross-service call; all coordination is async RabbitMQ
command + reply correlated by `sagaId`.

## 5. Functional Requirements

### Monolith — start & local steps

- **FR1.** `POST /api/subscriptions` creates the subscription in state **`PENDING`**
  (T1) and records the existing `SubscriptionCreated` domain event. The endpoint
  still returns `201` with the subscription; it does **not** block on email
  delivery. *Acceptance:* a created subscription is readable with `status: PENDING`
  immediately after `201`.

- **FR2.** The subscription row **and** a saga instance are written **atomically**
  in a single monolith-local transaction: there is no subscription without a saga
  and no saga without a subscription. The saga is persisted in Postgres A in an
  initial state (`STARTED`) keyed 1:1 to the subscription (correlation key =
  `subscriptionId`, plus a unique `sagaId`). The current create path uses
  `INSERT … ON CONFLICT (email, repository) DO NOTHING`; a **duplicate `POST`**
  (same email+repository) is **idempotent** — it returns the existing subscription
  and **starts no second saga** (the 1:1 key makes the saga-start a no-op when the
  subscription already exists). *Acceptance:* a forced failure between the two
  writes commits neither; after a `201` exactly one saga row exists for that
  subscription; a repeated `POST` for the same email+repository yields the same
  subscription and still exactly one saga row.

- **FR3.** The saga is **started inside the subscription write path** (the command
  handler / a transactional application service / the repository) **in the same DB
  transaction as T1** — *not* from a downstream PSR-14 listener. This is mandated
  by FR2's atomicity: a post-commit listener insert would be a second, non-atomic
  write, and the existing id-less `SubscriptionCreated` event (which already feeds
  the `WhenSubscriptionCreatedThenLog` observability listener) cannot key a saga on
  `subscriptionId` because the id does not exist at record time. Starting the saga
  must **not** fail or block the request thread (FR1): the in-transaction saga
  insert is the only synchronous work; the broker publish is deferred to the relay
  (FR4). *Acceptance:* creating a subscription persists a saga in `STARTED`
  atomically with the subscription, with no caller action and no dependency on the
  broker; if the saga insert fails, the whole `POST` fails and **no** subscription
  is committed.

- **FR4.** The `SendWelcomeEmail` command is published **reliably and decoupled from
  the `POST`** via an **outbox-style relay**: the persisted saga row is the source
  of truth and carries a **publish-state marker** (e.g. `command_published_at` /
  a `STARTED`-vs-`AWAITING_CONFIRMATION` distinction). A relay — running in a
  long-lived monolith worker (sweeper/consumer process, layout → architecture.md),
  **not** the request thread — publishes `SendWelcomeEmail` for saga rows that are
  `STARTED`-but-not-yet-published, and only advances the saga to
  `AWAITING_CONFIRMATION` after a **confirmed** publish (RabbitMQ publisher
  confirms); the relay is idempotent under at-least-once (a re-published welcome is
  deduped service-side, FR6). A best-effort synchronous publish attempt **may**
  fire as a fast-path, but it is **not** the guarantee: if it fails (broker down),
  the `POST` still returns `201` and the persisted saga is picked up by the relay
  once the broker recovers — a failed in-thread publish **degrades to the relay**,
  never to a failed `POST`. The command carries a correlation id (`sagaId`) and a
  reply address. *Acceptance:* see FR1/FR2 plus AC5 (broker-down).

### Notification service — pivot

- **FR5.** The notification service consumes `SendWelcomeEmail` from a durable
  queue, renders the welcome email from a **new welcome template** (distinct from
  the release-email template) and sends it via SMTP. *Acceptance:* a consumed
  command produces exactly one welcome email to the subscriber address.

- **FR6.** The welcome send is **idempotent**: before sending, the service claims
  the work in its ledger (reusing the existing claim / fencing-token
  `NotificationLedger` pattern, keyed for welcome emails); a redelivered or
  re-published command for an already-sent welcome is a no-op (deduped, no second
  email). *Acceptance:* see AC3.

- **FR7.** After processing, the service **publishes a reply**
  (`WelcomeEmailOutcome`) back to the monolith carrying the outcome
  (`sent` | `failed`), the `subscriptionId`, and the correlation id (`sagaId`). On a
  transient send failure the command is retried within the service's bounded
  retry/DLQ envelope (`MAX_REDELIVERIES = 3`, as the existing release consumer);
  only a **terminal** failure (retries exhausted) yields an `outcome: failed`
  reply. Because the service's release consumer today simply `nack`s an
  exhausted-retry message to the DLQ with **no reply emitted**, this PRD requires a
  **new terminal-failure reply mechanism** for the welcome path: on the final
  attempt the consumer **publishes `WelcomeEmailOutcome{failed}` (with its own
  fail-closed publisher confirm) before `nack`-ing to the DLQ** — so terminal
  failure produces an immediate `failed` reply (C1) rather than depending only on
  the monolith's timeout sweeper. *Acceptance:* a delivered send emits `sent`; a
  terminally undeliverable send emits exactly one `failed`.

### Monolith — reply, compensation, timeout

- **FR8.** A **new monolith reply consumer** consumes `WelcomeEmailOutcome` and
  hands it to the orchestrator, which advances the saga by correlation id:
  `sent → T3` (subscription `PENDING → CONFIRMED`, saga `COMPLETED`); terminal
  `failed → C1` (subscription `PENDING → CANCELLED`, saga `COMPENSATED`).
  *Acceptance:* see AC1 and AC2.

- **FR9.** Reply processing is **idempotent via state-guarded conditional
  transitions**. Each transition is a **conditional `UPDATE` keyed on the current
  state** — e.g. `UPDATE subscriptions SET status='CONFIRMED' WHERE id=:id AND
  status='PENDING'` (and the matching saga-row update) — and is treated as a
  **no-op iff `rowCount() = 0`**. This makes the transition safe under **concurrent
  redelivery** (two `WelcomeEmailOutcome` copies processed in parallel): exactly
  one `UPDATE` matches `status='PENDING'`, the other observes `rowCount()=0` and
  does nothing. A replayed `sent` on an already-`CONFIRMED` subscription, a
  replayed `failed` on an already-`CANCELLED` subscription, and a reply for an
  unknown or already-terminal saga are therefore all no-ops. *Acceptance:* see AC3.

- **FR10.** A **timeout sweeper** compensates sagas that never receive a reply
  within a deadline **T**. On timeout the saga drives `AWAITING_CONFIRMATION →
  COMPENSATING → COMPENSATED` (subscription `PENDING → CANCELLED`). **T** must
  exceed the notification service's terminal-retry envelope **plus** its claim
  lease so the sweeper never compensates a still-in-flight send — concretely
  `T > (NotificationLedger::CLAIM_LEASE_SECONDS + Σ consumer retry-backoff)`,
  where the source-of-truth constants are the notification service's
  `CLAIM_LEASE_SECONDS` (= 300s today) and its retry-backoff schedule across
  `MAX_REDELIVERIES = 3`. The concrete value of **T** and its single
  source-of-truth configuration are fixed in architecture.md; this PRD pins the
  **inequality**. *Acceptance:* see AC4. The saga always converges to a terminal
  state (`COMPLETED`/`CONFIRMED` or `COMPENSATED`/`CANCELLED`) — it can never hang
  forever and never loops infinitely.

### Cross-cutting

- **FR11.** **Compensation correctness.** C1 cancels the `PENDING` subscription
  consistently. If a send was in flight at timeout, **at most one** welcome email
  may still go out (documented bound, NFR1) but the subscription is `CANCELLED`
  regardless. No code path leaves a subscription `PENDING` permanently.

- **FR12.** **Observability.** Both sides emit structured logs + metrics such that
  per-stage counts are derivable: commands **published**, commands **consumed**,
  welcome emails **sent/deduped/failed**, replies **published/consumed**, sagas
  **confirmed**, sagas **cancelled (compensated)**, and **timeouts swept**.
  *Acceptance:* a single happy-path subscribe increments published=1, sent=1,
  confirmed=1; a terminal-failure path increments failed=1, cancelled=1.

- **FR13.** **Wire-contract preservation (additive).** The existing
  `POST /api/subscriptions` JSON fields `{id, email, repository, created_at}` are
  unchanged; a `status` field is **added** (§9). The `SendReleaseEmail/v1` contract
  is **untouched**. For gRPC, the proto's `SubscriptionReply` (`{id, email,
  repository, created_at}`) gains a **new additive field `string status = 5;`** so
  gRPC `CreateSubscription` / `GetSubscription` clients can observe the saga state,
  paralleling the JSON `status` addition. Adding a proto field is wire-compatible
  for protobuf (old clients ignore the unknown field) but **is** a schema change:
  the stubs are regenerated and the existing gRPC tests are updated **additively**
  (existing field assertions unchanged; a `status` assertion is added). The Behat
  scenarios stay green, with assertions adjusted **only** where the new `status` is
  asserted. *Acceptance:* see AC6.

- **FR14.** **Recipient resolution honours subscription status.** Release-email
  recipient resolution (`findSubscribersByRepository`, today an unfiltered
  `SELECT … WHERE repository = :repository`) must **exclude non-`CONFIRMED`
  subscriptions** — a `PENDING` (not-yet-confirmed) or `CANCELLED` (compensated)
  subscriber does **not** receive release emails. This is a deliberate functional
  change to recipient selection, not additive-only behaviour, and is required so
  the semantic change in §9 holds end-to-end. *Acceptance:* a repository with one
  `CONFIRMED` and one `CANCELLED`/`PENDING` subscriber for the same repo resolves
  exactly one recipient (the `CONFIRMED` one); proven by test.

## 6. Non-Functional Requirements

- **NFR1. Idempotency / at-least-once → exactly-once state.** RabbitMQ transport is
  at-least-once; every step is idempotent (FR6, FR9). End-to-end the system
  guarantees **exactly-once state** (no double-confirm, no double-cancel) —
  **conditional on the state-guarded transitions of FR9** (a conditional `UPDATE`
  with a `rowCount()` check, single-writer reply consumption); the guarantee is
  not claimed ahead of that mechanism. On the **delivery** side the system does
  **not** claim exactly-once: there are two **bounded, documented** duplicate-email
  sources, each at most **one** extra welcome email:
  1. a worker crash *between* the SMTP send and the ledger write (the same accepted
     bound as the existing release-email ledger), and
  2. a send still in flight when the timeout sweeper fires C1 (FR11 / R5) — the
     subscription is `CANCELLED` consistently but one welcome email may already be
     on its way.
  Document both bounds; do **not** over-claim exactly-once *delivery*.

- **NFR2. Reliable saga start (dual-write).** Unlike the poll-driven release flow
  (deliberately **outbox-free** because re-detection re-derives it), subscription
  creation is user-initiated and **not re-derivable**. The saga start therefore
  needs **outbox-like reliability**: atomic subscription+saga write (FR2) plus an
  outbox-style relay of the command (FR4). This is the **one** place the system
  genuinely needs outbox-like reliability — the PRD calls out the contrast with the
  HW7 outbox-free release flow deliberately.

- **NFR3. Convergence / no-hang.** The saga is guaranteed to reach a terminal state
  (`CONFIRMED` or `CANCELLED`) for every subscription: terminal failure → immediate
  C1, no reply by deadline → swept C1. There is no infinite retry loop and no
  permanently-`PENDING` subscription (FR10, FR11).

- **NFR4. Independent deployability preserved.** No new synchronous coupling and no
  shared database between the services; the only new coupling is two durable
  RabbitMQ messages. Each service builds, migrates and runs against its own Postgres
  as today.

- **NFR5. Observability.** Structured logs + metrics on both sides (FR12); a
  published → consumed → sent → replied → confirmed/cancelled funnel is derivable
  for operability.

- **NFR6. Quality gates.** Monolith: `composer lint` (PHPCS PSR-12 + **deptrac**),
  `./vendor/bin/phpunit --no-coverage --testsuite Unit`, `composer psalm`
  (errorLevel 1, 100% types). Notification service: its own equivalent
  lint/deptrac/psalm/phpunit gates. The **deptrac baseline must not grow** (currently
  `{}`) — the new saga-orchestration context fits with explicit, minimal port edges
  (enumerated in architecture.md), not baseline exceptions.

- **NFR7. Local dev.** `docker compose up` brings up the full stack (monolith REST,
  scanner, notification consumer, the new monolith reply consumer, RabbitMQ, both
  Postgres, Redis, the **MailHog** SMTP-capture stub already in `docker-compose.yml`)
  and demonstrates the saga end-to-end. No new service or database is introduced
  (the reply consumer is monolith code; saga state lives in Postgres A).

## 7. Integration Contract (summary — full schema in architecture.md)

Two **new**, versioned, additive-only messages under `contracts/`, each with its own
golden file + producer/consumer contract tests, following the existing
`send-release-email.v1.json` discipline (consumer tolerates unknown fields).

- **`SendWelcomeEmail/v1`** (monolith → notification): `{ schema, sagaId,
  subscriptionId, email, repository, occurredAt }`. Durable; own routing key + work
  queue with DLX/retry mirroring `notifications.send-email`; carries correlation id
  `= sagaId` and a reply address.
- **`WelcomeEmailOutcome/v1`** (notification → monolith): `{ schema, sagaId,
  subscriptionId, outcome: sent | failed, error?, occurredAt }`. Durable; own
  routing key + queue consumed by the monolith's new reply consumer.

The `SendReleaseEmail/v1` release-email message and its topology are **untouched**.

## 8. Data Ownership & Saga-State Store

- **D1.** Saga state is owned by the **monolith** and persisted in **Postgres A**
  (`release_notifier`), co-located with the `subscriptions` table that is the saga's
  local commit point. No third database, no saga state in the notification service.
- **D2.** A new monolith migration creates the saga-instance store (columns,
  unique/correlation keys and indexes → architecture.md). It is keyed **1:1 to the
  subscription** (`subscriptionId` correlation key + a unique `sagaId`), so a
  redelivered command/reply re-attaches to the existing saga row rather than
  duplicating it.
- **D3.** Subscription status (`PENDING / CONFIRMED / CANCELLED`) is owned by the
  monolith's `subscriptions` table (added additively, §9). The notification service
  owns only its **welcome-email ledger** in Postgres B (reusing the existing ledger
  shape, keyed for welcome emails); it stores **no saga state**.
- **D4.** No foreign key crosses the service boundary; correlation between the two
  databases is by `subscriptionId` / `sagaId` carried on the messages, exactly as the
  existing cross-service flow correlates by business key.

## 9. Semantic Change (call out explicitly)

This release introduces a **deliberate semantic change** to subscription creation:

- **Before:** `POST /api/subscriptions` created an immediately-active subscription
  (monolith-local, no email).
- **After:** the subscription is created **`PENDING`** and is **provisional** until
  the welcome email is confirmed dispatched. It becomes **`CONFIRMED`** when the
  notification service reports a successful send, or **`CANCELLED`** if the email is
  terminally undeliverable or no reply arrives by the deadline. A `CANCELLED`
  subscription is one whose confirmation failed — it does not receive release
  emails.

**Wire contract is preserved additively.** The response still returns `201` with the
existing fields `{id, email, repository, created_at}`; a **new `status` field** is
**added** (`pending | confirmed | cancelled`). No existing field is renamed, removed
or repurposed. The gRPC `SubscriptionReply` gains a matching additive field
`string status = 5;` (FR13) so gRPC clients can observe the same state; the stubs
are regenerated and gRPC tests get an additive `status` assertion. Existing Behat
scenarios stay green; only assertions that check the new `status` are added
(additively). Clients that ignore unknown fields are unaffected; clients that
previously treated a `201` as "active" must now treat it as "pending until
confirmed" — this is the intended, documented behavioral change (mirrors HW7 §9's
explicit-semantic-change call-out).

**Recipient resolution also changes (functional, not additive).** A subscription
no longer receives release emails the instant it is created: only `CONFIRMED`
subscriptions are resolved as recipients (FR14). `PENDING` subscribers (awaiting
confirmation) and `CANCELLED` subscribers (compensated) are excluded. This is a
deliberate behavioral change to `findSubscribersByRepository`, called out here so
it is not mistaken for an additive-only change.

## 10. Out of Scope / Future Phases

- **2PC (two-phase commit)** — out of scope entirely; not implemented and **not**
  compared in this PRD (the user removed it from scope; the task asks specifically
  for an orchestrated saga).
- **Human double-opt-in** (user clicks a confirmation link) — out of scope;
  "confirmed" = welcome email **dispatched** by the backend (N2).
- **Re-confirmation / resurrection** of a `CANCELLED` subscription (e.g. a
  `POST` retry or an admin re-trigger) — future phase; out of scope here.
  **Known limitation:** because `create()` uses `ON CONFLICT (email, repository)
  DO NOTHING`, a user who re-`POST`s after their subscription was `CANCELLED`
  gets the existing (stale) `CANCELLED` row back with **no new saga** (FR2's
  idempotent-create rule) and therefore **no path to re-confirm**. This is an
  accepted, documented gap for this phase, not a latent bug; a re-confirm path is
  future work.
- **Applying the saga pattern to the release flow** (making release-email delivery
  outcome-tracked) — deliberately not done; the release flow stays outbox-free
  (NFR2 contrast).
- **Generalized saga framework / process-manager library** — this PRD delivers one
  concrete saga, not a reusable engine.
- No Kubernetes / service mesh / API gateway; no new SMTP provider; no third
  database (N5).

## 11. Risks & Mitigations

- **R1. Dual-write at saga start** (subscription committed but command lost, or
  command sent but subscription rolled back) → atomic subscription+saga write (FR2)
  + outbox-style relay (FR4). Broker outage delays, never drops (AC5).
- **R2. Duplicate welcome email** (at-least-once redelivery / re-publish) →
  service-side welcome ledger dedup (FR6); accepted single-duplicate bound on a
  crash between send and ledger write documented (NFR1) — not over-claimed.
- **R3. Double or lost state transition** (redelivered reply) → state-guarded
  idempotent transitions (FR9): replays are no-ops.
- **R4. Saga hangs forever** (reply never arrives — broker/consumer down,
  message lost) → timeout sweeper compensates (FR10); convergence guaranteed
  (NFR3).
- **R5. Premature compensation of an in-flight send** (timeout fires while the send
  is still retrying) → deadline **T** > consumer retry-backoff envelope + claim
  lease (FR10); worst case one welcome email still goes out but the subscription is
  consistently `CANCELLED` (FR11, documented bound).
- **R6. Wire-contract regression** → additive-only `status` field on JSON **and**
  an additive `status = 5` proto field (wire-compatible, stubs regenerated, gRPC +
  Behat assertions extended additively — FR13); new messages are additive-only
  versioned contracts with golden files + contract tests (§7).
- **R7. deptrac baseline growth** → new context wired with explicit minimal port
  edges (architecture.md); baseline stays `{}` (NFR6).
- **R8. New operational surface** (monolith gains its first long-lived consumer) →
  reply consumer follows the notification service's supervised-consumer pattern
  (heartbeat / restart); mechanism in architecture.md.

## 12. Acceptance Criteria (definition of done)

- **AC1. Happy path.** `POST /api/subscriptions` → subscription created `PENDING`
  (`201`) → notification service consumes `SendWelcomeEmail`, sends exactly one
  welcome email (verifiable via the MailHog SMTP-capture stub) → publishes
  `outcome: sent` →
  monolith reply consumer transitions the subscription to **`CONFIRMED`** and the
  saga to `COMPLETED`. Demonstrated end-to-end via docker compose.
- **AC2. Compensation path.** When the welcome email is **terminally** undeliverable
  (notification service exhausts retries → DLQ → `outcome: failed`), the monolith
  transitions the subscription to **`CANCELLED`** and the saga to `COMPENSATED`. No
  subscription is left `PENDING`.
- **AC3. Idempotency (observable invariant).** A redelivered `SendWelcomeEmail`
  sends **no duplicate** welcome email; a redelivered `WelcomeEmailOutcome` causes
  **no double transition**. Proven by an automated test that **replays the same
  reply 3×** and asserts an *observable*: the subscription ends in exactly one
  terminal status and the corresponding counter increments **exactly once**
  (`confirmed_total = 1` for a replayed `sent`, `cancelled_total = 1` for a
  replayed `failed`) — backed by the conditional-`UPDATE` / `rowCount()` guard of
  FR9, so concurrent redelivery cannot double-transition.
- **AC4. Timeout → compensation (with negative guard).** Proven by **two** tests:
  (1) a saga that receives no reply within deadline **T** is swept to
  `COMPENSATED` (subscription `CANCELLED`); (2) a **negative** test that a saga
  still within the retry/claim envelope (before **T**) is **not** swept — guarding
  against premature compensation (R5). The deadline satisfies
  `T > (CLAIM_LEASE_SECONDS + Σ retry backoff)` with the notification service's
  `CLAIM_LEASE_SECONDS` and `MAX_REDELIVERIES = 3` retry schedule as the
  source-of-truth constants.
- **AC5. Broker down at POST.** With RabbitMQ unavailable at request time,
  `POST /api/subscriptions` still returns `201` with the subscription `PENDING` and
  a persisted saga; once the broker recovers the `SendWelcomeEmail` command is
  relayed and the saga proceeds to a terminal outcome (subscription `CONFIRMED` or
  `CANCELLED`). Proven by test.
- **AC6. Wire contract preserved (additive).** The `POST /api/subscriptions`
  response retains `{id, email, repository, created_at}` and adds `status`; the
  gRPC `SubscriptionReply` retains fields 1–4 and adds `status = 5` (regenerated
  stubs; existing gRPC test assertions unchanged, a `status` assertion added);
  existing Behat scenarios pass, with `status` assertions added additively; the
  `SendReleaseEmail/v1` golden contract is unchanged.
- **AC6a. Recipient resolution.** For a repository with one `CONFIRMED` and one
  `CANCELLED` (or `PENDING`) subscriber, release-email recipient resolution
  returns exactly the `CONFIRMED` subscriber (FR14). Proven by test.
- **AC7. Observability.** Per-stage counts are derivable from metrics/logs on both
  sides (FR12), with named counters: monolith `welcome_command_published_total`,
  `welcome_reply_consumed_total`, `confirmed_total`, `cancelled_total`,
  `timeout_swept_total`; notification service `welcome_consumed_total`,
  `welcome_sent_total`, `welcome_deduped_total`, `welcome_failed_total`,
  `welcome_reply_published_total` (exact metric-system spelling → architecture.md;
  the notification side requires a new `NotificationMetric` enum case + recorder +
  `notification_metrics` row, i.e. real schema/migration work, not free).
- **AC8. Quality gates green.** Monolith `composer lint` + `phpunit --testsuite
  Unit` + `composer psalm` (100%) pass; the notification service's equivalent gates
  pass; the **deptrac baseline does not grow** (stays `{}`).
- **AC9. Architecture docs updated.** The HW9 `architecture.md` (and the LikeC4
  model + an ADR) document the orchestrator, saga state model, the two new messages
  + topology, the reply consumer, and the timeout sweeper.

## Open Questions / Assumptions

- **[ASSUMPTION]** The subscription `status` is surfaced on the `POST` response and
  on subscription reads (`GET` / list) as a string enum
  `pending | confirmed | cancelled` (read endpoints expose it additively).
  Recipient resolution **filters** on it server-side regardless of what reads
  display (FR14). The exact enum spelling and precise field serialization are
  finalized in architecture.md.
- **[ASSUMPTION]** The timeout deadline **T** is a configurable value (env/setting)
  derived from — and required to exceed — the notification service's retry-backoff
  envelope + claim lease; the concrete number and its single source of truth are
  fixed in architecture.md (this PRD only pins the *relationship*).
- **[ASSUMPTION]** Only the **process/entrypoint layout** of the long-lived
  monolith workers is open (fold the reply consumer + outbox relay + timeout
  sweeper into the scanner process vs. a new `bin/` worker) — an architecture.md
  decision. The **existence** of the outbox relay (FR4), the reply consumer (FR8)
  and the timeout sweeper (FR10) is **not** optional; they are required FRs, not
  open questions.
- **[OPEN]** Whether a `CANCELLED` subscription is hard-deleted, soft-kept for audit,
  or eligible for a future re-confirm is deferred (§10 future phase); this PRD
  assumes it is **kept** with `status: cancelled` and excluded from release-email
  recipient resolution (FR14). The `CANCELLED`-resurrection gap under
  `ON CONFLICT DO NOTHING` is a documented limitation (§10), not an open question.

---

*Grounding note:* the distributed-transaction synthesis analyzed **both**
candidate transactions and ranked the **release-flow** candidate #1; the user
deliberately chose the **subscription-confirmation** candidate instead. The
synthesis is used here only for **infra grounding and saga mechanics**, never for
the candidate choice — see architecture.md for the cited file:line maps.
