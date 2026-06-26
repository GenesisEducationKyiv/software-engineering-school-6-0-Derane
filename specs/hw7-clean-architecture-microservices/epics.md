---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories', 'step-04-final-validation']
inputDocuments:
  - '_bmad-output/planning-artifacts/prd.md'
  - '_bmad-output/planning-artifacts/architecture.md'
  - '_bmad-output/project-context.md'
  - 'CLAUDE.md'
artifact: epics
project: github-release-notifier
title: 'Epic & Story Breakdown — Modular Clean-Architecture Refactor + Notification Microservice'
author: valerii
date: '2026-06-03'
status: draft
---

# github-release-notifier - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for **github-release-notifier**,
decomposing the requirements from the PRD and the Architecture decisions into implementable
stories.

The work refactors the Slim 4 + PHP-DI monolith into a **modular Clean-Architecture** codebase
(`src/<Context>/<Module>/{Domain,Application,Infrastructure}` + `apps/` deployables, CodelyTV-aligned,
no Symfony) and extracts the **Notification / Email** domain into a separate microservice that
integrates over **RabbitMQ**.

The plan is executed **Strangler-style, in dependency order** (P0 → P6 in the architecture's
migration strategy). Every story keeps the quality gates green:
`composer lint` (PHPCS PSR-12 **+ deptrac**), `./vendor/bin/phpunit --no-coverage`, `composer psalm`
(100% type coverage). The notification service carries its own equivalent gates. The public wire
contracts (REST `/subscriptions` JSON shape, gRPC reply, Behat scenarios) are **frozen** and must
remain byte-for-byte unchanged unless explicitly opted in.

> Epic-to-phase map: **Epic A = P0**, **Epic B = P1**, **Epic C = P2–P3**,
> **Epic D = P4**, **Epic E = P5–P6**.

## Requirements Inventory

### Functional Requirements

FR1: Each domain module exposes its capabilities only through interfaces (ports); no module
references another module's concrete repository, DTO, or table. Cross-module contracts live at
module edges.
FR2: `MetricsRepository` must stop querying foreign domains' tables directly; metrics are sourced
via each owning module's contract (per-module counter ports), aggregated by `MetricsService`.
FR3: When the scanner detects a new release, it resolves the recipient list for that repository via
the Subscription module (`SubscriberFinder`) and publishes one `SendReleaseEmail` integration
message per recipient to RabbitMQ.
FR4: The `SendReleaseEmail` payload carries everything the service needs to send and to dedupe
without calling back: `subscriptionId`, `email`, `repository`, `release` (tagName, name, htmlUrl,
publishedAt), plus `eventId` and `occurredAt`.
FR5: Publishing must be reliable: if publishing the batch for a repository fails, the scanner must
NOT advance `last_seen_tag` for that repo (it retries next cycle). Successful publish ⇒ advance
marker.
FR6: The in-process `NotificationDispatcher` and the monolith's dependency on
`NotificationLedgerInterface` / SMTP are removed from the monolith.
FR7: The service consumes `SendReleaseEmail` from a durable RabbitMQ queue, renders the email, and
sends it via SMTP.
FR8: The service is idempotent: before sending it checks its own ledger
(`hasSuccessfulNotification(subscriptionId, repository, tag)`); after sending it records the result
(`recordResult`). Duplicate deliveries (redelivery or re-publish across scan cycles) must not send
duplicate emails.
FR9: On transient send failure the message is negatively acknowledged for bounded retry, with a
dead-letter path after N attempts; the ledger records attempts and `last_error`.
FR10: The service exposes its own health check and metrics endpoint.
FR11: REST `/subscriptions` JSON shape, gRPC reply shape, and existing Behat scenarios remain
unchanged.

### NonFunctional Requirements

NFR1: **Idempotency / at-least-once** — end-to-end exactly-once *effect* (no duplicate emails) via
the service-side ledger; the transport is at-least-once.
NFR2: **Independent deployability** — service builds, migrates, and runs without the monolith's
database; monolith runs without the service's database.
NFR3: **Resilience** — *Descoped 2026-06-15.* Resilience proof removed; broker/service-down
liveness and queued-delivery-on-recovery are no longer tracked.
NFR4: **Observability** — structured logs + metrics on both sides; published vs. consumed vs.
delivered counts are derivable; correlate via `eventId`.
NFR5: **Quality gates** — lint (PHPCS + deptrac), phpunit, psalm 100%, acceptance pass for the
monolith; the service has its own equivalent suite + gates.
NFR6: **Local dev** — a single `docker compose up` brings up monolith, service, RabbitMQ, both
Postgres, Redis, MailHog/SMTP stub.

### Additional Requirements

_(Architecture-derived technical requirements that shape implementation.)_

- **AR-ARCH1 — Clean/Hexagonal layering per module.** Every module is `Domain → Application →
  Infrastructure`; dependency rule points inward only. Domain has zero framework/IO deps;
  Application depends on Domain only; Infrastructure (incl. Slim controllers, gRPC handlers, CLI)
  depends on Application + Domain. (arch §1, §3)
- **AR-ARCH2 — CodelyTV-aligned monorepo layout.** Code lives in `src/<Context>/<Module>/<Layer>`;
  deployables live in `apps/` (`apps/monolith/{http,grpc,scanner}`, `apps/notification/{consumer,
  http,migrations}`). (arch §4)
- **AR-DDD1 — Shared kernel value objects.** `RepositoryName` (validates `owner/repo`),
  `EmailAddress` (absorbs `EmailValidator`), `ReleaseTag` in `Shared/Domain/ValueObject`. (arch §5)
- **AR-DDD2 — Rich aggregate roots recording domain events.** `Subscription` (emits
  `SubscriptionCreated`) and `RepositoryStatus` (emits `ReleaseSeenAdvanced` / `RepositoryChecked`)
  extend `Shared\Domain\Aggregate\AggregateRoot`; events pulled via `pullDomainEvents()`. `Release`
  stays an **anemic VO snapshot** (no identity). **This reverses CLAUDE.md's anemic-DTO rule for
  entities → CLAUDE.md must be updated.** (arch §1, §5; CLAUDE.md follow-on)
- **AR-DDD3 — CQRS-lite in-house buses.** `CommandBus` / `QueryBus` interfaces in
  `Shared\Domain\Bus`; in-memory handler-locator adapters in `Shared\Infrastructure\Bus`. Use-cases
  are `*CommandHandler` / `*QueryHandler`. **No Symfony Messenger.** (arch §5)
- **AR-DDD4 — Two event planes.** (a) In-process **PSR-14** domain events (sync, in-memory) —
  introduce `EventDispatcherInterface` + `ListenerProviderInterface`, add `psr/event-dispatcher` as
  a direct dependency (no PSR-14 plane exists on this branch yet); (b) cross-service **RabbitMQ**
  integration messages — `SendReleaseEmail` is an integration **command**, not a domain event.
  (arch §5)
- **AR-FLOW1 — `NewReleaseDetected` decoupling.** `ScanReleases` raises `NewReleaseDetected{repo,
  release}` on the PSR-14 bus; listener `PublishReleaseEmailsOnNewReleaseDetectedListener`
  (`Notification\Publishing`) resolves recipients (Subscription port) and publishes per-recipient
  `SendReleaseEmail`. Scanning depends ONLY on Releases + RepositoryTracking. (arch §5)
- **AR-FLOW2 — Pre-commit synchronous dispatch, no outbox.** `NewReleaseDetected` is dispatched
  synchronously **before** `markReleaseSeen` persists; the marker advances **only after the listener
  returns OK**. Crash window self-heals via re-detection next cycle + idempotent consumer.
  (arch §5, §14; PRD §9)
- **AR-MQ1 — RabbitMQ topology.** Durable topic exchange `notifications` → routing key
  `release.email` → durable (lazy) queue `notifications.send-email` (with
  `x-dead-letter-exchange: notifications.dlx`) → after N attempts → `notifications.dlx` →
  `notifications.send-email.dlq`. **Publisher confirms enabled.** (arch §7)
- **AR-MQ2 — Wire schema `SendReleaseEmail/v1`.** JSON, additive-only, versioned (`"schema":
  "SendReleaseEmail/v1"`); consumer tolerates unknown fields and rejects malformed messages straight
  to DLQ (anti-corruption maps wire → Domain VOs). (arch §7)
- **AR-DATA1 — Data ownership split.** Monolith Postgres keeps `subscriptions`, `repositories` and
  **drops** `release_notifications`. Notification Postgres (new) owns `release_notifications` with
  the FK to `subscriptions` **removed**; `subscription_id` becomes a plain `INTEGER` reference.
  Idempotency via `UNIQUE(subscription_id, repository, tag_name)`. (arch §9; PRD §8)
- **AR-DEPTRAC — Boundary enforcement via deptrac.** `deptrac.yaml` defines layers and contexts;
  forbids any Domain→Infrastructure edge, cross-context Infrastructure deps, and
  `apps/notification` depending on monolith-only contexts. Wired into `composer lint` and CI.
  (arch §4)
- **AR-DOCKER — Compose stack.** New services: `rabbitmq`, `notification-svc`, `notification-db`,
  `mailhog`; Makefile targets for up/migrate/logs. (arch §11; NFR6)
- **AR-DOCS — Architecture docs sync.** LikeC4 model in `docs/architecture/` + ADR updated to show
  the new service, RabbitMQ, and the two databases (via `likec4-architecture-sync`). (arch §15;
  AC7)
- **AR-MAP — Current → target class mapping.** Honor the explicit move table (arch §13) when
  relocating `ScannerService`, `NotificationDispatcher`, `NotifierService`, `SmtpMailer`,
  `ReleaseEmailRenderer`, `NotificationLedger`, `SubscriptionService`, controllers, gRPC,
  `MetricsRepository`. (arch §13)

### UX Design Requirements

_None._ This project has no UI surface beyond the REST/gRPC contract; there is no UX Design
Specification input. The only "interface" requirements are the **frozen wire contracts** captured
in FR11 and enforced throughout. No UX-DRs apply.

### FR Coverage Map

- FR1 → **Epic A** (deptrac baseline, module skeleton, ports) + **Epic B** (every context moved
  behind ports; deptrac green).
- FR2 → **Epic B / B5** (per-context count ports + `MetricsService` aggregation; remove
  `MetricsRepository` cross-table COUNT).
- FR3 → **Epic C** (`SubscriberFinder` resolution + `PublishReleaseEmailsOnNewReleaseDetectedListener`
  listener publishing per recipient) ; switched live in **Epic E**.
- FR4 → **Epic C** (`SendReleaseEmail/v1` message DTO + factory carrying full payload + `eventId`).
- FR5 → **Epic E / E1** (marker advances only on successful publish; publish failure ⇒ no advance);
  semantics introduced at cutover.
- FR6 → **Epic E / E4** (remove in-process dispatcher, ledger dep, SMTP from monolith).
- FR7 → **Epic D** (`SendReleaseEmailHandler`: consume → render → send via SMTP).
- FR8 → **Epic D** (idempotency ledger check + recordResult; `UNIQUE` constraint) ; proven in
  **Epic E / E2**.
- FR9 → **Epic D** (nack → bounded retry → DLQ; ledger attempts + `last_error`).
- FR10 → **Epic D** (service `/health` + `/metrics`).
- FR11 → **Epic B** (refactors preserve wire shapes) + **Epic E** (end-to-end verification keeps
  Behat/JSON/gRPC green). Verified by gates in **every** story.

NFR coverage: NFR1 → D + E2; NFR2 → D + E (separate DB/app, drop monolith table); NFR3 → descoped (resilience proof removed 2026-06-15); NFR4 → C/D (metrics + logs, `eventId` correlation) + B5; NFR5 → quality-gate note
on **every** story; NFR6 → C3 (compose) + D6 (wire service into compose).

## Epic List

### Epic A: Clean-Architecture Foundations (Shared Kernel, Buses, Boundary Enforcement)
Establish the structural and DDD scaffolding the whole refactor stands on: Shared-kernel value
objects, the `AggregateRoot` base with domain-event recording, the PSR-14 in-process event plane,
the in-house CQRS Command/Query buses, the `src/<Context>/<Module>/<Layer>` + `apps/` skeleton, and
**deptrac** wired into `composer lint`. Also update `CLAUDE.md` so its conventions match the new
rules. No behavior changes; all gates stay green. (Phase P0)
**FRs covered:** FR1 (foundation) ; supports FR2–FR11 downstream. NFR5.

### Epic B: Modularize the Monolith Behind Contracts (Behavior-Preserving)
Move every remaining context into `Domain/Application/Infrastructure`, turn controllers/gRPC/CLI
into thin drivers over the bus, introduce aggregate roots where they belong, and fix the
`MetricsRepository` cross-table leak via per-context count ports. One story per context
(Subscription, RepositoryTracking, Releases, Scanning, Shared/Platform). Pure structural refactor —
public wire contracts unchanged. (Phase P1)
**FRs covered:** FR1, FR2 ; preserves FR11. NFR5.

### Epic C: Integration Foundation (Publisher Port, Domain Event, RabbitMQ Infra) — Not Yet Cut Over
Introduce the asynchronous integration seam **without changing runtime behavior yet**: the
`ReleaseNotificationPublisher` port + `SendReleaseEmail/v1` message, the `NewReleaseDetected` domain
event and the `PublishReleaseEmailsOnNewReleaseDetectedListener` listener, the RabbitMQ + MailHog +
notification-db docker stack, and the shared messaging infrastructure (connection, confirm-publisher,
consumer base). The Rabbit publisher adapter is **built but not default** — the in-process adapter
still runs, so behavior is preserved. (Phases P2–P3)
**FRs covered:** FR3 (recipient resolution + per-recipient publish, wired but not live), FR4
(message payload). NFR4, NFR6.

### Epic D: Notification Microservice (Consume → Render → Send, Idempotent)
Build the standalone `apps/notification` deployable: its own composer/Dockerfile/gates, its own
Postgres migration (`release_notifications`, no FK), the `Notification\Sending` Domain + Application
(`SendReleaseEmailHandler`: idempotency → render → send → record), and Infrastructure (Rabbit consumer
with ack/nack/DLQ, PDO ledger, PHPMailer, renderer, health/metrics). Wire into compose and prove an
end-to-end email lands in MailHog. (Phase P4)
**FRs covered:** FR7, FR8, FR9, FR10. NFR1, NFR2, NFR4.

### Epic E: Cutover, Idempotency Proof, and Monolith Decommission
Flip Scanning to the Rabbit publisher and introduce the semantic change (marker advances on
successful publish). Prove no duplicate emails on re-publish/redelivery. Then remove the in-process dispatcher/notifier/ledger/SMTP from
the monolith, drop `release_notifications` from the monolith DB, and update README, ADR, and the
LikeC4 model. (Phases P5–P6)
**FRs covered:** FR5, FR6 ; proves FR3, FR8 ; preserves FR11. NFR1, NFR2, NFR4.

---

## Epic A: Clean-Architecture Foundations (Shared Kernel, Buses, Boundary Enforcement)

**Phase:** P0. **Goal:** lay the non-breaking structural + DDD scaffolding for the whole refactor.
**Covers:** FR1 (foundation), NFR5. **Depends on:** nothing (first epic).
**Quality gates (every story):** `composer lint` (PHPCS PSR-12 + deptrac once wired),
`./vendor/bin/phpunit --no-coverage`, `composer psalm` (100% types). Wire contracts untouched.

### Story A1: Shared-kernel value objects (RepositoryName, EmailAddress, ReleaseTag)

As a platform maintainer,
I want immutable value objects for the core domain primitives in a Shared kernel,
So that contexts exchange validated, typed values instead of bare strings and primitive obsession is
eliminated.

**Scope / files:** `src/Shared/Domain/ValueObject/RepositoryName.php` (validates `owner/repo`,
absorbs `RepositoryNameValidator` rule), `.../EmailAddress.php` (absorbs `EmailValidator` rule),
`.../ReleaseTag.php`. `final readonly class`, throw a Shared invalid-argument domain exception on bad
input. Mirror tests under `tests/Shared/Domain/ValueObject/`. Do **not** yet rewire existing
signatures (introduced behind existing signatures incrementally in Epic B).

**Acceptance Criteria:**

**Given** a string `"owner/repo"`
**When** `RepositoryName::fromString()` (or constructor per chosen convention) is called
**Then** a `RepositoryName` is created exposing `value()` / `owner()` / `repo()`
**And** an invalid string (no slash, empty, illegal chars) throws a Shared domain exception with the
same validity rules the current `RepositoryNameValidator` enforces.

**Given** an invalid email string
**When** an `EmailAddress` is constructed
**Then** it throws, applying exactly the rule today's `EmailValidator` applies (no behavior drift).

**Given** the new VOs
**When** the test suite and gates run
**Then** `composer lint`, `phpunit`, and `composer psalm` (100% types) all pass with `#[\Override]`
on any interface methods and `final readonly class`.

**Dependencies:** none.
**Quality gates:** lint, phpunit, psalm.

### Story A2: AggregateRoot base with domain-event recording

As a domain modeler,
I want a `Shared\Domain\Aggregate\AggregateRoot` base plus a `DomainEvent` contract,
So that entities can record domain events and have them pulled and dispatched uniformly.

**Scope / files:** `src/Shared/Domain/Aggregate/AggregateRoot.php` (protected `record(DomainEvent)`,
public `pullDomainEvents(): DomainEvent[]` that drains the buffer), `src/Shared/Domain/DomainEvent.php`
(interface: `eventId()`, `occurredOn()`, plus aggregate identity/name accessors per CodelyTV style).
Tests under `tests/Shared/Domain/Aggregate/`. No entity adopts it yet (that is Epic B).

**Acceptance Criteria:**

**Given** a test aggregate that calls `record()` twice
**When** `pullDomainEvents()` is called
**Then** it returns both events in order **and** a second call returns an empty array (buffer
drained).

**Given** the `DomainEvent` interface
**When** a concrete event implements it
**Then** Psalm reports 100% types and `#[\Override]` is present on implementing methods.

**Dependencies:** none (can run parallel to A1).
**Quality gates:** lint, phpunit, psalm.

### Story A3: PSR-14 in-process event plane (dispatcher + listener provider)

As a platform maintainer,
I want a synchronous, in-memory PSR-14 event dispatcher and listener provider,
So that domain/process events (`NewReleaseDetected`, `SubscriptionCreated`, …) can drive in-process
reactions without leaving the process.

**Scope / files:** add `psr/event-dispatcher` as a **direct** composer dependency (none on this
branch). `src/Shared/Infrastructure/Event/InMemoryEventDispatcher.php` (implements
`Psr\EventDispatcher\EventDispatcherInterface`), `.../ListenerProvider.php` (implements
`ListenerProviderInterface`, maps event class → listeners). Synchronous, in-memory; exceptions from
listeners propagate to the caller (required for AR-FLOW2 pre-commit semantics). Tests under
`tests/Shared/Infrastructure/Event/`.

**Acceptance Criteria:**

**Given** a listener registered for event class `E`
**When** an instance of `E` is dispatched
**Then** the listener runs synchronously in the same call stack.

**Given** a listener that throws
**When** its event is dispatched
**Then** the exception propagates to the dispatcher caller (NOT swallowed), so a downstream publish
failure can abort marker advancement.

**Given** `composer.json`
**When** dependencies are inspected
**Then** `psr/event-dispatcher` appears as a direct `require` entry.

**Dependencies:** none (parallel to A1/A2).
**Quality gates:** lint, phpunit, psalm.

### Story A4: In-house CQRS Command/Query buses

As an application developer,
I want in-memory Command and Query buses (interfaces in `Shared\Domain\Bus`, adapters in
`Shared\Infrastructure\Bus`),
So that thin drivers can dispatch a Command/Query to its single handler — without Symfony Messenger.

**Scope / files:** `src/Shared/Domain/Bus/Command/{Command,CommandBus,CommandHandler}.php`,
`src/Shared/Domain/Bus/Query/{Query,QueryBus,QueryHandler,Response}.php`;
`src/Shared/Infrastructure/Bus/InMemoryCommandBus.php`, `.../InMemoryQueryBus.php` with a
handler-locator that maps command/query class → handler. Tests under `tests/Shared/.../Bus/`.

**Acceptance Criteria:**

**Given** a command bound to one handler
**When** the command is dispatched
**Then** exactly that handler executes; an unbound command throws a clear "no handler" exception.

**Given** a query bound to one handler returning a `Response`
**When** the query is asked
**Then** the typed response is returned (Psalm 100% types, no `mixed` leak across the bus boundary).

**Dependencies:** none (parallel to A1–A3).
**Quality gates:** lint, phpunit, psalm.

### Story A5: Module skeleton + apps/ scaffolding

As a maintainer,
I want the empty `src/<Context>/<Module>/<Layer>` directories and the `apps/` deployable scaffolding
in place,
So that Epic B can move classes into their target homes with no structural ambiguity.

**Scope / files:** create the directory tree per arch §4 (`src/Shared/...`, `src/Subscription/
Subscriptions/...`, `src/RepositoryTracking/Repositories/...`, `src/Releases/Sourcing/...`,
`src/Scanning/Scanner/...`, `src/Notification/Publishing/...`); `apps/monolith/{http,grpc,scanner}`
placeholders. Confirm/extend PSR-4 autoload so `App\` → `src/` still resolves the nested namespaces
(or add explicit mappings). No classes moved yet (Epic B does the moves). Keep existing `bin/`
entrypoints functioning.

**Acceptance Criteria:**

**Given** the new tree
**When** `composer dump-autoload` runs
**Then** it completes without PSR-4 mapping warnings and existing classes still autoload.

**Given** the current entrypoints (`bin/scanner.php`, `bin/grpc.php`, REST)
**When** they boot
**Then** they still run unchanged (no class moved yet) and all gates pass.

**Dependencies:** none (structural; parallel-safe).
**Quality gates:** lint, phpunit, psalm.

### Story A6: deptrac setup + deptrac.yaml baseline, wired into composer lint

As a maintainer,
I want deptrac configured with layer + context rules and added to `composer lint` and CI,
So that the Clean-Architecture and bounded-context boundaries are machine-enforced from here on.

**Scope / files:** add `qossmic/deptrac` (or shipped phar) as a dev tool; `deptrac.yaml` defining
**layers** (Domain, Application, Infrastructure) and **context** layers (Shared, Subscription,
RepositoryTracking, Releases, Scanning, Notification\Publishing, Notification\Sending, apps/*).
Rules: forbid Domain→Infrastructure and Domain→Application edges; forbid cross-context Infrastructure
deps; forbid `apps/notification` → monolith-only contexts (enforced fully once those exist in later
epics). Update `composer.json` `lint` script to run PHPCS **and** deptrac. A **baseline** captures
current (pre-refactor) violations so the gate is green on day one and shrinks over Epic B.

**Acceptance Criteria:**

**Given** `deptrac.yaml` and the baseline
**When** `composer lint` runs
**Then** PHPCS (PSR-12) and deptrac both run and the command exits 0.

**Given** a deliberate test violation (e.g., a Domain class importing a PDO adapter)
**When** deptrac runs
**Then** it reports the violation as an error (proving the rules are active, not no-ops).

**Given** Epic B progresses
**When** classes move into correct layers
**Then** the deptrac baseline can be regenerated smaller (tracked as Epic B exit criteria).

**Dependencies:** A5 (needs the context directories to assign layers).
**Quality gates:** lint (now includes deptrac), phpunit, psalm.

### Story A7: Update CLAUDE.md conventions for the new architecture

As a contributor / AI agent,
I want `CLAUDE.md` (and its companion `project-context.md`) to describe the new conventions,
So that the documented rules and the code agree — entities are aggregates, buses exist, deptrac is a
gate.

**Scope / files:** edit `CLAUDE.md`: change the "DTOs are anemic / no `from*`" rule to "**anemic VO
snapshots; entities are aggregate roots with domain events**" (per arch §1, §15 follow-on); document
the `src/<Context>/<Module>/<Layer>` + `apps/` layout; add CQRS-lite buses, PSR-14 plane, and the
RabbitMQ integration message; add **deptrac** to the Quality-gates section (`composer lint` now =
PHPCS + deptrac). Keep `#[\Override]`, `final readonly` (for stateless), interface-only DI rules.
Mirror the relevant deltas into `_bmad-output/project-context.md`.

**Acceptance Criteria:**

**Given** `CLAUDE.md`
**When** a reader checks the DTO rule
**Then** it states entities are aggregate roots recording domain events while `Release` stays an
anemic VO snapshot — no contradiction with the codebase.

**Given** the Quality-gates section
**When** read
**Then** `composer lint` is documented as PHPCS PSR-12 **+ deptrac**.

**Given** the layout section
**When** read
**Then** it documents `src/<Context>/<Module>/{Domain,Application,Infrastructure}` and `apps/`.

**Dependencies:** A1–A6 (documents what they introduced).
**Quality gates:** docs-only; still run lint/phpunit/psalm to confirm nothing broke.

---

## Epic B: Modularize the Monolith Behind Contracts (Behavior-Preserving)

**Phase:** P1. **Goal:** move every context into `Domain/Application/Infrastructure`, thin the
drivers over the bus, add aggregates, fix the metrics leak — with **zero** behavior change.
**Covers:** FR1, FR2 ; preserves FR11. **Depends on:** Epic A (skeleton, buses, VOs, deptrac, base
classes).
**Quality gates (every story):** lint (PHPCS + deptrac, baseline shrinking), phpunit, psalm 100%.
**Wire contracts frozen** — REST JSON, gRPC reply, Behat unchanged.

### Story B1: Subscription context — Domain (aggregate) + Application (CQRS) + thin Infra drivers

As a maintainer,
I want the Subscription domain moved into `src/Subscription/Subscriptions/{Domain,Application,
Infrastructure}` with `Subscription` as an aggregate root and use-cases behind the bus,
So that subscription capabilities are exposed only via ports and the controller/gRPC become thin
drivers — with the public JSON/gRPC shape unchanged.

**Scope / files (per arch §13 mapping):**
- Domain: `Subscription` aggregate root (extends `AggregateRoot`, records `SubscriptionCreated`),
  `SubscriberRef`, `SubscriberCollection`, `SubscriptionPage`; ports `SubscriptionRepository`,
  `SubscriberFinder`; subscription exceptions. Use `EmailAddress` / `RepositoryName` VOs (from A1).
- Application: `Subscribe/` (`SubscribeCommand` + `SubscribeCommandHandler`, replacing
  `SubscriptionService::subscribe`), `Find/` (query + handler), `List/` (paged query + handler).
- Infrastructure: `Persistence/PdoSubscriptionRepository`, `Http/SubscriptionController` (thin —
  builds command/query, dispatches via bus, maps to the **existing** JSON shape), gRPC handler bits
  routed through the bus, `Validation/` adapters, `Factory/` ACL mappers (parse DB/API → Domain).
- Keep `subscriptions` table ownership here.

**Acceptance Criteria:**

**Given** a POST to `/subscriptions` with valid `(email, repository)`
**When** handled via the new `SubscribeCommandHandler`
**Then** the response JSON shape is **identical** to today (Behat scenarios pass unchanged) and a
`Subscription` aggregate recorded a `SubscriptionCreated` event (pulled, dispatchable).

**Given** the gRPC subscribe/list calls
**When** invoked
**Then** the reply shape is unchanged (gRPC contract frozen).

**Given** deptrac
**When** `composer lint` runs
**Then** no Subscription Domain→Infrastructure edges and no cross-context concrete reach-ins are
reported; the baseline shrinks for this context.

**Dependencies:** A1–A6.
**Quality gates:** lint+deptrac, phpunit, psalm. Behat unchanged (ask before booting docker).

### Story B2: RepositoryTracking context — RepositoryStatus aggregate + role ports + use-cases

As a maintainer,
I want the scan-registry/progress domain moved into `src/RepositoryTracking/Repositories/...` with
`RepositoryStatus` as an aggregate root and its four role ports preserved,
So that tracked-repository state and progress are owned by one context behind ISP ports.

**Scope / files (arch §13):**
- Domain: `RepositoryStatus` aggregate (records `ReleaseSeenAdvanced` / `RepositoryChecked`); ports
  `ScanCandidateSource`, `ScanProgressWriter`, `RepositoryStatusReader`, `TrackedRepositoryRegistrar`
  (1:1 with today's narrow interfaces).
- Application: `Register/`, `GetDueForScan/`, `MarkChecked/`, `MarkReleaseSeen/` (command/query +
  handlers).
- Infrastructure: `Persistence/PdoTrackedRepositoryReader` + `...Writer`, `Factory/`.
- Owns `repositories` table.

**Acceptance Criteria:**

**Given** `markReleaseSeen(repo, tag)` via the new handler
**When** executed
**Then** `last_seen_tag` is persisted exactly as today and a `ReleaseSeenAdvanced` event is recorded
on the aggregate.

**Given** the scan-candidate query
**When** asked
**Then** it returns the same due-for-scan set as the current `ScanCandidateSource`.

**Given** deptrac
**When** lint runs
**Then** the four role ports live in Domain, adapters in Infrastructure, no cross-table reach-ins.

**Dependencies:** A1–A6. (Independent of B1.)
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story B3: Releases (GitHub sourcing) context — ReleaseSource port + cache adapters

As a maintainer,
I want GitHub release sourcing moved into `src/Releases/Sourcing/...` behind a single `ReleaseSource`
port,
So that release fetching/caching is one context exposing `repositoryExists` + `getLatestRelease`,
with `Release` as an anemic VO snapshot.

**Scope / files (arch §13):**
- Domain: `Release` (stays **anemic readonly VO**), `ReleaseSource` port (`repositoryExists`,
  `getLatestRelease`), `RateLimitException` (GitHub-context domain exception).
- Application: `FetchLatestRelease/` and `RepositoryExists/` queries + handlers (wrapping current
  `GitHubService`).
- Infrastructure: `GitHubApiClient` (Guzzle), `Cache/` (Redis `RedisGitHubCache`,
  `SafeGitHubCacheDecorator` — **keeps mutable state, not `readonly`**, per CLAUDE.md exception,
  `NullGitHubCache`). Redis stays cache-only.

**Acceptance Criteria:**

**Given** a repo with a newer release
**When** `getLatestRelease` is asked
**Then** it returns the same `Release` VO data as today, served through the same Redis cache path.

**Given** GitHub rate-limit responses
**When** sourcing runs
**Then** `RateLimitException` is raised from this context (caught later at the Scanning use-case, not
inside the unit) — behavior unchanged.

**Given** deptrac
**When** lint runs
**Then** `Release` and `ReleaseSource` are in Domain; Guzzle/Redis only in Infrastructure.

**Dependencies:** A1–A6.
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story B4: Scanning context — ScannerService → ScanReleases command/handler

As a maintainer,
I want `ScannerService` moved into `src/Scanning/Scanner/Application/ScanReleases/*` as a command +
handler driven by a thin CLI loop,
So that orchestration depends only on the Releases + RepositoryTracking ports and the
new-vs-seen comparison stays in Scanning.

**Scope / files (arch §13, §14):**
- Application: `ScanReleases/` (`ScanReleasesCommand` + `ScanReleasesCommandHandler`, ex-
  `ScannerService::scan`; keeps the new-vs-seen comparison using RepositoryTracking + Releases
  ports; catches `RateLimitException` and control-flow exceptions at this orchestration layer).
- Infrastructure: `Cli/` scanner loop (thin driver over the command bus; `bin/scanner.php` /
  `apps/monolith/scanner` invokes it).
- **Behavior preserved in this epic:** for now the handler still triggers the existing in-process
  notification path (no domain event / publisher yet — those arrive in Epic C). `markReleaseSeen`
  semantics unchanged until Epic E.

**Acceptance Criteria:**

**Given** the scanner CLI
**When** it runs a cycle
**Then** it detects new releases and advances `last_seen_tag` exactly as today (no semantic change
yet), with the same logging.

**Given** a GitHub rate-limit during a cycle
**When** scanning runs
**Then** the exception is caught at the `ScanReleasesCommandHandler` orchestration layer (not inside
the unit method) — unchanged behavior.

**Given** deptrac
**When** lint runs
**Then** Scanning depends only on Releases + RepositoryTracking ports (no edge to
Subscription/Notification yet) and shows no Domain→Infra violations.

**Dependencies:** B2, B3 (consumes their ports). A4 (bus).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story B5: Shared/Platform — error map, health, metrics (count-port fix), middleware, DI grouping

As a maintainer,
I want cross-cutting concerns consolidated under `Shared/Infrastructure` and the `MetricsRepository`
cross-table leak removed,
So that platform code lives in the shared kernel and metrics are sourced via each context's own count
port (FR2).

**Scope / files (arch §9, §10, §13):**
- Move `ExceptionStatusMap` → `Shared/Infrastructure/Error` (single HTTP+gRPC map, used by
  `ErrorHandlerMiddleware` and the gRPC service — unchanged mappings).
- Move health (`DatabaseHealthCheck`, `HealthCheckInterface`, `HealthController`) and metrics
  (`Gauge`, `Metric`, `PrometheusFormatter`, `MetricsController`) to `Shared/Infrastructure/{Health,
  Metrics}`.
- **FR2 fix:** delete `MetricsRepository`'s cross-table `COUNT`; add per-context count ports
  (`Subscription.count()` on a Subscription port, `RepositoryTracking.count()` on a
  RepositoryTracking port) and have `MetricsService` aggregate them. The `/metrics` output stays
  **identical**.
- Move `ApiKeyMiddleware`, `ErrorHandlerMiddleware` to `Shared/Infrastructure/Http`.
- Regroup `config/container.php` by module; bind **interfaces (ports) → adapters** only; alias
  shared instances (no concrete class as a DI key).

**Acceptance Criteria:**

**Given** the `/metrics` endpoint
**When** scraped
**Then** the Prometheus output is byte-identical to today, but the counts now come from per-context
count ports — `MetricsRepository` no longer queries `subscriptions`/`repositories` directly.

**Given** any mapped exception
**When** raised on HTTP or gRPC
**Then** `ExceptionStatusMap` (now in Shared) yields the same status — no mapping drift.

**Given** deptrac
**When** lint runs
**Then** Metrics no longer reaches across contexts to foreign tables; the cross-table edge is gone
from the baseline (baseline regenerated to its minimal end-of-Epic-B state).

**Dependencies:** B1, B2 (needs their count ports). A1–A6.
**Quality gates:** lint+deptrac, phpunit, psalm. Behat unchanged.

---

## Epic C: Integration Foundation (Publisher Port, Domain Event, RabbitMQ Infra) — Not Yet Cut Over

**Phase:** P2–P3. **Goal:** add the async integration seam and infra **without** changing runtime
behavior; the in-process adapter stays default.
**Covers:** FR3 (wired, not live), FR4 ; NFR4, NFR6. **Depends on:** Epic B (all contexts modular,
`SubscriberFinder` available).
**Quality gates (every story):** lint+deptrac, phpunit, psalm. Wire contracts frozen.

### Story C1: SendReleaseEmail/v1 integration message + ReleaseNotificationPublisher port

As an integration developer,
I want the `SendReleaseEmail/v1` message type and a `ReleaseNotificationPublisher` port in
`Notification\Publishing`,
So that there is a versioned, additive-only contract for per-recipient notification intents and a
stable seam to publish them.

**Scope / files (arch §5, §7):**
- Domain: `Notification/Publishing/Domain/SendReleaseEmail.php` (integration message carrying
  `schema="SendReleaseEmail/v1"`, `eventId`, `occurredAt`, `subscriptionId`, `email`, `repository`,
  `release{tagName,name,htmlUrl,publishedAt}`), `ReleaseNotificationPublisher` port
  (`publish(SendReleaseEmail): void`).
- A factory/serializer that maps the message ↔ JSON wire shape (additive-only).
- **Contract test** asserting the JSON serialization exactly matches arch §7's schema.

**Acceptance Criteria:**

**Given** a `SendReleaseEmail` instance
**When** serialized to JSON
**Then** the JSON has exactly the §7 fields (incl. `"schema":"SendReleaseEmail/v1"`) and a contract
test pins the shape.

**Given** the port
**When** Psalm analyzes it
**Then** it is a Domain interface with no Infrastructure dependency (deptrac green), 100% types.

**Dependencies:** A1 (VOs), B5 (Shared).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story C2: NewReleaseDetected domain event + PublishReleaseEmailsOnNewReleaseDetectedListener listener

As an integration developer,
I want a `NewReleaseDetected` PSR-14 event raised by Scanning and a listener that resolves recipients
and publishes one `SendReleaseEmail` per recipient,
So that Scanning is decoupled from Subscription/Notification and the per-recipient fan-out exists
(FR3) — using whichever publisher adapter is bound (in-process by default in this epic).

**Scope / files (arch §5, §8):**
- `src/.../NewReleaseDetected.php` (process-level domain event `{repository, release}`), dispatched
  on the PSR-14 bus by `ScanReleasesCommandHandler`.
- `Notification/Publishing/.../PublishReleaseEmailsOnNewReleaseDetectedListener.php` listener:
  resolves recipients via the Subscription port — canonical signature
  `SubscriberFinderInterface::findSubscribersByRepository(string $repository): SubscriberCollection`
  — builds a `SendReleaseEmail` per recipient, calls `ReleaseNotificationPublisher::publish`.
  **Throws on failure** so the sync dispatch can later gate the marker (AR-FLOW2).
- Register the listener in the PSR-14 listener provider.
- **Default publisher remains in-process** (Story C5) so behavior is preserved; marker semantics
  unchanged until Epic E.

**Acceptance Criteria:**

**Given** Scanning detects a new release for a repo with N subscribers
**When** `NewReleaseDetected` is dispatched synchronously
**Then** the listener resolves N recipients and calls `publish` exactly N times (one per recipient).

**Given** the publisher throws on a recipient
**When** the listener runs
**Then** the exception propagates out of the synchronous dispatch (so Epic E can decline to advance
the marker).

**Given** deptrac
**When** lint runs
**Then** Scanning has **no** direct edge to Subscription/Notification (only the event); the listener
(Publishing) is the only thing depending on the Subscription `SubscriberFinder` port.

**Dependencies:** A3 (PSR-14), C1 (message + port), B1 (`SubscriberFinder`), B4 (Scanning handler).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story C3: RabbitMQ + notification-db in docker compose (reuse existing MailHog)

As an operator,
I want RabbitMQ (with management UI) and a separate notification Postgres added to docker compose with
Makefile targets — reusing the MailHog that already exists,
So that the full local stack can run on a single `docker compose up` (NFR6) ahead of building the
service.

**Scope / files (arch §11):**
- `docker-compose.yml`: add `rabbitmq` (broker + management) and `notification-db` (Postgres).
  **MailHog already exists** (`docker-compose.yml` lines 68–72, ports 1025/8025) — reuse it, do NOT
  re-add. Keep existing `app`, `grpc`, `scanner`, monolith Postgres, Redis, `mailhog`.
- Makefile targets for up / per-service logs / migrate. Env wiring for the new services.
- Declare the RabbitMQ topology (durable exchange `notifications`, queue
  `notifications.send-email`, DLX `notifications.dlx`, queue `...dlq`) — via a bootstrap step or
  documented for the publisher/consumer to assert-declare.

**Acceptance Criteria:**

**Given** `docker compose up`
**When** the stack starts
**Then** `rabbitmq` and `notification-db` are healthy alongside the existing services (MailHog already
present and reused), and the RabbitMQ management UI is reachable.

**Given** the broker
**When** topology is declared
**Then** exchange `notifications` (topic, durable), queue `notifications.send-email` (durable, with
DLX), and the `.dlq` exist as in arch §7.

**Given** existing services
**When** the new ones are added
**Then** monolith REST/gRPC/scanner still start and behave unchanged (no coupling introduced yet).

**Dependencies:** none structurally (infra), but sequenced after C1/C2 in this epic for coherence.
**Quality gates:** compose boots; monolith gates (lint+deptrac, phpunit, psalm) still green.

### Story C4: Shared messaging infrastructure (connection, confirm-publisher, consumer base)

As a platform developer,
I want shared RabbitMQ infrastructure in `Shared/Infrastructure/Messaging/Rabbit` (connection,
publisher with confirms, consumer base),
So that both the monolith publisher adapter and the service consumer reuse one reliable transport
layer.

**Scope / files (arch §4, §7):**
- `Shared/Infrastructure/Messaging/Rabbit/RabbitConnection.php`,
  `.../RabbitPublisher.php` (**publisher confirms enabled**; publishes to exchange `notifications`
  with routing key `release.email`), `.../RabbitConsumer.php` base (ack/nack, reads `x-death` count
  for bounded redelivery → DLQ).
- **Add `php-amqplib/php-amqplib`** (pure-PHP, no `ext-amqp`) to the **monolith** `composer.json` —
  this Shared messaging code lives in the monolith and is used by the C5 publisher adapter.
- Config/env for broker URL, exchange/queue names.
- Unit tests with a mocked channel; integration test optional behind the compose stack.

**Acceptance Criteria:**

**Given** the publisher
**When** it publishes a message
**Then** it uses publisher confirms and only returns success on broker ack; on negative
confirm/timeout it raises (so callers can react).

**Given** the consumer base
**When** a message exceeds N redeliveries (`x-death`)
**Then** it routes to the DLQ instead of infinite redelivery.

**Given** deptrac
**When** lint runs
**Then** messaging lives in `Shared/Infrastructure` only; no Domain depends on it.

**Dependencies:** C3 (broker to talk to), B5 (Shared infra home).
**Quality gates:** lint+deptrac, phpunit, psalm.

### Story C5: Rabbit publisher adapter built — in-process adapter remains default (behavior preserved)

As an integration developer,
I want a `RabbitReleaseNotificationPublisher` implementing the publisher port, **bound but not
default**,
So that the Rabbit path exists and is testable while the in-process adapter still runs — preserving
current behavior until Epic E cutover.

**Scope / files (arch §12 P2–P3):**
- `Notification/Publishing/Infrastructure/Rabbit/RabbitReleaseNotificationPublisher.php` (serializes
  `SendReleaseEmail` via C1's serializer; publishes via C4's confirm-publisher).
- Keep/define an **in-process** `ReleaseNotificationPublisher` adapter that invokes the current
  in-process notification path; **this remains the DI-bound default** in `config/container.php`.
- DI: a single switch point (env/config) selects the adapter; default = in-process.

**Acceptance Criteria:**

**Given** the default DI binding
**When** the scanner runs a cycle and detects a release
**Then** notifications still flow through the **in-process** path exactly as today (behavior
preserved; Behat/email behavior unchanged).

**Given** the Rabbit adapter selected in a test/config
**When** `publish` is called
**Then** a `SendReleaseEmail/v1` message lands on the `notifications.send-email` queue (verifiable
via the management UI / integration test).

**Given** deptrac
**When** lint runs
**Then** the Rabbit adapter is in Publishing/Infrastructure; the port stays in Domain.

**Dependencies:** C1, C2, C4. C3 (broker for the integration assertion).
**Quality gates:** lint+deptrac, phpunit, psalm. Monolith behavior unchanged.

---

## Epic D: Notification Microservice (Consume → Render → Send, Idempotent)

**Phase:** P4. **Goal:** stand up the standalone `apps/notification` service that consumes
`SendReleaseEmail`, renders + sends email, dedupes via its own ledger, and exposes health/metrics.
**Covers:** FR7, FR8, FR9, FR10 ; NFR1, NFR2, NFR4. **Depends on:** Epic C (message contract, broker,
compose, shared messaging).
**Quality gates (every story):** the **service's own** lint+deptrac, phpunit, psalm 100% (NFR5);
service builds/migrates/runs **without** the monolith DB (NFR2).

### Story D1: apps/notification app skeleton (own composer, Dockerfile, gates, container)

As a service owner,
I want an independent `apps/notification` deployable with its own composer, Dockerfile, DI container,
and quality gates,
So that the notification service builds and runs independently of the monolith (NFR2).

**Scope / files (arch §4, §6):**
- `apps/notification/{consumer,http}` entrypoints + `apps/notification/container.php` (wires
  `Notification\Sending` ONLY).
- Service composer setup (own `require` incl. PDO, **`php-amqplib/php-amqplib`** (pure-PHP, no
  `ext-amqp`), PHPMailer, Monolog), own `phpcs`/`psalm`/`phpunit`/`deptrac` config.
- Dockerfile for the worker; compose service `notification-svc` (added/wired in D6).
- deptrac rule: `apps/notification` may depend on `Shared` + `Notification\Sending` only — **never**
  monolith-only contexts (Subscription/RepositoryTracking/Releases/Scanning/Publishing).

**Acceptance Criteria:**

**Given** the service directory
**When** its own `composer install` + gates run
**Then** lint+deptrac, psalm (100%), and phpunit pass on the (initially minimal) service code.

**Given** deptrac for the service
**When** a test import of a monolith-only context is added
**Then** it is flagged as a forbidden dependency.

**Dependencies:** Epic C (shared messaging available to reuse or vendor). A6 (deptrac pattern).
**Quality gates:** service lint+deptrac, phpunit, psalm.

### Story D2: Notification DB migration — release_notifications, no FK, idempotency UNIQUE

As a service owner,
I want the service's own Postgres migration creating `release_notifications` **without** the FK to
`subscriptions`,
So that the service owns its idempotency ledger independently (NFR2, AR-DATA1) with a UNIQUE
constraint enforcing dedupe.

**Scope / files (arch §9, §13; PRD §8):**
- `apps/notification/migrations/001_create_release_notifications.sql`: same columns + indexes as
  today's `002_add_release_notifications.sql`, **minus** the FK; `subscription_id` is a plain
  `INTEGER`; add `UNIQUE(subscription_id, repository, tag_name)`; keep attempt/`last_error` columns.
- Service migration runner (its own, not the monolith's).

**Acceptance Criteria:**

**Given** the service migration
**When** run against `notification-db`
**Then** `release_notifications` exists with no FK to `subscriptions` and a
`UNIQUE(subscription_id, repository, tag_name)` constraint.

**Given** a duplicate `(subscription_id, repository, tag_name)` insert
**When** attempted
**Then** the UNIQUE constraint rejects it (foundation for FR8 dedupe).

**Dependencies:** D1, C3 (notification-db exists).
**Quality gates:** service gates; migration applies cleanly on a fresh DB (NFR2).

### Story D3: Notification\Sending Domain + Application (SendReleaseEmailHandler)

As a service developer,
I want the `Notification\Sending` Domain and the `SendReleaseEmailHandler` use-case,
So that consuming a `SendReleaseEmail` performs idempotency → render → send → record (FR7, FR8) with
clean layering.

**Scope / files (arch §6, §13):**
- Domain: `EmailNotification`, `NotificationResult`, ports `NotificationLedger`
  (`hasSuccessfulNotification(subscriptionId, repository, tag)`, `recordResult(...)`), `Mailer`,
  and the service's own VOs; map the wire message → Domain VOs (anti-corruption; tolerate unknown
  fields).
- Application: `SendReleaseEmail/SendReleaseEmailHandler::handle()` implementing the §6 sequence:
  (1) if `ledger.hasSuccessfulNotification` → success/ack (dedupe); (2) render; (3) `mailer.send`;
  (4) `ledger.recordResult`; (5) success → signal ack, transient failure → signal nack.

**Acceptance Criteria:**

**Given** a `SendReleaseEmail` whose `(subscriptionId, repository, tag)` is already recorded
successful
**When** the handler runs
**Then** it does **not** call the mailer and signals ack (no duplicate email) — FR8.

**Given** a fresh message
**When** the handler runs
**Then** it renders, sends via the `Mailer` port, records the result, and signals ack — FR7.

**Given** a transient mailer failure
**When** the handler runs
**Then** it records the attempt + `last_error` and signals nack for retry — FR9 (handler side).

**Dependencies:** D1, C1 (message shape).
**Quality gates:** service lint+deptrac, phpunit, psalm.

### Story D4: Notification\Sending Infrastructure — Rabbit consumer (ack/nack/DLQ), PDO ledger, PHPMailer, renderer

As a service developer,
I want the adapters: the Rabbit consumer wiring ack/nack/DLQ, the PDO ledger, the PHPMailer mailer,
and the email renderer,
So that the handler is driven by real infrastructure end-to-end (FR7, FR9) with bounded retry → DLQ.

**Scope / files (arch §6, §7, §13):**
- `Infrastructure/Rabbit/SendReleaseEmailConsumer.php` (binds `notifications.send-email`; on handler
  ack → ack; on transient nack → requeue/redeliver bounded by `x-death`; malformed/poison →
  reject to DLQ).
- `Infrastructure/Persistence/PdoNotificationLedger.php` (implements `NotificationLedger`; upsert
  semantics matching today; honors the UNIQUE constraint; records attempts + `last_error`).
- `Infrastructure/Mail/PhpMailerMailer.php` (implements `Mailer`, ex-`SmtpMailer`/`PHPMailerFactory`/
  `SmtpConfig`), `ReleaseEmailRenderer`, `RenderedEmail` — moved from the monolith.

**Acceptance Criteria:**

**Given** a valid message on the queue
**When** the consumer delivers it to the handler and the handler acks
**Then** the message is acked and an email is sent via PHPMailer (to MailHog in dev).

**Given** repeated transient failures beyond N attempts
**When** redelivery exhausts (`x-death`)
**Then** the message lands in `notifications.send-email.dlq` and the ledger holds the attempts +
`last_error` — FR9.

**Given** a malformed message
**When** consumed
**Then** it is rejected straight to the DLQ (anti-corruption) and does not crash the worker.

**Dependencies:** D2 (ledger table), D3 (handler + ports), C4 (consumer base), C3 (broker, MailHog).
**Quality gates:** service lint+deptrac, phpunit, psalm.

### Story D5: Service health + metrics endpoints

As an operator,
I want the notification service to expose its own `/health` and `/metrics`,
So that it is observable independently of the monolith (FR10, NFR4).

**Scope / files (arch §6, §10):**
- `apps/notification/http` exposes `/health` (broker + DB reachability) and `/metrics` (Prometheus:
  consumed, delivered, deduped, failed/DLQ counts; correlate by `eventId` in logs).
- Service's own small `ExceptionStatusMap` for its own exceptions (arch §10).

**Acceptance Criteria:**

**Given** the service is up with broker + DB reachable
**When** `/health` is queried
**Then** it reports healthy; when the DB or broker is down it reports unhealthy.

**Given** messages processed
**When** `/metrics` is scraped
**Then** consumed/delivered/deduped/failed counts are exposed (so published-vs-consumed-vs-delivered
is derivable — NFR4).

**Dependencies:** D1, D3, D4.
**Quality gates:** service lint+deptrac, phpunit, psalm.

### Story D6: Wire notification-svc into compose; end-to-end email via MailHog

As an operator,
I want `notification-svc` running in docker compose consuming the queue and sending to MailHog,
So that a published `SendReleaseEmail` is delivered end-to-end in local dev (AC3 foundation).

**Scope / files (arch §11):**
- Add/wire `notification-svc` service in `docker-compose.yml` (env: broker URL, notification-db DSN,
  MailHog SMTP). Makefile target to migrate + run the worker.
- Smoke flow: publish a `SendReleaseEmail/v1` (e.g., via the Rabbit publisher from C5 in a manual/
  test harness) → worker consumes → email visible in MailHog.

**Acceptance Criteria:**

**Given** `docker compose up` with `notification-svc`
**When** a `SendReleaseEmail/v1` is published to `notifications.send-email`
**Then** the worker consumes it, sends the email, and it appears in the MailHog inbox.

**Given** the service running
**When** the monolith DB is stopped
**Then** the service still consumes and sends (independence — NFR2).

**Dependencies:** D1–D5, C3, C5.
**Quality gates:** service gates green; end-to-end smoke passes; monolith gates still green.

---

## Epic E: Cutover, Idempotency Proof, and Monolith Decommission

**Phase:** P5–P6. **Goal:** flip Scanning to the Rabbit publisher with the new marker semantics,
prove idempotency, then decommission the in-process notification stack and drop the
monolith ledger table; update docs.
**Covers:** FR5, FR6 ; proves FR3, FR8 ; preserves FR11 ; NFR1, NFR2, NFR4.
**Depends on:** Epic D (working service) + Epic C (Rabbit publisher).
**Quality gates (every story):** monolith lint+deptrac, phpunit, psalm 100%; **Behat unchanged**;
service gates green.

### Story E1: Cut Scanning over to the Rabbit publisher + introduce publish-then-mark semantics (FR5)

As a maintainer,
I want the DI default switched to `RabbitReleaseNotificationPublisher` and `markReleaseSeen` to
advance **only after a successful publish** of the whole per-repo batch,
So that the monolith integrates asynchronously and the marker is a recomputable checkpoint written
after publish (FR5, AR-FLOW2; PRD §9 semantic change).

**Scope / files (arch §5, §8, §12 P5):**
- `config/container.php`: bind `ReleaseNotificationPublisher` → `RabbitReleaseNotificationPublisher`
  as the default.
- `ScanReleasesCommandHandler`: dispatch `NewReleaseDetected` **synchronously before**
  `markReleaseSeen`; advance the marker **only if** the synchronous dispatch (listener publish)
  returns OK. On publish failure (listener throws), do **not** advance — retried next cycle.

**Acceptance Criteria:**

**Given** a new release with N subscribers
**When** a scan cycle runs and all N publishes confirm
**Then** N `SendReleaseEmail/v1` messages are on the queue **and** `last_seen_tag` is advanced — FR3,
FR5.

**Given** a publish failure mid-batch (broker nack/timeout)
**When** the cycle runs
**Then** `last_seen_tag` is **not** advanced and the next cycle re-detects and re-publishes — FR5,
no outbox needed (self-heals).

**Given** the public surfaces
**When** REST/gRPC/Behat run
**Then** they are unchanged (FR11).

**Dependencies:** C5, C2, B4, D6 (service consuming on the other end).
**Quality gates:** monolith lint+deptrac, phpunit, psalm; Behat unchanged.

### Story E2: Idempotency proof — no duplicate email on re-publish / redelivery (AC4)

As a QA engineer,
I want an automated test proving that re-running a scan or redelivering a message sends **no**
duplicate email,
So that exactly-once *effect* over at-least-once transport is demonstrated (FR8, NFR1, AC4).

**Scope / files (PRD AC4; arch §6, §7):**
- Test (integration/e2e against the compose stack or service test suite) that: publishes the same
  `SendReleaseEmail` twice (and/or forces a Rabbit redelivery), then asserts MailHog received the
  email **once** and the ledger has a single successful row per
  `(subscription_id, repository, tag_name)`.
- Also assert a second scan cycle for an already-seen release produces no new email.

**Acceptance Criteria:**

**Given** the same `SendReleaseEmail` delivered twice (redelivery or re-publish)
**When** the service processes both
**Then** exactly one email is in MailHog and exactly one successful ledger row exists — FR8, NFR1,
AC4.

**Given** a re-scan of an already-advanced release
**When** the cycle runs
**Then** no duplicate publish leads to a duplicate email (consumer dedupe absorbs any re-publish).

**Dependencies:** E1, D2, D4.
**Quality gates:** service + monolith gates; the idempotency test is green and added to CI.

### Story E3: Resilience proof — Descoped (2026-06-15)

**Removed.** The live broker/service-outage resilience proof — `bin/resilience-proof.sh`, its
`Scanner Smoke and Resilience Proof` CI workflow, and the manual-test evidence under
`var/manual-test-evidence/` — was dropped. NFR3 and AC5 are descoped (see the PRD). Story numbering
is preserved: E1, E2, and E4 are unchanged.

### Story E4: Decommission monolith notification stack + drop release_notifications (FR6, AC1)

As a maintainer,
I want the in-process dispatcher/notifier/ledger/SMTP removed from the monolith and the monolith's
`release_notifications` table dropped,
So that the monolith no longer owns notification delivery (FR6) and the module boundaries are clean
(AC1).

**Scope / files (arch §12 P6, §13; PRD FR6, §8 D4):**
- Delete the in-process `NotificationDispatcher`(+iface), `NotifierService`(+iface), the
  monolith-side `NotificationLedger`(+iface), `SmtpMailer`/`MailerInterface`/`PHPMailerFactory`/
  `SmtpConfig`, `ReleaseEmailRenderer`/`RenderedEmail`, and the in-process publisher adapter — now
  fully replaced by the Rabbit path + the service.
- Remove the related DI bindings from `config/container.php`.
- Monolith migration: **drop** `release_notifications` (after cutover; the service owns it now).
- Remove monolith composer deps that only served SMTP if no longer used.

**Acceptance Criteria:**

**Given** the monolith codebase
**When** searched
**Then** no in-process `NotificationDispatcher`/`NotifierService`/monolith ledger/SMTP classes remain
and DI has no bindings for them — FR6.

**Given** the monolith migrations applied
**When** the schema is inspected
**Then** `release_notifications` no longer exists in the monolith DB; only `subscriptions` +
`repositories` remain (AR-DATA1).

**Given** deptrac + the full suite
**When** `composer lint` (PHPCS + deptrac) and phpunit/psalm run
**Then** all pass, no module imports another's concrete repo/DTO (AC1), and Behat/JSON/gRPC are
unchanged (FR11).

**Dependencies:** E1, E2, E3 (cutover proven before deletion).
**Quality gates:** monolith lint+deptrac, phpunit, psalm; Behat unchanged.

### Story E5: Update README, ADR, and LikeC4 architecture model (AC7)

As a maintainer,
I want README, an ADR, and the LikeC4 model in `docs/architecture/` updated to reflect the new
service, RabbitMQ, and the two databases,
So that the documented architecture matches the deployed system (AC7).

**Scope / files (PRD AC7; arch §15; CLAUDE.md `likec4-architecture-sync`):**
- Update `docs/architecture/` LikeC4 model: add `notification-svc`, `rabbitmq` (exchange/queue/DLQ),
  `notification-db`, MailHog; show the publish→consume→send flow and the two-database split; remove
  the in-process notification path. Use the `likec4-architecture-sync` skill.
- Write/append an ADR recording: the modular Clean-Architecture refactor, CodelyTV-aligned DDD, the
  RabbitMQ integration, the no-outbox decision, and the data-ownership split.
- Update README: new compose stack, run/migrate targets per service, the new topology.

**Acceptance Criteria:**

**Given** the LikeC4 model
**When** rendered
**Then** it shows `notification-svc`, RabbitMQ (with DLQ), both Postgres databases, MailHog, and the
publish→consume→send edges — and no longer shows the in-process notifier (AC7).

**Given** README + ADR
**When** read
**Then** they describe the two deployables, the two databases, the RabbitMQ contract
(`SendReleaseEmail/v1`), and the no-outbox rationale.

**Dependencies:** E1–E4 (documents the final state).
**Quality gates:** docs; run lint/phpunit/psalm to confirm nothing broke; LikeC4 model validates.

---

## Validation Summary

**FR coverage — every FR maps to at least one story:**
FR1 → A5, A6, B1–B5, E4 · FR2 → B5 · FR3 → C2, C5, E1 · FR4 → C1 · FR5 → E1 · FR6 → E4 · FR7 → D3, D4
· FR8 → D3, D4, E2 · FR9 → D3, D4 · FR10 → D5 · FR11 → B1, B5, E1, E4 (verified by gates every story).

**NFR coverage:** NFR1 → D3/E2 · NFR2 → D1/D2/D6/E4 · NFR3 → E3 · NFR4 → C2/D5/B5 · NFR5 → quality-gate
note on every story · NFR6 → C3/D6.

**Dependency ordering (Strangler, no forward deps within an epic):**
A (no deps) → B (needs A) → C (needs B) → D (needs C) → E (needs C + D). Within each epic, stories
depend only on earlier stories (or earlier epics), per the explicit Dependencies note on each story.

**Architecture compliance:** No "create all tables upfront" — `release_notifications` is created by
the service exactly when needed (D2) and dropped from the monolith only at decommission (E4).
deptrac enforces Clean-Architecture + bounded-context boundaries from A6 onward. The CLAUDE.md
convention reversal (entities → aggregates) is explicit work in A7. Wire contracts (REST JSON, gRPC,
Behat) stay frozen throughout and are re-verified at B1/B5/E1/E4.

**File-churn note:** Epics are sliced by **bounded context / migration phase**, not by file type.
The phased Strangler sequence deliberately revisits `config/container.php` and `ScanReleasesCommandHandler`
across B/C/E — this is intentional incremental wiring (introduce port → build adapter → cut over),
not avoidable churn, and matches the architecture's P0–P6 plan.
