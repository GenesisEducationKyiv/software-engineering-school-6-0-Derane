---
artifact: prd
project: github-release-notifier
title: 'REST → gRPC migration — one synchronous inter-service call (welcome-email send/confirm)'
author: valerii
date: '2026-06-23'
status: draft
related:
  [
    'specs/rest-to-grpc-migration/onboarding.md',
    'specs/hw9-saga-subscription-confirmation/prd.md',
    'specs/project-context.md',
    'specs/rest-to-grpc-migration/architecture.md (to be authored next)',
  ]
---

# PRD — REST → gRPC Migration: Welcome-Email Send/Confirm (one sync inter-service call)

## 1. Context & Problem

`github-release-notifier` is a two-service system: a Slim 4 + PHP-DI **monolith**
(Postgres A, db `release_notifier`) that owns subscriptions, tracked repositories,
release scanning and the HW9 enrollment saga, plus an extracted **notification
service** (`apps/notification`, PSR-4 `App\` → `src/`, sending bounded context
`App\Sending`, Postgres B, db `release_notifications`) that consumes RabbitMQ and
sends email. The assignment
(HW #7/#9 lineage) asks us to **replace one synchronous HTTP REST call between two
microservices with a gRPC unary RPC** — a `.proto` contract driven by **buf**
tooling, the REST path kept working alongside behind a feature flag, with correct
gRPC status-code error mapping.

**Headline reality (load-bearing — from the onboarding investigation).** There is
**no synchronous service-to-service REST call in this system today**. All
monolith ↔ notification traffic is asynchronous RabbitMQ (`SendWelcomeEmail`,
`WelcomeEmailOutcome`, `SendReleaseEmail`); the only inbound HTTP on the
notification service is `GET /health` + `GET /metrics`
(`apps/notification/http/index.php:20-21`), infrastructure probes the monolith
never calls; there is **no outbound HTTP/Guzzle client in `apps/notification` and
no gRPC client anywhere in the repo** (onboarding §2). So this is not a literal
"swap call X for gRPC." It is: pick the most request/response-shaped inter-service
interaction, **stand up a thin synchronous REST baseline on the server side**, and
**add a gRPC unary alternative behind a flag**.

**The chosen interaction.** The HW9 **welcome-email send/confirm** step is the only
genuine cross-service request/response in the system (onboarding §3, §7–§8). Today
it is a request/reply collapsed into **two async RabbitMQ legs** correlated by
`correlation_id = sagaId`: the monolith's saga relay publishes `SendWelcomeEmail`
fire-and-forget through the **`WelcomeEmailRelay` port**
(`src/Saga/Enrollment/Domain/WelcomeEmailRelay.php` — single method
`publish(SendWelcomeEmail): void`), the notification `SendWelcomeEmailHandler`
sends and publishes a `WelcomeEmailOutcome` reply, and the monolith's reply
consumer advances the saga, with a timeout sweeper as the never-hangs backstop.
Logically this **is** the canonical "API/saga side asks the mail service to send
the welcome email and waits for sent/failed" — the textbook `VerifyEmail` RPC
shape — but today both legs are async, the caller does not block, and **neither
side returns the outcome** (`publish()` and `handle()` are both `void`).

This PRD migrates **exactly that one interaction**:

- **Service A (caller)** = the monolith's HW9 saga relay
  (`SagaWorker` → `WelcomeEmailRelay` port, `src/Saga/Enrollment/...`).
- **Service B (server)** = `apps/notification` (`App\Sending`), reusing the existing
  `App\Sending\Application\SendWelcomeEmailHandler` business logic **unchanged**.

Because no synchronous REST call exists to swap one-for-one, the "keep REST as
baseline" requirement is satisfied by **introducing** a thin synchronous REST
endpoint on Service B as the baseline, then adding a **gRPC unary twin**. Transport
is selected by an env/feature flag `WELCOME_EMAIL_TRANSPORT = rabbit | rest | grpc`.

### 1.1 The semantic shift (call out honestly)

This is **not a transport-only swap**. Converting the welcome path to a
synchronous caller-waits RPC removes the broker buffer and the timeout sweeper's
"never hangs" guarantee on the opt-in paths: the caller now blocks on the send and
needs its own deadline/retry policy (onboarding §3). To protect HW9, the **async
RabbitMQ relay → reply-queue → timeout-sweeper path stays the DEFAULT**
(`rabbit`); the synchronous `rest` and `grpc` transports are **opt-in**. On the
opt-in paths the caller deliberately waits for `sent | failed` instead of relaying
fire-and-forget — a real semantic change, flagged here as Decision DC1 and Risk R5.

### 1.2 Default-vs-opt-in transport model

| Flag value | Plane | Caller semantics | Server surface | Status |
| --- | --- | --- | --- | --- |
| `rabbit` *(DEFAULT)* | async AMQP | fire-and-forget relay → reply queue → sweeper | existing `SendWelcomeEmailConsumer` | HW9, **100% intact** |
| `rest` | sync HTTP/1.1 + JSON | caller waits for `sent`/`failed` | **new** REST endpoint on Service B (baseline) | new, opt-in |
| `grpc` | sync HTTP/2 + protobuf | caller waits for `sent`/`failed` | **new** gRPC unary server on Service B | new, opt-in |

`rabbit` remaining the default is what keeps the HW9 saga, its tests, and its wire
contracts unchanged (FR12 / NFR3 / R4).

## 2. Goals & Non-Goals

### Goals

- **G1.** Define a **versioned `.proto` contract** with ≥1 **unary** RPC for the
  welcome-email send (`SendWelcomeEmail(...) returns (...)` carrying a `sent|failed`
  outcome), with clear self-documenting names and comments.
- **G2.** Adopt **buf** (greenfield in this repo): `buf.yaml` + `buf.gen.yaml`,
  `buf lint` clean, `buf generate` emitting into a **dedicated gen dir** separate
  from the committed `generated/`.
- **G3.** Stand up a **gRPC unary server** on Service B (notification) layered over
  the existing `SendWelcomeEmailHandler` — **no business-logic change**.
- **G4.** Add a **gRPC client** on Service A (the saga relay) that actually calls
  Service B when the flag selects `grpc`, plus a **synchronous REST baseline**
  endpoint+client kept working alongside (not deleted, not broken).
- **G5.** Map errors to **correct gRPC status codes** by reusing the
  `ExceptionStatusMap` pattern (the single source of truth for HTTP↔status mapping,
  onboarding §5), per the HTTP→gRPC table in §6.
- **G6.** Keep **both transports operating simultaneously** behind the flag, with
  **`rabbit` as the default** so HW9 (saga + tests + wire contracts) stays intact
  and the existing `proto/release_notifier.proto` is untouched.
- **G7.** Prove the gRPC path with **≥1 automated test** (happy path + ≥1 error
  case asserting the correct gRPC status code), following the existing in-process
  gRPC test harness pattern (`tests/Grpc/`, `tests/Integration/Grpc/`).
- **G8 (bonus ★).** Benchmark REST vs gRPC with a **single k6 harness** (HTTP for
  REST, `k6/net/grpc` for gRPC) and record the req/s and p50/p95/p99 comparison in
  the README with a short explanation.
- **G9.** Keep all quality gates green on both sides (lint + deptrac, phpunit Unit,
  psalm 100%); the deptrac baseline (currently `{}`) must **not** grow.

### Non-Goals

- **N1.** **No migration of any other interaction.** Exactly one inter-service
  interaction (welcome-email send/confirm) is migrated. `SendReleaseEmail` (fan-out,
  no reply path, onboarding §7 Candidate 3) is explicitly out of scope.
- **N2.** **No change to the existing `proto/release_notifier.proto`** or its wire
  format. The new RPC lives in a **new proto file/package**; the monolith's
  Subscription-CRUD gRPC API (`ReleaseNotifierService`) is untouched
  (wire-format protection).
- **N3.** **No change to Service B business logic.** Only a new transport layer
  (REST endpoint + gRPC server) over the unchanged `SendWelcomeEmailHandler`.
- **N4.** **No removal of any path.** REST is kept as a live baseline; the async
  RabbitMQ relay/reply/sweeper path is kept as the default fallback. Nothing is
  deleted.
- **N5.** **Async `rabbit` is not changed.** The HW9 saga's default behavior, its
  contracts (`SendWelcomeEmail/v1`, `WelcomeEmailOutcome/v1`), its reply queue and
  its timeout sweeper are unchanged — they are the fallback.
- **N6.** No TLS/mTLS, no service mesh, no API gateway, no streaming RPCs, no new
  third database, no Kubernetes. Plaintext gRPC over the compose network, mirroring
  the existing `bin/grpc.php` / `.rr.grpc.yaml` setup (onboarding §4).
- **N7.** No regeneration of the committed `generated/` tree from buf; buf output
  goes to its own dir (G2). `make proto` (raw protoc + Spiral plugin) stays for the
  legacy proto.

## 3. Scope

### 3.1 Contract & tooling (new)
- A **new `.proto` file** with a **versioned package** (e.g.
  `notification.welcome.v1`) and a `php_namespace` option, declaring one **unary**
  RPC `SendWelcomeEmail(SendWelcomeEmailRequest) returns (SendWelcomeEmailResponse)`,
  the response carrying a `sent|failed` outcome (+ optional error detail). Names are
  self-documenting; every message/field/RPC is commented (FR1).
- **buf** wired greenfield: `buf.yaml` (v2; modules → the proto dir; `lint:
  STANDARD`; `breaking: FILE`) and `buf.gen.yaml`; `buf lint` and `buf generate`
  run clean (FR2). Generated PHP lands in a **dedicated gen dir** (e.g.
  `gen/` or `generated-buf/`, exact name → architecture.md), **separate** from the
  existing committed `generated/`, autoloaded under its own PSR-4 prefix.

### 3.2 Service B — notification (new transport surface only)
- A **synchronous REST endpoint** (baseline), `App\Sending\Infrastructure\Http\WelcomeEmailController`
  (sibling of `HealthController`/`MetricsController`, registered in
  `apps/notification/http/index.php`), that accepts the welcome-email send request,
  invokes the **unchanged** `App\Sending\Application\SendWelcomeEmailHandler`, and
  returns the `sent|failed` outcome as JSON with the correct HTTP status (FR3, FR5).
- A **gRPC unary server** `App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService`
  implementing the new service interface, invoking the same unchanged handler,
  returning the same outcome (FR4). This is **net-new** for `apps/notification` (it has
  zero gRPC today): new gRPC deps + RoadRunner entrypoint + DI wiring + a second
  container process alongside `bin/consumer.php` (onboarding §4, Open decision 4).
- A notification-side error map `App\Sending\Infrastructure\Error\ExceptionStatusMap`
  exposing **`toGrpcStatus()`** and **`toHttpStatus()`**
  (`apps/notification/src/Sending/Infrastructure/Error/ExceptionStatusMap.php`) so both
  transports map the same exception to the same outcome (FR6; onboarding §5 notes this
  map currently has no gRPC arm).

### 3.3 Service A — monolith saga relay (new adapters behind the existing port)
- Two **new `WelcomeEmailRelay`-shaped adapters** — a **REST client** adapter and a
  **gRPC client** adapter — selected by `WELCOME_EMAIL_TRANSPORT` in
  `config/container.php`, alongside the existing `RabbitWelcomeEmailRelay`
  (the default). The `grpc`/`rest` adapters call Service B and obtain the
  `sent|failed` outcome directly (FR7).
- Because the current port returns `void` (fire-and-forget) and the sync transports
  must surface the outcome, the **caller-side seam** is adapted so the outcome flows
  back on the synchronous paths without altering the async path's contract — the
  exact seam (extend the port vs. a sibling outcome-returning port) is an
  architecture.md decision; this PRD pins that the async `void` semantics and the
  HW9 saga state machine are **unchanged on `rabbit`** (FR7, FR12, DC1).

### 3.4 Configuration & runtime
- `WELCOME_EMAIL_TRANSPORT` env flag (`rabbit | rest | grpc`, **default `rabbit`**),
  plus the Service B endpoint/host:port settings for `rest`/`grpc` (FR8).
- A new gRPC server process for Service B in `docker-compose.yml` (or folded into
  the notification container's process supervisor), plaintext, mirroring the
  monolith's RoadRunner gRPC setup. Booting the docker stack is confirmed with the
  user separately (NFR6).

### 3.5 Tests, benchmark & gates
- ≥1 automated test on the gRPC path: **happy path** + **≥1 error case** asserting
  the correct gRPC status code (FR9), following `tests/Grpc/` /
  `tests/Integration/Grpc/` (in-process handler invocation with a mocked context).
- ★ A **k6** harness driving REST (HTTP) and gRPC (`k6/net/grpc`) and a README
  comparison table (req/s; p50/p95/p99) with a short rationale (FR10).
- New quality gate(s): buf lint + the new tests wired into a `make` target and a CI
  workflow row added to `.github/required-pr-checks.txt` (NFR5; onboarding §6).

## 4. Target Topology (high level)

```
  Service A (MONOLITH, Postgres A)                    Service B (apps/notification, Postgres B)
  ───────────────────────────────                    ──────────────────────────────────────────
  SagaWorker → RelayPendingWelcomeEmails
        │  WelcomeEmailRelay port (seam)
        │
        ├─ rabbit (DEFAULT, async) ──► RabbitMQ ──► SendWelcomeEmailConsumer ─┐
        │   fire-and-forget; reply queue;          (UNCHANGED HW9 path)        │
        │   timeout sweeper backstop                                           ▼
        │                                                          SendWelcomeEmailHandler
        ├─ rest (opt-in, sync) ──HTTP/1.1+JSON──► [NEW] REST endpoint ─────────┤  (UNCHANGED
        │   caller waits sent|failed                                           │   business logic;
        │                                                                      │   claim/render/
        └─ grpc (opt-in, sync) ──HTTP/2+protobuf─► [NEW] gRPC unary server ────┘   send/markSent)
            caller waits sent|failed                returns SendWelcomeEmailResponse{outcome}

  Flag: WELCOME_EMAIL_TRANSPORT = rabbit | rest | grpc   (default rabbit)
  Errors: ExceptionStatusMap → HTTP status (REST) and gRPC status code (gRPC), one mapping.
```

The new `.proto` + buf-generated stubs (own gen dir) define the gRPC contract.
The existing `proto/release_notifier.proto` and committed `generated/` are
untouched (N2/N7).

## 5. Functional Requirements

### Contract & tooling

- **FR1. Versioned, documented proto contract with a unary RPC.** A **new** `.proto`
  file at **`proto/notification/welcome/v1/welcome.proto`** declares the **versioned
  package** `notification.welcome.v1` and `option php_namespace = "Notification\\Welcome\\V1";`,
  with **one unary** RPC for the welcome-email send —
  `SendWelcomeEmail(SendWelcomeEmailRequest) returns (SendWelcomeEmailResponse)` —
  whose response carries a **`sent | failed` outcome** (and an optional structured
  error detail). The outcome enum is buf-STANDARD-clean with the `OUTCOME_` value
  prefix: `enum Outcome { OUTCOME_UNSPECIFIED = 0; OUTCOME_SENT = 1; OUTCOME_FAILED = 2; }`
  (`subscription_id` is `int64`, RD10a). The file path satisfies
  `PACKAGE_DIRECTORY_MATCH` against the `buf.yaml` `modules: - path: proto` root. RPC,
  messages and fields use clear self-documenting names and carry **doc comments**. The
  existing `proto/release_notifier.proto` is **not** modified (N2). *Acceptance:* see AC1.

- **FR2. buf wired and clean (greenfield).** `buf.yaml` (v2 — modules pointing at
  the proto dir; `lint: STANDARD`; `breaking: FILE`) and `buf.gen.yaml` exist;
  `buf lint` reports **zero** findings under STANDARD and runs **fully offline** (the
  hard requirement). `buf generate` produces PHP stubs into a **dedicated gen dir**
  (`gen/`) that is **separate** from the committed `generated/` tree and autoloaded
  under its own PSR-4 prefix. `buf.gen.yaml` wires three plugins into `gen/`:
  (1) messages = **remote `buf.build/protocolbuffers/php`**; (2) server interface =
  the **local vendored Spiral** plugin
  `tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc`;
  (3) client stub = **remote `buf.build/grpc/php`**. Because two plugins are remote,
  **`buf generate` requires network at codegen time** (an acceptable codegen-time
  dependency — runtime stays offline). An **offline fallback** is documented and
  supported: a pinned `grpc_php_plugin` binary plus a `protoc-gen-php` shim that wraps
  `protoc --php_out`. `buf` and all plugin versions are **pinned**. *Acceptance:* see AC2.

### Service B — notification (transport only, no logic change)

- **FR3. Synchronous REST baseline endpoint.** Service B exposes a **new** synchronous
  HTTP endpoint that accepts a welcome-email send request (carrying at least
  `sagaId`, `subscriptionId`, recipient `email`, `repository`), invokes the
  **unchanged** `SendWelcomeEmailHandler`, and returns the `sent | failed` outcome
  as JSON with the appropriate HTTP status. This endpoint is the **baseline** the
  gRPC twin is compared against and is **kept working** (never deleted, never
  broken) once gRPC lands. *Acceptance:* see AC4, AC5.

- **FR4. gRPC unary server over the same handler.** Service B exposes a **gRPC unary
  server** implementing the FR1 service, which invokes the **same unchanged**
  `SendWelcomeEmailHandler` and returns `SendWelcomeEmailResponse{outcome}`. This
  introduces a net-new gRPC surface in `apps/notification` (gRPC deps + a RoadRunner
  entrypoint + DI + a second container process), governed by the notification
  service's own deptrac. *Acceptance:* see AC3, AC4.

- **FR5. No business-logic change in Service B.** The REST and gRPC surfaces are
  **transport adapters only** over the existing `SendWelcomeEmailHandler`; the claim
  / render / send / mark-sent logic, its ledger/idempotency, and its outcome
  computation are unchanged. *Acceptance:* `App\Sending\Application\SendWelcomeEmailHandler`
  and the `App\Sending\Application` layer have no behavioral diff attributable to
  this work; the existing notification unit/integration tests stay green (AC7).

- **FR6. gRPC status-code mapping reuses `ExceptionStatusMap`.** Errors raised by the
  handler are mapped to gRPC status codes via a notification-side `toGrpcStatus()`
  built on the existing `ExceptionStatusMap` (single source of truth, mirroring the
  monolith's `ReleaseNotifierService::mapException()` pattern), per this
  **HTTP → gRPC** table — used for **both** the REST status and the gRPC status so a
  given exception maps consistently across transports:

  | HTTP | gRPC status | code | Trigger (this system) |
  | --- | --- | --- | --- |
  | 200 | `OK` | 0 | successful send (`outcome: sent`) |
  | 400 | `INVALID_ARGUMENT` | 3 | `ValidationException` / `InvalidArgumentException` (bad request shape / invalid VO) |
  | 401 | `UNAUTHENTICATED` | 16 | missing/invalid caller credentials *(reserved; no auth in v1)* |
  | 403 | `PERMISSION_DENIED` | 7 | caller not permitted *(reserved; no authz in v1)* |
  | 404 | `NOT_FOUND` | 5 | unknown saga/subscription target |
  | 409 | `ABORTED` | 10 | **`WelcomeInFlightException`** — benign send-in-flight contention (caller leaves saga pending, retries next tick) |
  | 409 | `ALREADY_EXISTS` | 6 | duplicate/idempotent-conflict send *(reserved; no handler arm in v1)* |
  | 429 | `RESOURCE_EXHAUSTED` | 8 | `RateLimitException` / throttling |
  | 500 | `INTERNAL` | 13 | unexpected/default error (rethrown send failure; transient) |
  | 503 | `UNAVAILABLE` | 14 | Service B (or its deps) unavailable |
  | 504 | `DEADLINE_EXCEEDED` | 4 | send did not complete within the deadline |

  The mapping lives in the **transport adapter** (the gRPC server class + REST
  controller wrapping `SendWelcomeEmailHandler::handle()`, which is `void` and throws)
  — **not** the handler. Its **catch order is load-bearing** because
  `WelcomeAlreadyFailedException` and `WelcomeInFlightException` both extend
  `\RuntimeException`: (1) normal return → `OUTCOME_SENT`; (2) `WelcomeAlreadyFailedException`
  → `OUTCOME_FAILED` as a normal `OK` response (drives saga compensate); (3)
  `WelcomeInFlightException` → **ABORTED / 409** (benign contention — caller leaves the
  saga pending and retries next tick, does **not** drive the saga); (4)
  `ValidationException` / `InvalidArgumentException` → **INVALID_ARGUMENT / 400**;
  (5) any other `\Throwable` → **INTERNAL / 500** (transient; caller leaves saga
  pending for the next relay tick). The `WelcomeInFlightException` arm must be added to
  the notification `ExceptionStatusMap::toGrpcStatus()` / `toHttpStatus()` so it is a
  **reachable** mapping, not a reserved one.

  *Acceptance:* see AC3, AC6. (Rows 401/403 and the `ALREADY_EXISTS` 409 variant are
  mapped for completeness and to satisfy the assignment's status map; the v1 system has
  no auth and `ALREADY_EXISTS` has no handler arm. The `ABORTED` 409 arm **is**
  reachable via `WelcomeInFlightException`.)

### Service A — monolith saga relay (caller)

- **FR7. Flag-selected client adapters that actually call Service B.** Two new
  `WelcomeEmailRelay`-shaped adapters — a **REST client** and a **gRPC client** —
  are wired in `config/container.php` and selected by `WELCOME_EMAIL_TRANSPORT`.
  When the flag is `grpc`, the saga relay **actually invokes the Service B gRPC
  server** (not REST, not Rabbit) and obtains the `sent | failed` outcome; when
  `rest`, it calls the REST endpoint. The existing `RabbitWelcomeEmailRelay` remains
  and is selected by `rabbit`. On the sync paths the caller **waits for the
  outcome** (the deliberate semantic shift, DC1); the async path's `void`
  fire-and-forget contract and the HW9 saga state machine are **unchanged**.
  *Acceptance:* see AC3, AC4, AC8.

- **FR8. Transport selection by env flag, default `rabbit`.** `WELCOME_EMAIL_TRANSPORT`
  (`rabbit | rest | grpc`) selects the relay adapter; an **absent or unrecognized**
  value defaults to **`rabbit`**. Both sync transports and the async transport are
  wired simultaneously; switching the flag switches the path with no code change and
  **no REST deletion**. *Acceptance:* see AC4, AC8.

### Cross-cutting

- **FR9. Automated test on the gRPC path.** At least one automated test exercises the
  gRPC path with (a) a **happy path** asserting `outcome: sent` and `OK`, and (b) at
  **least one error case** asserting the **correct gRPC status code** (e.g.
  `INVALID_ARGUMENT` for a bad request, or `INTERNAL` for a handler failure), via
  the established in-process gRPC test pattern (`tests/Grpc/ReleaseNotifierServiceTest.php`
  / `tests/Integration/Grpc/...`: mocked `ContextInterface`, real status map). REST
  baseline behavior is likewise covered by ≥1 test so the comparison is honest.
  *Acceptance:* see AC6.

- **FR10. ★ k6 REST-vs-gRPC benchmark + README comparison.** A single **k6** harness
  drives the REST baseline (HTTP) and the gRPC path (`k6/net/grpc`); the README
  records **req/s** and latency **p50/p95/p99** for both and the **difference**, with
  a short explanation of *why* gRPC differs (HTTP/2 multiplexing, binary protobuf vs
  text JSON, no per-request schema reparse). The benchmark tool is **k6** (not
  autocannon/ghz). The harness is committed unconditionally (AC9a); the recorded
  numbers are gated on a booted stack (AC9b). *Acceptance:* see AC9a, AC9b.

- **FR11. Both transports operate simultaneously; nothing removed.** REST
  (HTTP/1.1 + JSON) and gRPC (HTTP/2 + protobuf) both run; the flag chooses which
  the caller uses; the REST endpoint is **not** deleted when gRPC lands; the async
  `rabbit` path remains the default fallback. *Acceptance:* see AC4, AC8.

- **FR12. Wire-format & HW9 protection.** The existing `proto/release_notifier.proto`
  and its generated `generated/` tree are **untouched**; the HW9 `SendWelcomeEmail/v1`
  and `WelcomeEmailOutcome/v1` RabbitMQ contracts, the saga state machine, the reply
  queue and the timeout sweeper are **unchanged**; with the flag at its default
  (`rabbit`) the HW9 saga and **all** existing HW7/HW9 tests and Behat scenarios
  stay green. *Acceptance:* see AC7, AC8.

## 6. Non-Functional Requirements

- **NFR1. Default-safe, opt-in migration.** `WELCOME_EMAIL_TRANSPORT` defaults to
  **`rabbit`**; the synchronous `rest`/`grpc` transports are opt-in. Therefore the
  HW9 saga + tests + wire contracts remain 100% intact by default, and the
  REST/gRPC paths cannot regress production behavior unless explicitly enabled
  (FR8, FR12).

- **NFR2. Caller-waits semantics on the sync paths (documented trade-off).** On
  `rest`/`grpc` the caller **blocks** on the send and obtains `sent | failed`
  directly. This **removes** the async broker buffer and the timeout sweeper's
  never-hangs guarantee **on those paths**; the sync client therefore enforces its
  **own deadline** (`DEADLINE_EXCEEDED` / `UNAVAILABLE` mapping, FR6) and a bounded
  retry policy (values → architecture.md). This is a deliberate semantic change
  (DC1, R5), not a transport-only swap. The `rabbit` default retains the sweeper.

- **NFR3. Transport interchangeability over a single seam.** All three transports
  implement the same `WelcomeEmailRelay`-shaped seam and are interchangeable by flag
  with no change to the saga orchestration; the server side runs the **same**
  `SendWelcomeEmailHandler` for all three (FR5, FR7).

- **NFR4. Generated code isolation.** buf output lives in a **dedicated gen dir**
  separate from the committed `generated/`, with its own PSR-4 prefix; the legacy
  `make proto` flow and the committed `generated/` are unaffected (FR2, N7).

- **NFR5. Quality gates & CI.** Monolith: `composer lint` (PHPCS PSR-12 +
  **deptrac**), `./vendor/bin/phpunit --no-coverage --testsuite Unit`, `composer psalm`
  (errorLevel 1, 100% types). Notification service: its own lint/deptrac/psalm/phpunit
  gates. The **deptrac baseline must not grow** (stays `{}`): the new client adapters
  and gRPC server are wired with explicit, minimal port edges, not baseline
  exceptions. **Dependencies for the gRPC client (Service A):** add `grpc/grpc: ^1.x`
  to the **root** `composer.json` `require` (provides `\Grpc\BaseStub` /
  `ChannelCredentials` / `UnaryCall` for runtime **and** psalm symbol resolution), and
  add the PECL **`ext-grpc`** (`install-php-extensions grpc`) to the **root** Dockerfile
  — **client image only**; Service B keeps RoadRunner and needs **no** `ext-grpc`.
  **psalm:** in the root `psalm.xml` add `<directory name="gen"/>` to `projectFiles`
  **with** an `<ignoreFiles><directory name="gen"/></ignoreFiles>` entry (resolve the
  generated/`\Grpc` symbols, do **not** analyze generated code), or targeted
  `@psalm-suppress` on generated-class usages. A new gate (buf lint and/or the new gRPC
  tests) is added as a `make` target consumed by a CI workflow and registered in
  `.github/required-pr-checks.txt` (onboarding §6). Generated stubs in the new gen dir
  are excluded from hand-written gates exactly as `generated/` is excluded from phpcs.

- **NFR6. Local dev / runtime.** `docker compose up` brings up both services with the
  new gRPC server process for Service B (plaintext, mirroring `bin/grpc.php` /
  `.rr.grpc.yaml`); flipping `WELCOME_EMAIL_TRANSPORT` demonstrates the welcome path
  over `rabbit`, `rest`, and `grpc`. **Booting the stack and running the k6
  benchmark is confirmed with the user separately** before execution. The PHP gRPC
  **client** requires the `grpc` runtime (PECL `ext-grpc` and/or RoadRunner); the
  exact client-runtime strategy is an architecture.md decision (onboarding §9.3).

- **NFR7. Independent deployability preserved.** No shared database; the sync paths
  add one new synchronous coupling (A→B over REST/gRPC) **only when enabled**; each
  service still builds, migrates and runs against its own Postgres. The default
  (`rabbit`) preserves the fully-decoupled HW9 topology.

- **NFR8. Observability (deferred / non-gating).** The sync paths **MAY** emit
  per-transport request/outcome counters and latency so the benchmark and operability
  story are derivable (requests by transport, `sent`/`failed`, deadline/unavailable
  errors), reusing the existing metrics plane. This is **deferred and non-gating** — no
  AC blocks on it and exact counter names are left to architecture.md / a future story.
  (The k6 benchmark in AC9a / AC9b is the authoritative per-transport
  latency/throughput evidence; in-process counters are an optional supplement.)

## 7. Contract Summary (full schema in architecture.md)

One **new** versioned proto contract (separate file, separate package), plus the
new REST endpoint shape mirroring it:

- **`SendWelcomeEmail` (unary RPC, `notification.welcome.v1`,
  `proto/notification/welcome/v1/welcome.proto`)** — Service A → Service B.
  - `SendWelcomeEmailRequest { string saga_id; int64 subscription_id; string email;
    string repository; }` (field set mirrors the HW9 `SendWelcomeEmail/v1` payload so
    the handler input is unchanged; `subscription_id` is **`int64`** to avoid
    32-bit truncation, RD10a).
  - `SendWelcomeEmailResponse { Outcome outcome; string error_detail; }` where
    `enum Outcome { OUTCOME_UNSPECIFIED = 0; OUTCOME_SENT = 1; OUTCOME_FAILED = 2; }`
    (the `OUTCOME_` prefix keeps buf STANDARD clean, RD1). Generated under
    `php_namespace = "Notification\\Welcome\\V1"`, i.e. `Notification\Welcome\V1\Outcome`.
  - This **wire enum** is distinct from the two hand-written PHP enums it maps to at the
    boundaries: the server-side `App\Sending\Domain\WelcomeOutcome` (which the handler
    publishes) and the caller-side
    `App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome` (which
    `HandleWelcomeEmailOutcomeCommand` carries). The transport adapter maps
    disposition → wire on the server, and wire → `HandleOutcome\WelcomeOutcome` on the
    caller.
  - Errors surface as gRPC **status codes** (FR6 table), not in-band, except the
    business `sent|failed` outcome which is a normal `OK` response field.
- **REST baseline** — `POST` on Service B accepting the same JSON field set and
  returning `{ outcome, error? }` with the matching HTTP status (FR3).

The existing `proto/release_notifier.proto`, the HW9 `SendWelcomeEmail/v1` and
`WelcomeEmailOutcome/v1` RabbitMQ messages, and the committed `generated/` tree are
**untouched** (FR12).

## 8. Decisions (locked) & Constraints

- **DC1. Deliberate semantic shift on the opt-in paths.** The migration changes the
  welcome path from async fire-and-forget to **synchronous caller-waits** on
  `rest`/`grpc`. This is intended and accepted (not a bug), and is contained by
  keeping `rabbit` the default (NFR1, NFR2, R5).
- **DC2. Introduced REST baseline.** Since no synchronous inter-service REST call
  exists, a thin synchronous REST endpoint on Service B **is** the baseline the
  "keep REST working alongside" requirement refers to (FR3).
- **DC3. buf adopted greenfield, separate gen dir.** `buf.yaml`/`buf.gen.yaml` are
  net-new; buf output is isolated from the committed `generated/` (FR2, NFR4).
- **DC4. New proto, not an edit of the legacy one.** The welcome RPC lives in a new
  file/package; `proto/release_notifier.proto` is frozen (N2, FR12).
- **DC5. Benchmark tool = k6.** A single k6 harness covers REST (HTTP) and gRPC
  (`k6/net/grpc`); not autocannon/ghz (FR10).
- **DC6. Server logic frozen.** `SendWelcomeEmailHandler` is reused **unchanged**;
  only transport adapters are added (FR5, N3).
- **DC7. Client runtime = `ext-grpc` + remote buf plugins (resolved).** The Service A
  gRPC client uses PECL **`ext-grpc`** (client image only) over the buf-generated
  client stub from the remote `buf.build/grpc/php` plugin; `grpc/grpc` is added to the
  root `composer.json`. Service B stays on RoadRunner with the local vendored Spiral
  server plugin and needs no `ext-grpc` (RD2, RD7). Closes the former
  client-stub-strategy open question.
- **DC8. Deadline & retry already pinned (resolved at PRD level).** The sync paths
  enforce a bounded deadline + retry/backoff that replaces the broker buffer + sweeper;
  concrete values live in architecture.md. On a definitive `OK(SENT|FAILED)` the sync
  relay drives the saga in-thread; on `ABORTED`/`INVALID_ARGUMENT`/`INTERNAL`/
  `UNAVAILABLE`/`DEADLINE_EXCEEDED` it leaves the saga **pending** for the next tick,
  mirroring the async bounded-retry/sweeper (RD6). Closes the former deadline/retry
  open question at the PRD level.
- **DC9. Service B gRPC = a second RoadRunner process (resolved).** The new gRPC
  server runs as a **second RoadRunner process** inside the notification container
  (alongside `bin/consumer.php`), not a separate compose service; the notification
  build context becomes the **repo root** (`context: .`, `dockerfile:
  apps/notification/Dockerfile`) so the image can `COPY proto/` + `gen/` (RD3). Closes
  the former separate-service-vs-second-process open question.
- **DC10. Broker still required on the sync paths (resolved).** `SagaWorker` needs **no
  code change**: the migration swaps only the DI relay binding. The AMQP reply
  consumer + timeout sweeper keep running on **all** transports, so `saga-worker` keeps
  `depends_on: rabbitmq`. On sync paths no `SendWelcomeEmail` is published; the
  handler's still-published async outcome reply is consumed by the running reply
  consumer as a **no-op** (idempotent for both SENT and FAILED, RD4, RD5). Confirms the
  broker remains a runtime dependency even on `rest`/`grpc`.

## 9. Out of Scope / Future Phases

- Migrating any other interaction (`SendReleaseEmail`, Subscription CRUD) to gRPC
  (N1) — the assignment is one interaction.
- Editing the existing `proto/release_notifier.proto` or regenerating the committed
  `generated/` via buf (N2, N7).
- Replacing the async `rabbit` default with a sync transport as the production
  default — `rabbit` stays default; flipping the production default is future work
  once the sync paths' deadline/retry policy is proven (NFR2).
- Auth/authz (the `UNAUTHENTICATED`/`PERMISSION_DENIED` rows are reserved mappings),
  TLS/mTLS, streaming RPCs, service mesh, API gateway, Kubernetes, a third database
  (N6).
- Removing the async relay/reply/sweeper machinery — kept as the default fallback
  (N4, N5).
- Generalized multi-RPC inter-service gRPC layer / shared gRPC client kernel — this
  PRD delivers one unary RPC and one client adapter, not a framework.

## 10. Risks & Mitigations

- **R1. buf greenfield friction** (no buf in repo; codegen today is raw protoc +
  Spiral plugin via `make proto`) → adopt buf only for the **new** proto into a
  **separate** gen dir; leave `make proto` and `generated/` alone (DC3, DC4, NFR4).
- **R2. Net-new gRPC surface in `apps/notification`** (zero gRPC there today:
  deps + RoadRunner entrypoint + second process + its own `toGrpcStatus`) →
  mirror the monolith's `bin/grpc.php` / `.rr.grpc.yaml` pattern and reuse the
  notification `ExceptionStatusMap`; gate the new code under the notification
  deptrac without growing the baseline (FR4, FR6, NFR5; onboarding §4–§5).
- **R3. PHP gRPC client runtime** (PHP gRPC client needs the `grpc` runtime;
  Spiral's `protoc-gen-php-grpc` emits a **server** interface, **no client stub**,
  onboarding §4) → client-stub strategy (official grpc PHP plugin vs hand-written
  adapter over the generated messages) is an architecture.md decision; the PRD pins
  that a working client must actually call Service B (FR7).
- **R4. Breaking HW9 / wire contracts** → `rabbit` stays default; new proto in a new
  package; existing proto + `generated/` + HW9 messages + saga + sweeper untouched;
  existing tests/Behat stay green (FR12, NFR1, AC7, AC8).
- **R5. Lost never-hangs guarantee on sync paths** (no broker buffer / no sweeper
  when caller waits) → sync client enforces its own deadline + bounded retry
  (`DEADLINE_EXCEEDED`/`UNAVAILABLE`, FR6); `rabbit` default retains the sweeper;
  documented as the deliberate DC1 trade-off (NFR2).
- **R6. Wrong gRPC status mapping** → single `ExceptionStatusMap`-derived
  `toGrpcStatus()` used by both transports, with the FR6 table as the spec, asserted
  by the error-case test (FR6, FR9, AC3, AC6).
- **R7. deptrac baseline growth** → new adapters/server wired with explicit minimal
  port edges; baseline stays `{}` (NFR5, AC10).
- **R8. New CI gate not enforced** → add buf-lint / gRPC-test `make` target +
  workflow and register it in `.github/required-pr-checks.txt` (NFR5, onboarding §6).

## 11. Acceptance Criteria (definition of done)

| # | Acceptance Criterion | Maps to |
| --- | --- | --- |
| **AC1** | A **new** `.proto` file exists with a **versioned package** and a **unary** `SendWelcomeEmail(SendWelcomeEmailRequest) returns (SendWelcomeEmailResponse)` RPC whose response carries a `sent|failed` outcome; RPC/messages/fields have clear self-documenting names and doc comments. The existing `proto/release_notifier.proto` is unchanged. | FR1, N2 |
| **AC2** | `buf.yaml` (v2, lint STANDARD, breaking FILE) **and** `buf.gen.yaml` are present; `buf lint` returns **zero findings under STANDARD** and runs **fully offline**, and `buf generate` (network at codegen time for the two remote plugins; offline fallback documented) emits PHP stubs into a **dedicated gen dir separate from `generated/`** (proven by running both commands clean and the output — messages, server interface, client stub — landing only in the new `gen/` dir). | FR2, NFR4 |
| **AC3** | The A→B gRPC wire is proven end-to-end by **all three** of: (a) a `GrpcWelcomeEmailRelay` **unit test** that mocks the buf-generated `*Client` stub and asserts the relay issues a real `\Grpc` **`UnaryCall`** with the correctly-mapped request; (b) an **in-process server test** wiring `App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService` → the unchanged `SendWelcomeEmailHandler`, asserting `OUTCOME_SENT` on success **and** at least one error status (`ABORTED` for `WelcomeInFlightException`, or `INVALID_ARGUMENT`) per the FR6 table; and (c) the k6 gRPC run (AC9b). Service B's handler is unchanged. | FR4, FR6, FR7, FR5 |
| **AC4** | With `WELCOME_EMAIL_TRANSPORT=grpc` the saga relay calls Service B over gRPC; with `=rest` it calls the REST endpoint; with `=rabbit` (or unset) it uses the existing async relay — **all three wired simultaneously**, switched by flag with no code change and **no REST deletion**. | FR3, FR7, FR8, FR11 |
| **AC5** | The **REST baseline** endpoint on Service B accepts the welcome-email send request, runs the unchanged handler, and returns `sent|failed` with the correct HTTP status — and **still works after gRPC lands** (not deleted, not broken). | FR3, FR5, FR11 |
| **AC6** | At least one automated gRPC-path test passes: a **happy path** asserting `OK`/`sent`, **and ≥1 error case asserting the correct gRPC status code**, following the existing `tests/Grpc/` / `tests/Integration/Grpc/` in-process pattern. | FR9, FR6 |
| **AC7** | All existing **HW7/HW9 tests and Behat scenarios pass unchanged**; `SendWelcomeEmailHandler` and the notification `Application` layer have no behavioral diff; the existing notification unit/integration tests stay green. | FR5, FR12 |
| **AC8** | The HW9 default async path is intact: with the flag at default, the saga relay → reply queue → timeout sweeper flow, the `SendWelcomeEmail/v1` and `WelcomeEmailOutcome/v1` contracts, and the existing `proto/release_notifier.proto` + `generated/` are all **unchanged**. | FR8, FR12, NFR1 |
| **AC9a ★** | A single **k6** harness driving the REST baseline (HTTP) and the gRPC path (`k6/net/grpc`) is **committed unconditionally** (does not require a booted stack to land in the repo): the script, its config/data and the `make` target are present and runnable. | FR10 |
| **AC10** | **Quality gates green:** monolith `composer lint` + `phpunit --testsuite Unit` + `composer psalm` (100%); the notification service's equivalent gates; the **deptrac baseline does not grow** (stays `{}`); the new gate is registered in `.github/required-pr-checks.txt`. | NFR5, R7 |
| **AC11** | Architecture docs updated: the new proto/package + buf wiring, the dedicated gen dir, the Service B gRPC server + REST endpoint, the Service A client adapters + flag, the gRPC status mapping, the client-runtime decision, and the deadline/retry policy are documented in `architecture.md` (and the LikeC4 model + an ADR). | NFR2, NFR6 |
| **AC9b ★** | With the docker stack booted (confirmed with the user separately, NFR6), the README records the **k6 numbers** produced by the AC9a harness: req/s and latency p50/p95/p99 for **both** transports and the difference, plus a short explanation (HTTP/2 multiplexing, binary protobuf vs text JSON, no schema reparse). **Gated** on stack boot — the bonus ★ is forfeited only if these numbers are absent, never the AC9a harness. | FR10 |
| **AC12** | The **REST baseline** route on Service B (registered in `apps/notification/http/index.php`) **still works after** the gRPC server is wired in (Story 2.3): a REST request returns `sent|failed` with the correct HTTP status with the gRPC surface present and running. | FR3, FR5, FR11 |

## Open Questions / Assumptions

- **[RESOLVED]** New proto package is `notification.welcome.v1` at
  `proto/notification/welcome/v1/welcome.proto`; the buf gen dir is the top-level
  `gen/` with `php_namespace = "Notification\\Welcome\\V1"` (greenfield, no collision
  with the legacy `Grpc\` → `generated/Grpc/`) (RD1, RD2). Exact PSR-4 wiring detail
  stays in architecture.md.
- **[ASSUMPTION]** The REST baseline is a `POST` JSON endpoint on Service B
  (path + auth posture → architecture.md); v1 has **no auth**, so the
  `UNAUTHENTICATED`/`PERMISSION_DENIED` rows of the FR6 table are reserved mappings,
  exercised only if/when auth is added.
- **[ASSUMPTION]** The caller-side seam surfaces the `sent|failed` outcome on the
  sync paths via an outcome-returning relay variant while the async `void` port
  contract is preserved on `rabbit`; the precise seam shape (extend vs. sibling
  port) is an architecture.md decision (FR7, DC1). `SagaWorker` itself does **not**
  change — only the DI relay binding swaps (RD4, DC10).
- **[RESOLVED]** Client-stub generation strategy = **`ext-grpc` + the remote
  `buf.build/grpc/php` plugin** for the client stub; `grpc/grpc` added to the root
  `composer.json`, `ext-grpc` to the root (client) Dockerfile. Service B keeps the
  local vendored Spiral **server**-only plugin (DC7, RD2, RD7, R3).
- **[RESOLVED]** A bounded **deadline + retry/backoff** policy exists and is pinned at
  the PRD level (DC8): definitive `OK(SENT|FAILED)` drives the saga in-thread; all
  other statuses leave it pending for the next tick (RD6). Concrete numeric values
  remain an architecture.md detail (NFR2, R5).
- **[RESOLVED]** The Service B gRPC server is a **second RoadRunner process** inside
  the notification container (not a separate compose service); the build context moves
  to the repo root so the image can copy `proto/` + `gen/` (DC9, RD3; onboarding §1,
  §9.4).
- **[RESOLVED]** The **broker stays required on the sync paths**: the AMQP reply
  consumer + sweeper keep running on all transports and `saga-worker` keeps
  `depends_on: rabbitmq`; the still-published async reply is an idempotent no-op on
  sync paths (DC10, RD4, RD5).
- **[OPEN]** Booting the docker stack to run the k6 benchmark (AC9b) is
  **confirmed with the user separately** before execution (NFR6).

---

*Grounding note:* every claim about the current system (no sync inter-service REST
call; all async RabbitMQ; the `WelcomeEmailRelay` port seam; the `void`
fire-and-forget + sweeper machinery; zero gRPC in `apps/notification`;
`ExceptionStatusMap` as the single status source with no gRPC arm on the
notification side; buf greenfield; the in-process gRPC test harness) is anchored to
`specs/rest-to-grpc-migration/onboarding.md` (§§1–9) with the file:line evidence
recorded there. This PRD adopts the onboarding's **Candidate 1/2 recommendation**
(welcome-email send/confirm, introduced REST baseline + gRPC twin behind a flag,
async `rabbit` retained as the default fallback) as the locked scope.
