---
artifact: implementation-readiness-report
project: github-release-notifier
author: valerii
date: '2026-06-20'
status: complete
stepsCompleted:
  - 'step-01-document-discovery'
  - 'step-02-prd-analysis'
  - 'step-03-epic-coverage-validation'
  - 'step-04-dependency-order-validation'
  - 'step-05-ux-alignment'
  - 'step-06-brownfield-reality-cross-check'
inputDocuments:
  - 'specs/hw9-saga-subscription-confirmation/prd.md'
  - 'specs/hw9-saga-subscription-confirmation/architecture.md'
  - 'specs/hw9-saga-subscription-confirmation/epics.md'
  - 'CLAUDE.md'
codeChecked:
  - 'src/'
  - 'migrations/'
  - 'apps/notification/'
  - 'config/container.php'
  - 'deptrac.yaml'
  - 'deptrac.baseline.yaml'
  - 'docker-compose.yml'
  - 'proto/release_notifier.proto'
  - 'contracts/'
  - 'bin/'
  - 'docs/adr/'
overallVerdict: 'Ready-with-conditions'
---

# Implementation Readiness Assessment Report

**Date:** 2026-06-20
**Project:** github-release-notifier
**Assessor:** valerii (BMad Implementation Readiness workflow)

> **Overall verdict: READY-WITH-CONDITIONS.** The HW9 orchestrated-saga PRD,
> architecture, and epics are exceptionally well-aligned, fully traceable, and
> dependency-ordered along the P0→P10 Strangler ladder, and the brownfield
> baseline in `src/`, `migrations/`, `apps/notification/`, `deptrac.yaml`,
> `docker-compose.yml`, and `proto/` matches what the docs claim. All 14 FRs, 7
> NFRs, and 10 ACs (incl. AC6a) trace to at least one story — **100% coverage,
> no orphans.** There are **no blockers.** A small set of **should-fix** items
> (one stale `make migrate` claim that the docs themselves already flag, one
> internal AC-numbering note, and two testability tightenings) should be resolved
> or explicitly accepted before the affected epics; none gate the start of Epic A.

---

## 1. Document Discovery

| Document | File | Status |
|---|---|---|
| PRD | `specs/hw9-saga-subscription-confirmation/prd.md` | Found (whole, ~24 KB) |
| Architecture | `specs/hw9-saga-subscription-confirmation/architecture.md` | Found (whole, 1037 lines) |
| Epics & Stories | `specs/hw9-saga-subscription-confirmation/epics.md` | Found (whole, 1668 lines) |
| Conventions | `CLAUDE.md` | Found |
| UX Spec | — | Not found (correctly N/A — see §5) |

- **No duplicates** (no whole+sharded conflict) for any document type.
- **No required document missing.** UX is intentionally absent (headless
  REST + gRPC + CLI-worker system) — the epics explicitly record "UX Design
  Requirements: None" and fold the only "interface" concern (the additive
  `status` wire field) into FR13.
- All three HW9 specs share consistent YAML frontmatter (`author: valerii`,
  `date: 2026-06-20`, `status: draft`) and cross-reference each other plus
  `specs/project-context.md`.
- Brownfield reality cross-check performed against the live code (results in §6).

---

## 2. PRD Analysis (requirements extracted)

### Functional Requirements (14)
- **FR1** `POST /api/subscriptions` creates the subscription `PENDING` (T1), records the existing `SubscriptionCreated` event, returns `201`, does not block on email; readable `status: PENDING` after `201`.
- **FR2** Subscription row **and** saga instance written atomically in one monolith-local tx; saga keyed 1:1 (`subscriptionId` + unique `sagaId`); duplicate `POST` is idempotent, starts no second saga.
- **FR3** Saga started **inside the write path** in T1's tx — not from a PSR-14 listener (id-less `SubscriptionCreated` cannot key on `subscriptionId`); saga insert must not block the request; its failure fails the whole `POST`.
- **FR4** `SendWelcomeEmail` published reliably + decoupled via an **outbox-style relay** from the persisted `STARTED` row; advances to `AWAITING_CONFIRMATION` only on confirmed publish; broker outage delays, never fails the `POST`.
- **FR5** Notification service consumes `SendWelcomeEmail`, renders a **new welcome template**, sends via SMTP — exactly one email per command.
- **FR6** Welcome send **idempotent** via the claim/fencing-token ledger keyed for welcome; redelivery is a no-op.
- **FR7** Service **publishes a reply** (`WelcomeEmailOutcome{sent|failed}`) — net-new publisher + terminal-failure reply (publish `failed` before `nack` to DLQ).
- **FR8** **New monolith reply consumer** advances the saga by correlation id: `sent → T3` (CONFIRMED/COMPLETED), terminal `failed → C1` (CANCELLED/COMPENSATED).
- **FR9** Reply processing **idempotent via state-guarded conditional `UPDATE`** (no-op iff `rowCount()=0`); safe under concurrent redelivery; replays + unknown/terminal sagas are no-ops.
- **FR10** **Timeout sweeper** compensates sagas with no reply by deadline **T**; `T > CLAIM_LEASE_SECONDS + Σ retry-backoff`; secondary start-sweep at `T_start`; always converges.
- **FR11** **Compensation correctness** — C1 cancels consistently; at most one in-flight welcome email may still go out; no permanently-`PENDING` subscription.
- **FR12** **Observability** — both sides emit structured logs + metrics; per-stage funnel counts derivable.
- **FR13** **Wire-contract preservation (additive)** — JSON `{id,email,repository,created_at}` + new `status`; gRPC `SubscriptionReply` + `string status = 5`; `SendReleaseEmail/v1` untouched; Behat/gRPC assertions additive.
- **FR14** **Recipient resolution honours status** — `findSubscribersByRepository` excludes non-`CONFIRMED`; deliberate functional (non-additive) change.

### Non-Functional Requirements (7)
- **NFR1** Idempotency / at-least-once → exactly-once **state** (not delivery); two documented bounded one-extra-email sources.
- **NFR2** Reliable saga start (dual-write) — outbox-like reliability; the one place the system needs it (HW7 release flow stays outbox-free).
- **NFR3** Convergence / no-hang — terminal `failed` → immediate C1; missing reply → swept; no infinite loop.
- **NFR4** Independent deployability preserved — no shared DB, only two durable messages.
- **NFR5** Observability — published→consumed→sent→replied→confirmed/cancelled funnel, `sagaId`-correlated.
- **NFR6** Quality gates — monolith `lint`(+deptrac)/phpunit/psalm; notification equivalent; **deptrac baseline stays `{}`**.
- **NFR7** Local dev — `docker compose up` brings up the full stack (incl. new monolith `saga-worker`, reused MailHog); no new service/DB.

### Acceptance Criteria (10: AC1–AC9 + AC6a)
AC1 happy path → CONFIRMED · AC2 compensation → CANCELLED · AC3 idempotency (replay-3×, counter once) · AC4 timeout→compensation **with negative guard** · AC5 broker-down at `POST` → 201 + relay-on-recovery · AC6 wire contract preserved additively (JSON + gRPC `status=5`) · AC6a recipient resolution CONFIRMED-only · AC7 observability counters (named, both sides) · AC8 quality gates green, baseline `{}` · AC9 architecture/LikeC4/ADR docs updated.

### Additional (architecture-derived) requirements
The epics enumerate **AR-SAGA1–4, AR-DEPTRAC, AR-MQ1/2, AR-DATA1–4, AR-WORKER1,
AR-CONTRACT1, AR-DOCS** — all well-formed and traced to specific architecture
sections (section3/5/6/7/8/9/10/11/15).

### PRD completeness assessment
The PRD is **complete and internally consistent**: it states the problem,
the canonical *compensatable → pivot → retriable* saga shape, two explicit state
machines (§1.1 saga lifecycle vs subscription status) with a full transition
mapping, goals/non-goals, scope per service, an integration contract (§7),
data-ownership + saga-state-store plan (§8), an explicitly called-out semantic
change (§9, both the `PENDING` default **and** the recipient-resolution change),
risks with mitigations (R1–R8), a 10-item definition-of-done (§12), and an
Open-Questions/Assumptions section that correctly scopes the *layout* of the
worker as open while pinning the *existence* of relay/reply-consumer/sweeper as
non-negotiable FRs. The "no outbox except here" and "no 2PC, not even compared"
decisions are justified rather than asserted. This is a strong PRD.

---

## 3. Epic Coverage Validation

### FR / NFR / AC → Story coverage matrix

| Req | PRD text (short) | Story coverage | Covered |
|---|---|---|---|
| FR1 | Create `PENDING`, `201`, non-blocking, readable | B4, B5 | Covered |
| FR2 | Atomic subscription+saga, 1:1, dup-`POST` idempotent | A1, A4, B5 | Covered |
| FR3 | Saga started in write path, in T1 tx | A4, A5, B5 | Covered |
| FR4 | Outbox-style relay, confirmed publish | D1, D2 | Covered |
| FR5 | Consume → render welcome → send | C3, C4 | Covered |
| FR6 | Idempotent welcome send (ledger claim) | C2, C4 | Covered |
| FR7 | Reply publisher + terminal-failure reply | C5 | Covered |
| FR8 | Reply consumer → T3/C1 orchestration | D3 | Covered |
| FR9 | State-guarded conditional-`UPDATE` idempotency | B1, B3, D3 (proof) | Covered |
| FR10 | Timeout sweeper (primary T + start-sweep) | D4 | Covered |
| FR11 | Compensation correctness, in-flight bound | D3, D4, E4 (proof) | Covered |
| FR12 | Observability funnel both sides | C6, D5 | Covered |
| FR13 | Additive JSON + gRPC `status` | E2, E3 | Covered |
| FR14 | Recipient resolution CONFIRMED-only | E1 | Covered |
| NFR1 | Exactly-once state, bounded duplicates | C2, C4, D3, D4 | Covered |
| NFR2 | Reliable saga start (dual-write) | A1, B1, B5, D1 | Covered |
| NFR3 | Convergence / no-hang | D4 | Covered |
| NFR4 | Independent deployability | A1, C1, E4 | Covered |
| NFR5 | Observability (funnel + correlation) | C6, D5 + per-story | Covered |
| NFR6 | Quality gates, baseline `{}` | A2, A3 + every story | Covered |
| NFR7 | Single compose up, full stack | E4 | Covered |
| AC1 | Happy path → CONFIRMED (MailHog) | E4 | Covered |
| AC2 | Compensation → CANCELLED | E4 | Covered |
| AC3 | Idempotency (replay-3×, counter once) | D3 | Covered |
| AC4 | Timeout→compensation + negative guard | D4 | Covered |
| AC5 | Broker-down at `POST`, relay on recovery | D2 (unit) + E4 (e2e) | Covered |
| AC6 | Wire contract additive (JSON + gRPC) | E3 | Covered |
| AC6a | Recipient resolution CONFIRMED-only | E1 | Covered |
| AC7 | Observability counters (named, both sides) | C6, D5 (+ E4 funnel) | Covered |
| AC8 | Gates green, baseline `{}` | every story | Covered |
| AC9 | architecture.md + LikeC4 + ADR-0003 | E5 | Covered |

### Coverage statistics
- Total PRD FRs: **14** — covered: **14** (100%).
- Total NFRs: **7** — covered: **7** (100%).
- Total ACs: **10** (AC1–AC9 + AC6a) — covered: **10** (100%).
- **No orphan requirements.** **No story without a requirement/architecture
  justification.** The only "pure infra/docs" stories — A1 (migrations), A2
  (deptrac), C1 (topology), E2 (contracts), E4 (compose), E5 (docs) — each trace
  to an AR-* and/or an AC (AR-DATA*, AR-DEPTRAC, AR-MQ*, AC1/AC2/AC9).
- The epics' own three coverage maps — the Requirements-Inventory **FR Coverage
  Map** (lines 271–304), the **Validation Summary** FR/NFR/AC lists (lines
  1600–1639), and the per-story **Dependencies** notes — were independently
  re-derived and **agree with each other and with this matrix** (the FR1 →
  "B4, B5", FR9 → "B1, B3, D3", AC5 → "D2 + E4" splits all reconcile across the
  three).

**Locked-decision coverage (explicitly verified, not flagged as problems):**
- Atomic saga start (not PSR-14-listener-driven): **scheduled** (A4/A5 ports →
  B5 wraps `create + start()` in one `TransactionManager.transactional`).
- "Outbox-style relay only" (no synchronous fast-path publish — arch §1/§15
  deliberately drops PRD FR4's *optional* fast-path): **present** (D1/D2 relay
  is the sole publisher; trivially satisfies AC5).
- State-guarded conditional-`UPDATE` idempotency, subscription `status='pending'`
  guard as the true single-writer lock: **covered** (B1/B3 writers → D3 proof).
- deptrac: three new layers + two opposite-direction port edges + acyclicity
  invariant (primitive crossing key): **covered** (A2 declares, A4 proves the
  negative + keeps Domains independent, D3 exercises the reply edge).
- `ClaimOutcome` 4th case `AlreadyFailed` on the **shared** enum, release-path
  `if`-chains stay green: **covered** (C2, verified against real code in §6).
- Wire-format protection (additive `status` on JSON + proto `status=5`):
  **covered** (E2/E3, frozen through Epics A–D).

---

## 4. Dependency-Order Validation (Strangler A→B→C→D→E)

The sequence is **sound and forward-dependency-free**:

- **A (P0–P1)** depends on nothing. A1 (migrations + seeded `src/Saga/Enrollment`
  tree) → A2 (deptrac layers need the dirs) → A3 (`SagaId` + `TransactionManager`)
  → A4 (Saga.Domain — needs A2/A3) → A5 (Application skeletons — need A3/A4). All
  non-breaking; gates stay green; deptrac negative-proof correctly deferred from
  A2 to A4 (where real `Saga.Domain` classes exist).
- **B (P2–P3)** depends on A. B1 (PDO saga repo, needs A1 table + A4 ports), B2
  (`PdoTransactionManager`, needs A3), B3 (confirmation-writer adapter, needs A1
  + A4 interface), B4 (status projection, needs A1 + B3), **B5 (atomic start)
  correctly depends on B1–B4 + A3–A5** — the integration point, last in the epic.
- **C (P4–P5)** depends only on A's `007` table. Reordered **ports-before-handler**:
  C1 topology → C2 ledger (`ClaimResult`/`AlreadyFailed`) → C3 Domain ports/VOs +
  renderer + mapper → C4 handler (depends only on C2/C3 interfaces) → C5 publisher
  adapter (depends on C3's *port*) → C6 metrics. **The old C3→C4/C5 forward
  dependency is explicitly removed** (epics §C overview + Validation Summary).
- **D (P6)** depends on A/B/C. D1 (relay adapter) → D2 (worker loop + M4 fence) →
  D3 (reply consumer + orchestrator, needs B1–B3/C1/D2) → D4 (sweeper, needs
  D3 path) → D5 (monolith metrics, needs D1/D3/D4 call-sites). Clean.
- **E (P7–P10)** depends on B/C/D. E1 (recipient filter) → E2 (golden contracts,
  needs D1/C5 serializers) → E3 (wire `status`, needs B4 projection + E1) → **E4
  (compose + e2e demo) correctly depends on D2/C1–C6/D3–D5/B5** (everything live)
  → E5 (docs last).

Every step is designed to keep `lint + deptrac + phpunit + psalm` green on the
affected deployable, and the **deptrac baseline stays `{}`** throughout. **No
story depends on a later story.** The deliberate revisits of
`config/container.php` (B1/B2/B3/B5/D2/D3), `PdoSubscriptionRepository` (B4
projection → E1 filter), `SubscribeCommandHandler` (B5), `bin/consumer.php`
(C1/C3/C4), `SendWelcomeEmailHandler` (C4/C5), and `SagaWorker` (D2/D3/D4) are
correctly justified as incremental Strangler wiring (port → adapter → worker
tick → cut over), not avoidable churn — and the epics' File-Churn note documents
this explicitly.

---

## 5. UX Alignment

- **UX document status:** Not found — **correctly N/A.** The system is headless
  (REST + gRPC + scanner/saga-worker CLI); there is no UI surface. The epics
  explicitly record "UX Design Requirements: None" and fold the only "interface"
  concern (the additive `status` field on JSON and gRPC, FR13) into the wire
  contracts, enforced as contract/Behat/gRPC assertions, not as UX.
- **Alignment issues:** none. No PRD/architecture statement implies a UI. The
  `[ASSUMPTION]` that `status` is surfaced on reads as a string enum
  (`pending | confirmed | cancelled`) is a wire-shape decision finalized in
  architecture.md (§9/§13/E3), not a UX gap.
- **Warning:** none. The absence of a UX spec is appropriate.

---

## 6. Brownfield Reality Cross-Check (docs vs. actual code)

| Claim in docs | Verified in code | Result |
|---|---|---|
| Monolith **runs no runtime consumer** — only publishes (PRD §3.1, arch §10) | `bin/worker.php` is the FrankenPHP HTTP worker; compose monolith `command:`s are `bin/scanner.php` + `rr serve`; the only `basic_consume` in monolith `src/` is inside the dead `RabbitConsumer` itself | **Match** |
| **Dead FQCN-identical `RabbitConsumer`** exists, deptrac-load-bearing, never executed (arch §10 M4, project memory) | Both `src/Shared/…/Rabbit/RabbitConsumer.php` and `apps/notification/src/Shared/…/Rabbit/RabbitConsumer.php` share namespace `App\Shared\Infrastructure\Messaging\Rabbit`; monolith has **no** `MessageConsumer.php` (only notification does) | **Match — and the M4 "monolith-absent `MessageConsumer` interface" warning (D2) is real** |
| **deptrac baseline `{}`** (NFR6/AC8) | `deptrac.baseline.yaml` = `skip_violations: {  }`; `apps/notification` has a ruleset but **no baseline file** (epics' service-side note is correct) | **Match** |
| `subscriptions` has **no `status` column** (migration 004 needed) | `migrations/001` creates `subscriptions(id, email, repository, created_at, UNIQUE(email,repository))` — 4 columns, no status | **Match — 004 is real work** |
| Recipient port method is `findSubscribersByRepository` (FR14/AC6a) | `SubscriberFinder::findSubscribersByRepository(RepositoryName)` + the PDO impl + the `PublishReleaseEmailsForRelease` caller all use exactly that name | **Match — no method-name drift (unlike HW7 SF-2)** |
| proto `SubscriptionReply` has **4 fields**, gains additive `status = 5` (FR13/AC6) | `proto/release_notifier.proto:40-45` = `id=1, email=2, repository=3, created_at=4` — exactly 4 | **Match** |
| `ClaimOutcome` has 3 cases today; `AlreadyFailed` is the net-new 4th on the **shared** enum; release handler uses `if`-chains not `match` (C2) | `ClaimOutcome` = `Claimed`/`AlreadySent`/`InFlight` (3 cases); `ClaimResult` exists; `SendReleaseEmailHandler` reads `$claim->outcome === ClaimOutcome::X` via `if` (no `match`) | **Match — C2's "release branches stay green" claim is grounded** |
| **No welcome ledger/template/publisher** yet; `ReleaseEmailRenderer` + `PdoNotificationLedger` exist as templates | No `Welcome*` files anywhere; `ReleaseEmailRenderer`, `PdoNotificationLedger` (`claim`/`markSent`), `CLAIM_LEASE_SECONDS = 300`, `MAX_REDELIVERIES = 3` all present | **Match — welcome path is correctly scoped as net-new** |
| `SubscribeCommandHandler` calls `create()` + dispatches the event (atomic-start refactor is real); `create()` returns a reconstituted `Subscription`; `Subscription::id()` is `?int`; no `status()` getter | Handler line 56 `$this->repository->create($subscription)` (result discarded) + dispatch; `create(): Subscription` with `RETURNING …` + SELECT-fallback; `id(): ?int`; aggregate has `subscribe()`/`reconstitute()` but **no `status()`** | **Match — B4/B5 scope (capture id, add getter, wrap in tx) is accurate** |
| MailHog already in compose, reused (NFR7, arch §11) | `docker-compose.yml:68` `mailhog` service present, `SMTP_HOST: mailhog` | **Match — arch §11 correctly says "ALREADY EXISTS, reused"** |
| ADR series `0001`/`0002` exist; `0003` is new (AC9, arch §15) | `docs/adr/0001-frankenphp-worker-mode.md`, `0002-notification-service-extraction.md` present; no `0003` | **Match** |
| Two migration dirs / runners; `migrations/` at `001–003` (next `004`), `apps/notification/migrations/` at `001–006` (next `007`) (A1) | `migrations/` = `001,002,003`; `apps/notification/migrations/` = `001`…`006` | **Match — next-free numbers exact** |
| `contracts/` discipline (`send-release-email.v1.json` golden) for E2 | `contracts/send-release-email.v1.json` + `README.md` present | **Match** |
| Arch §11 says "`make migrate` runs `migrations/004` + `005`" | The correct Postgres-A set is `004`–`006` (006 = saga_metrics) per the P0 table and A1 | **Mismatch (doc-internal) — see SF-1; epics A1 already flags it** |

---

## 7. Findings by Severity

### Blockers (must fix before implementation)
**None.** No orphan requirement, no forward dependency, no untraceable story, no
contradiction between the locked decisions and the plan, and no brownfield claim
that is false in a way that would derail a story.

### Should-fix (resolve or explicitly accept before the affected epic)

- **SF-1 — Architecture §11 "`make migrate` runs `004` + `005`" omits `006`.**
  Architecture.md lines 878–879 state the Postgres-A migrate set as "`004` +
  `005`", but migration `006_create_saga_metrics.sql` is also a Postgres-A
  migration (D5's event-count counters ride it) and the P0 phase table (§12) +
  epics Story A1 both correctly list `004`–`006`. Epics A1 **already calls this
  out** ("Architecture §11 says `make migrate` runs `004` + `005` — that is a
  stale omission of `006`; the correct Postgres-A migrate set is `004`–`006`").
  Risk: a reader of architecture.md alone under-runs the migration set and D5's
  metrics table is missing at runtime.
  *Fix:* one-line edit to architecture.md §11 to read "`004`–`006`". Cheap;
  removes the only doc-vs-doc inconsistency in the set.

- **SF-2 — AC numbering: AC6a is a sub-criterion, not in the AC1–AC9 sequence.**
  The PRD's definition-of-done (§12) has AC1–AC9 **plus** an inserted **AC6a**
  (recipient resolution). The count is internally consistent (epics trace AC6a →
  E1 everywhere), but a reader expecting a flat AC1–AC10 may miscount, and the
  "10 ACs" figure depends on counting AC6a. Not a coverage gap — both AC6 and
  AC6a are independently covered (E3 and E1 respectively).
  *Fix (optional):* either renumber to a flat sequence or add a one-line note in
  §12 that "AC6a is a distinct, separately-tested criterion." Accept-as-is is
  also fine — flagged only so a reviewer doesn't read AC6a as a typo of AC6.

- **SF-3 — `T_start ≥ T` with both defaulting to 900 makes the secondary
  start-sweep deadline equal to the primary, not strictly greater.** Architecture
  §8/§11 require `T_start ≥ T` and set both `SAGA_TIMEOUT_SECONDS` and
  `SAGA_START_TIMEOUT_SECONDS` to `900`. With `T_start = T`, a `Started` saga
  whose broker recovered just-in-time could be start-swept at the same instant
  the relay is about to publish (the start-sweep clock runs from `created_at`,
  which predates `awaiting_since`). The inequality is satisfied non-strictly, but
  the *intent* (start-sweep is the slower backstop) reads better with `T_start`
  strictly above `T`.
  *Fix:* either set the default `SAGA_START_TIMEOUT_SECONDS` strictly above `T`
  (e.g. 1200) or document explicitly in D4/AR-CONTRACT1 that `T_start = T` is
  acceptable because a `Started` saga past `created_at + 900s` has provably never
  confirmed a publish (the relay advances on confirm within one tick). Pin this
  so the implementer doesn't guess.

- **SF-4 — D2's M4 fence is large and gating but its acceptance is "verify/replace
  verbatim," not a concrete equivalence check.** D2 correctly enumerates the full
  collaborator set the verbatim `RabbitConsumer` swap pulls in (`MessageConsumer`
  interface — confirmed **absent** in monolith `src/` in §6 — plus
  `shouldRouteToDlq`/`requeueWithRetry`/`requeueWithoutRetryIncrement`/
  `republishDelayed`/`RetryPublishFailedException`/mapper base). The AC says they
  are "proven equivalent to (or replaced verbatim by)" the notification ones and
  "the worker **boots**." This is the single highest-risk story (the monolith's
  first-ever runtime consumer) and "boots" is a weak pass/fail.
  *Fix:* add a concrete D2 step — a diff of every collaborator file
  (monolith vs `apps/notification`) that must be byte-identical post-swap, plus a
  boot smoke (`php bin/saga-worker.php` connects, declares topology, and consumes
  one message) — so the "boots" acceptance is deterministic. Keep within D2 scope.

### Nice-to-have (improve quality; not gating)

- **NTH-1 — `sagaId` round-trip correlation asserted but not test-covered.** FR12/
  NFR5 rely on the same `sagaId` (AMQP `correlation_id`) surviving
  publish→consume→send→reply→confirm across both services, but no story asserts
  the *same* `sagaId` appears in the consumed/replied log lines. Consider an
  explicit assertion in E4 (or D3) that the reply's `sagaId` equals the published
  one, so the funnel is genuinely greppable.

- **NTH-2 — `welcome_reply_noop_total` is an observability counter with no AC
  asserting it increments.** Arch §5/§7 introduce `welcome_reply_noop_total` for
  the sent-after-cancel bounded-duplicate case, and D5 wires it, but no AC asserts
  it increments on the no-op path. AC3 asserts the *state* counters
  (`confirmed_total=1`/`cancelled_total=1`) increment once; an additive assertion
  that the redundant replays bump `welcome_reply_noop_total` would make the
  bounded-duplicate observable in test, not just in production.

- **NTH-3 — DLQ drain / poison-message runbook absent for the new welcome DLQ.**
  C1/C5 route terminal welcomes to `notifications.welcome-email.dlq`, but nothing
  covers operator handling (inspect/replay/purge). Acceptable for this phase;
  consider a sentence in E5's ADR/README, consistent with the existing release DLQ.

- **NTH-4 — `EnrollmentSaga` records four domain events but only metrics/logging
  listeners consume them in scope.** A4 records `SagaStarted`/`WelcomePublished`/
  `SagaCompleted`/`SagaCompensated` drained via `pullDomainEvents()`, but the plan
  only wires observability listeners. Fine as future-proofing; flag so a reviewer
  doesn't expect a business listener and so psalm/deptrac don't treat unused
  events as dead code.

- **NTH-5 — `CANCELLED`-resurrection gap is documented but has no test guarding
  the documented behaviour.** PRD §10 + arch §14 accept that a re-`POST` after a
  `CANCELLED` subscription returns the stale row with no new saga (the
  `ON CONFLICT (email, repository) DO NOTHING` consequence). A one-line unit
  assertion that this is the *current* behaviour (returns existing `CANCELLED`
  row, starts no saga) would pin the documented limitation so a future change
  doesn't silently alter it. Optional — it's an accepted gap, not a bug.

---

## 8. Testability Assessment

- **AC1 / happy path (E4):** Strong. Concrete end-to-end via docker compose +
  MailHog (exactly one welcome email) → `outcome: sent` → subscription
  `confirmed` / saga `completed`. Backed by the real reused MailHog service (§6).
- **AC2 / compensation (E4):** Concrete (retries exhaust → DLQ → `outcome: failed`
  → subscription `cancelled` / saga `compensated`; no `pending` left). Good.
- **AC3 / idempotency (D3):** Strong. Replay the same reply 3× (or two concurrent
  copies) and assert an *observable*: exactly one terminal status +
  `confirmed_total=1` / `cancelled_total=1` — backed by the conditional-`UPDATE` /
  `rowCount()` guard (B1/B3 verified writers). A genuine test, not a claim.
- **AC4 / timeout + negative guard (D4):** Strong, and the **negative guard is
  explicit** (a saga inside the envelope is **not** swept) — exactly what the
  readiness skill wants from a resilience proof. Determinism is secured by B1/D4's
  reconciled decision to bind the **injected `$now`** (not DB `NOW()`) in
  `dueForSweep`/`dueForStartSweep` (M5). The one residual soft spot is the
  `T_start = T` default (SF-3).
- **AC5 / broker-down (D2 unit + E4 e2e):** Well-decomposed. D2 proves the
  *unit* half (the `POST` handler holds no publisher and never touches the
  broker — provable because the architecture dropped FR4's synchronous fast-path,
  so the relay is the sole publisher); E4 proves the *behavioral* half
  (broker-down-then-recover → relay publishes → terminal outcome). The split is
  honest about what each gate can prove.
- **FR6 / welcome dedup (C2/C4):** Concrete (`AlreadySent` → no mailer call +
  re-emit `sent`; `AlreadyFailed` → no re-send). The `terminal_failed_at`-before-
  reply ordering that makes redelivery safe is precisely specified (arch §6).
- **FR9 / state-guard (B1/B3):** Concrete (first `complete()`/`confirm()` returns
  `true`; second returns `false` / `rowCount()=0`; `WHERE status='pending'` blocks
  terminal-status resurrection). Good.
- **AC6 / wire-format (E3 + Behat):** Concrete (JSON retains 4 fields + adds
  `status`; proto retains 1–4 + adds `status=5`; existing assertions unchanged,
  `status` assertions added; `SendReleaseEmail/v1` golden unchanged).
- The retry bound (`MAX_REDELIVERIES = 3`) and claim lease (`CLAIM_LEASE_SECONDS
  = 300`) are **numerically pinned** in both the docs and the real constants (§6),
  and the deadline `T = 900` has an explicit worst-case derivation (arch §11) — a
  notable improvement over HW7's unpinned retry-count finding.
- The only weak spots are SF-3 (`T_start = T`) and SF-4 (D2's "boots" pass/fail).

---

## 9. Summary and Recommendations

### Overall Readiness Status
**READY-WITH-CONDITIONS.** PRD + Architecture + Epics are complete, mutually
consistent, **100%-traceable** (14/14 FR, 7/7 NFR, 10/10 AC incl. AC6a),
correctly dependency-ordered along the P0→P10 Strangler ladder, and faithful to
the brownfield baseline (13/13 doc claims verified against live code, the only
mismatch being a doc-internal `006` omission the epics already flag). No blockers.
Proceed to implementation after addressing (or explicitly accepting) the four
should-fix items.

### Critical Issues Requiring Immediate Action
None (no blockers). The should-fix items are low-effort doc/spec tightenings,
best done before the epic they affect:
1. **SF-1** before Epic D (Story D5 / before running `make migrate`) — fix the
   architecture.md §11 "`004` + `005`" → "`004`–`006`".
2. **SF-3** before Epic D (Story D4) — pin `T_start` strictly above `T` (or
   document why `T_start = T` is safe).
3. **SF-4** before Epic D (Story D2) — make the M4 `RabbitConsumer` collaborator
   swap acceptance a concrete file-diff + boot smoke, not "boots."
4. **SF-2** before Epic E (or accept) — clarify AC6a numbering.

### Recommended Next Steps
1. Apply SF-1 (one-line architecture.md fix). Optionally SF-2 renumber.
2. Pin SF-3 (`T_start` default) and tighten SF-4 (D2 acceptance) in `epics.md`.
   Do **not** change scope.
3. Add the `sagaId` round-trip assertion (NTH-1) to E4 and the
   `welcome_reply_noop_total` assertion (NTH-2) to D5/D3.
4. Proceed with **Epic A immediately** — it is fully unblocked today (migrations,
   `SagaId`/`TransactionManager`, deptrac layers, pure Saga.Domain), non-breaking,
   and keeps every gate green.

### Final Note
This assessment reviewed 4 input documents and cross-checked **13** doc claims
against the live code in `src/`, `migrations/`, `apps/notification/`,
`deptrac.yaml`, `deptrac.baseline.yaml`, `docker-compose.yml`, `proto/`,
`contracts/`, `bin/`, and `docs/adr/`. It identified **0 blockers, 4 should-fix,
5 nice-to-have** items across coverage, consistency, dependency-ordering,
testability, and brownfield-accuracy categories. The plan is among the
better-prepared distributed-transaction plans reviewable — its requirement
traceability, the explicit two-state-machine model, the justified no-outbox-
except-here and no-2PC decisions, the state-guarded idempotency design, the
deterministic-`$now` sweeper, and the honest unit-vs-e2e split of AC5 are all
sound. Address the should-fix items (mostly one-line spec corrections) before
Epic D/E; you may proceed with Epic A immediately.

*These findings can be used to improve the artifacts, or you may choose to
proceed as-is — none are blocking.*
