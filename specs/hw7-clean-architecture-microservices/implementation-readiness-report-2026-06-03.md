---
artifact: implementation-readiness-report
project: github-release-notifier
author: valerii
date: '2026-06-03'
status: complete
stepsCompleted:
  - 'step-01-document-discovery'
  - 'step-02-prd-analysis'
  - 'step-03-epic-coverage-validation'
  - 'step-04-ux-alignment'
  - 'step-05-epic-quality-review'
  - 'step-06-final-assessment'
inputDocuments:
  - '_bmad-output/planning-artifacts/prd.md'
  - '_bmad-output/planning-artifacts/architecture.md'
  - '_bmad-output/planning-artifacts/epics.md'
  - '_bmad-output/project-context.md'
  - 'CLAUDE.md'
codeChecked:
  - 'src/'
  - 'migrations/'
  - 'composer.json'
  - 'docker-compose.yml'
  - 'proto/release_notifier.proto'
overallVerdict: 'Ready-with-conditions'
---

# Implementation Readiness Assessment Report

**Date:** 2026-06-03
**Project:** github-release-notifier
**Assessor:** valerii (BMad Implementation Readiness workflow)

> **Overall verdict: READY-WITH-CONDITIONS.** The PRD, architecture, and epics are
> unusually well-aligned, traceable, and dependency-ordered, and the brownfield
> baseline in `src/`, `migrations/`, `composer.json` matches what the docs claim.
> There are **no blockers**, but a small set of **should-fix** items (stale
> docker-compose claim, a couple of testability gaps, and one naming/contract
> ambiguity) should be resolved or explicitly accepted before Epic D/E execution.

> **Update (2026-06-15) — NFR3 / AC5 / Story E3 descoped.** The notification-outage
> *resilience proof* (`bin/resilience-proof.sh`, its CI workflow, and the manual-test
> evidence under `var/manual-test-evidence/`) was removed. The assessment below is
> preserved as the original 2026-06-03 snapshot and still references NFR3/AC5/E3 as
> planned at that date.

---

## 1. Document Discovery

| Document | File | Status |
|---|---|---|
| PRD | `_bmad-output/planning-artifacts/prd.md` | Found (whole, 11 KB) |
| Architecture | `_bmad-output/planning-artifacts/architecture.md` | Found (whole, 21 KB) |
| Epics & Stories | `_bmad-output/planning-artifacts/epics.md` | Found (whole, 61 KB) |
| Project Context | `_bmad-output/project-context.md` | Found (persistent fact) |
| Conventions | `CLAUDE.md` | Found |
| UX Spec | — | Not found (correctly N/A — see §5) |

- **No duplicates** (no whole+sharded conflicts) for any document type.
- **No required document missing.** UX is intentionally absent (headless service;
  only frozen wire contracts apply — confirmed by epics "UX Design Requirements:
  None").
- Brownfield reality cross-check performed against `src/`, `migrations/`,
  `composer.json`, `docker-compose.yml`, `proto/`. Results in §6.

---

## 2. PRD Analysis (requirements extracted)

### Functional Requirements (11)
- **FR1** Module capabilities exposed only via interfaces; no cross-module reach-through.
- **FR2** `MetricsRepository` stops querying foreign domains' tables; per-module counters aggregated by `MetricsService`.
- **FR3** Scanner resolves recipients via Subscription module and publishes one `SendReleaseEmail` per recipient to RabbitMQ.
- **FR4** Event payload carries `subscriptionId`, `email`, `repository`, `release{tag,name,url,publishedAt}`, `eventId`.
- **FR5** Reliable publish: do not advance `last_seen_tag` if batch publish fails; advance on success.
- **FR6** Remove in-process `NotificationDispatcher` + monolith dependency on `NotificationLedgerInterface`/SMTP.
- **FR7** Service consumes `SendReleaseEmail` from durable queue, renders, sends via SMTP.
- **FR8** Service idempotent: `hasSuccessfulNotification` check then `recordResult`; no duplicate emails.
- **FR9** Transient failure → nack/bounded retry → DLQ after N attempts; ledger records attempts + `last_error`.
- **FR10** Service exposes its own health + metrics endpoint.
- **FR11** REST `/subscriptions` JSON, gRPC reply, Behat scenarios unchanged.

### Non-Functional Requirements (6)
- **NFR1** Idempotency / at-least-once → exactly-once *effect*.
- **NFR2** Independent deployability (each side runs without the other's DB).
- **NFR3** Resilience (monolith serves while broker/service down; queue buffers).
- **NFR4** Observability (logs + metrics both sides; published/consumed/delivered derivable).
- **NFR5** Quality gates pass both sides.
- **NFR6** Single `docker compose up` brings up full stack.

### Acceptance Criteria (7)
AC1 modular `src/`, no concrete cross-imports · AC2 separate buildable/migratable
service · AC3 end-to-end publish→send via MailHog · AC4 no duplicate email
(idempotency proven by test) · AC5 monolith up while broker/service down ·
AC6 all gates green both sides · AC7 LikeC4 + ADR updated.

### Additional (architecture-derived) requirements
The epics enumerate AR-ARCH1/2, AR-DDD1–4, AR-FLOW1/2, AR-MQ1/2, AR-DATA1,
AR-DEPTRAC, AR-DOCKER, AR-DOCS, AR-MAP. These are well-formed and traced to
architecture sections.

### PRD completeness assessment
The PRD is **complete and internally consistent**: it states the problem, goals,
non-goals, scope, an explicit integration contract, data-ownership/migration plan
(§8), an explicitly called-out semantic change (§9), risks with mitigations, and a
definition-of-done (§12 AC1–AC7). The "no outbox" rationale is justified rather
than asserted. This is a strong PRD.

---

## 3. Epic Coverage Validation

### FR → Story coverage matrix

| Req | PRD text (short) | Story coverage | Status |
|---|---|---|---|
| FR1 | Interface-only module boundaries | A5, A6, B1–B5, E4 | Covered |
| FR2 | Kill `MetricsRepository` cross-table COUNT | B5 | Covered |
| FR3 | Resolve recipients + per-recipient publish | C2, C5 (wired), E1 (live) | Covered |
| FR4 | Full `SendReleaseEmail` payload + `eventId` | C1 | Covered |
| FR5 | Publish-then-mark; no advance on failure | E1 | Covered |
| FR6 | Remove in-process dispatcher/ledger/SMTP | E4 | Covered |
| FR7 | Consume → render → send | D3, D4 | Covered |
| FR8 | Idempotency ledger check + record | D3, D4; proven E2 | Covered |
| FR9 | nack → bounded retry → DLQ + `last_error` | D3, D4 | Covered |
| FR10 | Service `/health` + `/metrics` | D5 | Covered |
| FR11 | Wire contracts unchanged | B1, B5, E1, E4 + gates everywhere | Covered |
| NFR1 | Exactly-once effect | D3, E2 | Covered |
| NFR2 | Independent deployability | D1, D2, D6, E4 | Covered |
| NFR3 | Resilience | E3 | Covered |
| NFR4 | Observability | C2, D5, B5 | Covered |
| NFR5 | Quality gates both sides | every story | Covered |
| NFR6 | Single compose up | C3, D6 | Covered |
| AC1 | Modular, no concrete cross-imports | E4 (verified), B1–B5, A6 deptrac | Covered |
| AC2 | Separate buildable/migratable service | D1, D2 | Covered |
| AC3 | End-to-end via MailHog | D6 | Covered |
| AC4 | No duplicate email (test) | E2 | Covered |
| AC5 | Monolith up while broker/service down | E3 | Covered |
| AC6 | All gates green both sides | every story | Covered |
| AC7 | LikeC4 + ADR updated | E5 | Covered |

### Coverage statistics
- Total PRD FRs: **11** — covered: **11** (100%).
- Total NFRs: **6** — covered: **6** (100%).
- Total ACs: **7** — covered: **7** (100%).
- **No orphan requirements.** **No story without a requirement/architecture
  justification** (the only "pure infra/docs" stories — A5, A6, A7, C3, C4, E5 —
  each trace to an AR-* or an AC).
- The epics' own FR/NFR Coverage Map (lines 154–177) and Validation Summary
  (lines 1170–1192) match this independent re-derivation.

**Locked-decision coverage (explicitly verified, not flagged as problems):**
- Anemic-DTO → aggregate-root convention reversal: **scheduled** (A2 base, B1/B2
  adopt it, A7 updates `CLAUDE.md`).
- "No outbox" + pre-commit synchronous PSR-14 dispatch: **present** (A3 propagating
  dispatcher, C2 throwing listener, E1 publish-then-mark; AR-FLOW2).
- FK removal + separate DB: **covered** (D2 no-FK migration, E4 drops monolith table).
- deptrac boundary enforcement: **covered** (A6 baseline → B* shrink → D1 service rules).
- Wire-format protection: **covered** (FR11 re-verified at B1/B5/E1/E4 + gates each story).

---

## 4. Dependency-Order Validation (Strangler A→B→C→D→E)

The sequence is **sound and forward-dependency-free**:

- **A (P0)** depends on nothing; A1–A4 are parallel-safe; A5 → A6 (deptrac needs
  dirs); A7 documents A1–A6. All non-breaking, gates stay green.
- **B (P1)** depends on A; B4 correctly depends on B2+B3 (consumes their ports) and
  A4; B5 depends on B1+B2 (needs count ports). No B story needs C+.
- **C (P2–P3)** depends on B (needs `SubscriberFinder`); C2 depends on A3+C1+B1+B4;
  C5 keeps in-process adapter default → behavior preserved (no premature cutover).
- **D (P4)** depends on C (message contract, broker, shared messaging); internal
  order D1→D2→D3→D4→D5→D6 is clean.
- **E (P5–P6)** depends on C+D; E1 cutover, E2/E3 proofs, **E4 decommission
  gated on E1–E3** (delete only after cutover proven), E5 docs last.

Every step is designed to keep `lint + deptrac + phpunit + psalm` green, and the
deptrac baseline shrinks across B as classes move. **No story depends on a later
story.** The deliberate revisits of `config/container.php` and
`ScanReleasesCommandHandler` across B/C/E are correctly justified as incremental
Strangler wiring (introduce port → build adapter → cut over), not avoidable churn.

---

## 5. UX Alignment

- **UX document status:** Not found — **correctly N/A.** The system is headless
  (REST + gRPC + scanner CLI); there is no UI surface. The epics explicitly record
  "UX Design Requirements: None" and fold the only "interface" concern (the frozen
  wire contracts) into FR11.
- **Alignment issues:** none. No PRD/architecture statement implies a UI.
- **Warning:** none. The absence of a UX spec is appropriate, not a gap.

---

## 6. Brownfield Reality Cross-Check (docs vs. actual code)

| Claim in docs | Verified in code | Result |
|---|---|---|
| Current layout is file-type layered (`Service/`, `Notifier/`, `Repository/`, `Domain/`) | `src/` listing confirms exactly these dirs | Match |
| Move-table classes exist (`ScannerService`, `NotificationDispatcher`, `NotifierService`, `SmtpMailer`, `ReleaseEmailRenderer`, `RenderedEmail`, `NotificationLedger`, `SubscriptionService`, `MetricsRepository`) | All present | Match — move-table accurate |
| `MetricsRepository` queries foreign tables directly (FR2 leak) | `SELECT COUNT(*) FROM subscriptions` and `FROM repositories` in `MetricsRepository.php` | Match — leak is real |
| `ScannerService` advances marker only on `$allDelivered` (PRD §9 semantic) | `$allDelivered = $this->dispatcher->dispatch(...)` then `markReleaseSeen` only if true | Match — semantic change is real |
| FK `release_notifications.subscription_id → subscriptions ON DELETE CASCADE` + `UNIQUE(subscription_id, repository, tag_name)` | Both present in `migrations/002_add_release_notifications.sql` | Match — D2/D4 scope accurate |
| Ledger methods `hasSuccessfulNotification` / `recordResult` / `attempts` / `last_error` | All present in `NotificationLedger.php` | Match — FR8/FR9 grounded |
| `apps/`, `deptrac.yaml`, PSR-14, RabbitMQ client, `notification-db` do **not** yet exist | None present | Match — correctly scoped as new work |
| `composer lint` must be extended to add deptrac (A6) | `"lint": "phpcs"` today (no deptrac) | Match — A6 work is real |
| Test baseline "81+ tests" | ~126 test methods present | Exceeds baseline |
| MailHog is a **new** compose service (PRD NFR6, arch §11, epic C3/AR-DOCKER) | **`mailhog` already exists** in `docker-compose.yml` (lines 68–69) | **Mismatch — see SF-1** |

---

## 7. Findings by Severity

### Blockers (must fix before implementation)
**None.** No orphan requirement, no forward dependency, no untraceable story, no
contradiction between locked decisions and the plan.

### Should-fix (resolve or explicitly accept before Epic C/D/E)

- **SF-1 — Stale "add MailHog" claim (doc vs. reality).** PRD NFR6, architecture
  §11, and epics Story **C3** + **AR-DOCKER** describe MailHog as a service to be
  *added* to docker-compose, but `docker-compose.yml` **already contains a
  `mailhog` service** (lines 68–69) used by the current SMTP path. Risk: C3 may
  duplicate or conflict with the existing definition; the "new services" list is
  inaccurate.
  *Fix:* reword C3/AR-DOCKER to "add `rabbitmq` + `notification-db`; **reuse/retain
  existing `mailhog`**", and adjust the new-service count. Cheap, prevents confusion.

- **SF-2 — Port method-name drift (`findSubscribers` vs. `findSubscribersByRepository`).**
  Architecture §5/§8 and epics C2 call the recipient-resolution port
  `findSubscribers(repo)` / "Subscription `SubscriberFinder` port", while the actual
  interface is `SubscriberFinderInterface::findSubscribersByRepository(string)`.
  The architecture also renames it to a bare `SubscriberFinder`. Minor, but the
  listener (C2) and metrics count-port (B5) wiring should agree on the exact signature.
  *Fix:* pin the canonical port name + method signature in C2 (and B1) scope so the
  implementer doesn't guess; note it returns `SubscriberCollection` (already exists).

- **SF-3 — AC5/E3 resilience proof under-specified for the "broker-up, service-down →
  recover" path.** E3 asserts buffered messages "deliver on recovery", but the test
  steps don't define *how recovery is triggered/observed deterministically* (e.g.
  bring `notification-svc` back, then poll MailHog with a bounded timeout) nor the
  pass/fail threshold. As written it risks being a flaky/under-defined test.
  *Fix:* add concrete steps to E3: stop service → publish N → assert queue depth = N
  on RabbitMQ → start service → poll MailHog until N received or timeout T → assert.
  Define T and N. Keeps AC5 genuinely verifiable (the skill flags resilience proofs
  that aren't real tests).

- **SF-4 — RabbitMQ client library not pinned in the plan.** The task context fixes
  the client as `php-amqplib/php-amqplib`, but **D1's scope says "php-amqplib or
  chosen Rabbit client"** and **C4** (shared messaging infra) names no library at
  all. Leaving the transport library "TBD" across the two stories that build it is a
  real ambiguity for `composer require`.
  *Fix:* state `php-amqplib/php-amqplib` explicitly in **C4** (where the connection/
  publisher/consumer base first appear) and in **D1**; remove "or chosen Rabbit
  client". (C4 is built in the monolith repo; confirm the dep is added there, since
  the publisher adapter C5 lives in the monolith, not only in `apps/notification`.)

### Nice-to-have (improve quality; not gating)

- **NTH-1 — `eventId` correlation is asserted but not test-covered.** NFR4 and C1/D5
  rely on `eventId` to correlate publish↔consume↔deliver, but no story asserts the
  *same* `eventId` survives the round-trip in logs/metrics. Consider an explicit AC
  (in E2 or D5) that the consumed/delivered log lines carry the published `eventId`.

- **NTH-2 — Ledger backfill decision left "optional" with no story.** Architecture
  §9/§14 and PRD §8 default to a clean start (no backfill). That's a defensible
  decision, but if any production ledger history must survive cutover, there is no
  story for the optional one-off copy. Recommend an explicit one-line note in E1/E4
  ("backfill: out of scope; clean start") so it's a decision, not an omission.

- **NTH-3 — DLQ drain / poison-message operational runbook absent.** D4 routes poison
  messages to `notifications.send-email.dlq`, and D5 exposes a DLQ count, but nothing
  covers *what an operator does* with the DLQ (inspect/replay/purge). Acceptable for
  this phase; consider a sentence in E5's README/ADR.

- **NTH-4 — `RepositoryStatus` aggregate emits two events but only one is exercised
  downstream.** B2 records `ReleaseSeenAdvanced` *and* `RepositoryChecked`, yet no
  listener consumes them in this scope (only the process-level `NewReleaseDetected`
  drives publishing). Fine as future-proofing, but flag so reviewers don't expect a
  listener. Ensure psalm/deptrac don't treat the unused events as dead code violations.

- **NTH-5 — A7/`CLAUDE.md` update timing vs. quality gates.** A7 changes the
  "anemic DTO" rule in `CLAUDE.md`, but the *code* that makes entities aggregates
  lands in B1/B2 (a later epic). For the duration of Epic A the documented rule will
  describe code that doesn't exist yet. Harmless (docs lead code by design here), but
  worth a one-line note in A7 that the rule is "as of Epic B" to avoid a reviewer
  flagging doc-vs-code drift mid-Epic-A.

---

## 8. Testability Assessment

- **AC4 / idempotency (E2):** Strong. Concrete dual-delivery + re-scan assertions
  against MailHog count and a single ledger row per `(subscription_id, repository,
  tag_name)` — backed by the real `UNIQUE` constraint (verified in migration). This
  is a genuine test, not a claim.
- **AC5 / resilience (E3):** Partially specified — see **SF-3**. The "monolith stays
  up while broker down" half is concrete; the "buffer then deliver on recovery" half
  needs deterministic recovery + polling steps.
- **FR8 dedupe (D3):** Concrete (ledger-hit → no mailer call → ack). Good.
- **FR9 retry/DLQ (D4):** Concrete (exceed `x-death` N → land in `.dlq`, ledger holds
  attempts + `last_error`). Good — though "N" is not numerically pinned anywhere;
  pin it (e.g. N=5) in C4/D4 so the bound is testable.
- **FR11 wire-format (B1/B5/E1/E4):** Concrete (Behat unchanged + byte-identical
  `/metrics`). Good.
- Most ACs are in proper Given/When/Then form and are individually verifiable. The
  only weak spots are SF-3 (E3) and the unpinned retry-count N.

---

## 9. Summary and Recommendations

### Overall Readiness Status
**READY-WITH-CONDITIONS.** PRD + Architecture + Epics are complete, mutually
consistent, 100%-traceable (FR/NFR/AC), correctly dependency-ordered, and faithful
to the brownfield baseline. No blockers. Proceed to implementation after addressing
(or explicitly accepting) the four should-fix items.

### Critical Issues Requiring Immediate Action
None (no blockers). The should-fix items are low-effort doc/spec tightenings, best
done before the epic they affect:
1. **SF-1** before Epic C (Story C3) — fix the MailHog "already exists" claim.
2. **SF-4** before Epic C (Story C4) — pin `php-amqplib/php-amqplib` and confirm it's
   added to the monolith's composer (C4/C5 live there), not only `apps/notification`.
3. **SF-2** before Epic C (Story C2) — pin the `SubscriberFinder` port name/signature.
4. **SF-3** before Epic E (Story E3) — make the resilience-recovery test deterministic.

### Recommended Next Steps
1. Apply SF-1…SF-4 edits to `epics.md` (and the one-line MailHog correction to
   `prd.md` §NFR6 / `architecture.md` §11 for accuracy). Do **not** change scope.
2. Pin the bounded retry count N (e.g. 5) in C4/D4 and add the `eventId`
   round-trip assertion (NTH-1) to E2 or D5.
3. Record the no-backfill decision explicitly (NTH-2) and proceed with Epic A —
   which is fully unblocked today.

### Final Note
This assessment reviewed 5 input documents and cross-checked 11 doc claims against
the live code in `src/`, `migrations/`, `composer.json`, and `docker-compose.yml`.
It identified **0 blockers, 4 should-fix, 5 nice-to-have** items across coverage,
consistency, dependency-ordering, testability, and brownfield-accuracy categories.
The plan is among the better-prepared refactor plans reviewable — its requirement
traceability, justified no-outbox decision, and Strangler sequencing are sound.
Address the should-fix items (mostly one-line spec corrections) before the affected
epics; you may also proceed with Epic A immediately, as it is unblocked.

*These findings can be used to improve the artifacts, or you may choose to proceed
as-is — none are blocking.*
