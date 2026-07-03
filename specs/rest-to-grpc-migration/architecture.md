---
artifact: architecture
project: github-release-notifier
title: 'REST → gRPC migration — synchronous welcome-email send (unary RPC + REST baseline behind a flag)'
author: valerii
date: '2026-06-23'
status: draft (RD1–RD10 of the 2026-06-23 readiness report applied)
related:
  [
    'specs/rest-to-grpc-migration/prd.md',
    'specs/rest-to-grpc-migration/onboarding.md',
    'specs/rest-to-grpc-migration/implementation-readiness-report-2026-06-23.md',
    'specs/hw9-saga-subscription-confirmation/architecture.md',
    'specs/project-context.md',
    'docs/adr/0004-rest-to-grpc-welcome-email.md (to be authored in P9)',
    'docs/architecture/',
  ]
---

# Architecture — REST → gRPC: Synchronous Welcome-Email Send

This document resolves every forward-reference the PRD made to "→ architecture.md":
the PHP gRPC **client** strategy (D1), the **buf** codegen plan and gen-dir/PSR-4
isolation (D2), the full new **`.proto`** (D3), the Service B **gRPC server + REST
baseline** (D4), the Service A **caller seam** that surfaces `sent|failed` on the
sync paths without breaking the async `void` default (D5), the Service B gRPC
**deployment** shape (D6), the notification-side **`toGrpcStatus()`** error mapping
(D7), and the **quality gates / CI / deptrac** wiring (D8). It traces every
FR/NFR/AC of `prd.md` into a concrete, implementation-ready design grounded in the
real code, and it honors the locked decisions DC1–DC6.

> **Readiness-report alignment (2026-06-23).** This revision applies the binding
> Resolution Decisions RD1–RD10 of
> `implementation-readiness-report-2026-06-23.md`: the buf-lint-clean proto path
> `proto/notification/welcome/v1/welcome.proto` with the `OUTCOME_`-prefixed enum
> (RD1); the three-plugin `buf.gen.yaml` (remote messages + vendored Spiral server
> iface + remote client stub) with the prove-in-image gate and version pins (RD2);
> the repo-root notification build context (RD3); the no-code-change SagaWorker /
> DI-relay-swap framing (RD4); the **proven** double-signal idempotency (RD5); the
> transport-adapter outcome derivation with its exact catch order and the
> `WelcomeInFlightException` → ABORTED/409 arm (RD6); the `grpc/grpc` + `ext-grpc` +
> psalm `gen/` corrections (RD7); the real-client wire tests (RD8); and the
> ground-truth namespaces `App\Sending\Infrastructure\{Grpc,Http}` /
> `apps/notification/http/index.php` / the two disambiguated `WelcomeOutcome` enums
> (RD9). `int64 subscription_id` (RD10).

The crux is honest: there is **no synchronous inter-service REST call today** — all
monolith ↔ notification traffic is async RabbitMQ (onboarding §2). So this is not a
one-for-one transport swap. It is: keep the async `rabbit` path **as the default and
the HW9 backstop**, and add two **opt-in synchronous twins** (`rest`, `grpc`) over
the *same unchanged* `SendWelcomeEmailHandler`, selected by
`WELCOME_EMAIL_TRANSPORT`. Nothing in HW9 is removed (PRD N4/N5/FR12).

## 1. Principles

The HW7/HW9 clean-layering principles carry over unchanged; this migration adds the
transport-substitution principles on top.

1. **Clean / Hexagonal layering, dependency rule points inward.**
   `Domain ← Application ← Infrastructure` inside every module. The new code is
   **all Infrastructure** on both sides: the gRPC server and REST controller on
   Service B, and the two new `WelcomeEmailRelay`-shaped client adapters on Service
   A. No Domain or Application class is added or changed (FR5/DC6/N3) — the existing
   `SendWelcomeEmailHandler` (Service B) and `HandleWelcomeEmailOutcomeHandler`
   (Service A reply path) are reused verbatim.

2. **One server brain, three transports (NFR3).** `rabbit`, `rest`, `grpc` are
   three driving adapters in front of the **same** `SendWelcomeEmailHandler`. The
   handler already computes the `sent|failed` disposition and publishes a reply
   (`apps/notification/src/Sending/Application/SendWelcomeEmailHandler.php:42-100`);
   the sync surfaces simply *read that disposition back out* instead of consuming a
   reply from a queue.

3. **Default-safe, opt-in migration (NFR1/DC1).** `WELCOME_EMAIL_TRANSPORT` defaults
   to `rabbit`. With the default, **zero** behavior changes: the HW9 relay → reply
   queue → timeout sweeper machinery, the `SendWelcomeEmail/v1` +
   `WelcomeEmailOutcome/v1` contracts, the legacy `proto/release_notifier.proto`, and
   the committed `generated/` tree are all untouched. The sync paths cannot regress
   production unless explicitly enabled.

4. **Caller-waits is a deliberate semantic shift, contained (DC1/NFR2/R5).** On
   `rest`/`grpc` the caller blocks for `sent|failed`, which removes the broker buffer
   and the sweeper's never-hangs guarantee **on those paths only**. The sync client
   therefore owns its own **deadline + bounded retry/backoff** (§7) — the explicit
   replacement for the buffer+sweeper. The `rabbit` default keeps the sweeper.

5. **One exception → status mapping, per side (FR6/DC reuse).** Each service keeps a
   single `App\Sending\Infrastructure\Error\ExceptionStatusMap` as the only source of
   exception→transport mapping. The notification map gains a `toGrpcStatus()` arm (it
   is HTTP-only today,
   `apps/notification/src/Sending/Infrastructure/Error/ExceptionStatusMap.php`),
   reused by **both** the REST controller (HTTP status) and the gRPC server (status
   code) — mirroring the monolith's `ReleaseNotifierService::mapException()` pattern.
   Both `toGrpcStatus()` and `toHttpStatus()` gain a `WelcomeInFlightException` arm
   (ABORTED / 409) **ahead** of the catch-all `\RuntimeException` arm — load-bearing,
   because `WelcomeInFlightException` and `WelcomeAlreadyFailedException` both extend
   `\RuntimeException` (RD6).

6. **buf greenfield, isolated gen dir (DC3/NFR4).** buf is adopted **only** for the
   new proto, generating into a dedicated `gen/` tree with its own PSR-4 prefixes,
   **separate** from the committed `generated/`. `make proto` (raw protoc + Spiral
   plugin) and `generated/` are untouched (N7).

7. **Wire-format protection, additive only (FR12/DC4).** The welcome RPC lives in a
   **new** proto file + **new package**; `proto/release_notifier.proto` is frozen.
   The REST/JSON contract is net-new (DC2). No existing wire contract changes.

8. **Strangler-clean, both baselines stay `{}` (NFR5/R7/AC10).** Every new edge is an
   explicit, justified deptrac edge; `deptrac.baseline.yaml` keeps `skip_violations:
   {}` on **both** deployables. The new `gen/` tree is excluded from hand-written
   gates exactly as `generated/` is (it is generated code).

## 2. Tooling readiness (verified, read-only)

Verified on `2026-06-23` (`which`, `php -m`, `ls tools/bin`, both Dockerfiles):

| Tool | Host | In images today | Action |
|---|---|---|---|
| `buf` | **absent** | **absent** (both Dockerfiles) | **install** in the **root** Dockerfile (codegen runs there), **pinned** release; also a `buf` Make/CI step. `buf lint` runs fully offline; `buf generate` reaches buf.build for the two remote plugins (RD2). |
| `protoc` | absent (host) | **present in root image** (`Dockerfile:4`, `protobuf-compiler`); **absent in notification image** | needed only for the **offline fallback** (`protoc-gen-php` shim wrapping `protoc --php_out`); buf's remote `protocolbuffers/php` plugin supplies messages on the network path. |
| `protoc-gen-php-grpc` (Spiral server plugin) | n/a | **vendored** at `tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc` (5.0 MB, exec) | reuse as the **`local`** buf plugin for the Service B server interface (the one always-local plugin — RD2). |
| `grpc_php_plugin` (official client stub gen) | **absent** | **absent** | **offline-fallback only**: install a **pinned** `grpc_php_plugin` binary in the codegen (root) image; the default network path uses the **remote** `buf.build/grpc/php` plugin (RD2) — both emit the `*Client extends \Grpc\BaseStub` stub (D1/D2). |
| `protoc-gen-php` (protobuf-builtin messages) | n/a | **no standalone binary exists** — protobuf message codegen is a `protoc` builtin (`protoc --php_out`), not a separate `protoc-gen-php` executable (RD2; `Makefile:130`) | network path uses **remote** `buf.build/protocolbuffers/php`; offline fallback is a thin `protoc-gen-php` **shim** that shells `protoc --php_out`. There is **no** standalone `protoc-gen-php` to install. |
| PECL `ext-grpc` (PHP gRPC runtime) | **not loaded** | **absent** (root FrankenPHP 8.4 has `pdo_pgsql pcntl sockets redis`; notification 8.2-alpine has `pdo_pgsql sockets pcntl`) | **install `ext-grpc` in the root (monolith) image only** — the gRPC **client** runs in the monolith saga-worker (D1/D6) |
| `ext-protobuf` (C protobuf runtime) | not loaded | absent (both rely on the pure-PHP `google/protobuf` lib) | **optional** perf upgrade; not required — `google/protobuf` pure-PHP works for both. Recommend installing alongside `ext-grpc` in the root image for the benchmark (FR10). |
| RoadRunner `rr` binary + `spiral/roadrunner-grpc` | n/a | **`rr` present in root image** (`Dockerfile:21-26`); `spiral/roadrunner-grpc` in **root composer only** | notification image needs **both** added (it has zero gRPC, onboarding §4) for the Service B gRPC server (D4/D6) |

**Net new installs, by image:**
- **Root `Dockerfile` (monolith)** — add `ext-grpc` (+ optional `ext-protobuf`) via `install-php-extensions grpc protobuf`; add a **pinned** `buf` (+ the **pinned** offline-fallback `grpc_php_plugin` binary and the `protoc-gen-php` shim) because codegen runs in this container via the Make target. The gRPC **client** runtime (`ext-grpc`) is needed here because the saga-worker is a monolith process. Add `grpc/grpc` to the **root** `composer.json` (the client lib + psalm symbol resolution, RD7) — `ext-grpc` goes in the **root Dockerfile only**, never the notification image.
- **`apps/notification/Dockerfile`** — add `spiral/roadrunner-grpc` + `google/protobuf` (composer), the `rr` binary, and the vendored Spiral server plugin is **not** needed at runtime (only at codegen). `ext-grpc` is **not** required on Service B — RoadRunner terminates gRPC and hands decoded messages to PHP; the Spiral server runtime is pure-PHP + the `rr` Go binary. (This mirrors the monolith `grpc` service, which runs without `ext-grpc`.)

> **Load-bearing asymmetry (resolves R3):** the **server** side (Service B) needs
> **no `ext-grpc`** — RoadRunner's Go process speaks gRPC and the Spiral plugin emits
> a pure-PHP server interface (exactly like the monolith `grpc` service runs today).
> The **client** side (Service A saga-worker) is the *only* place that needs the PHP
> gRPC runtime, so `ext-grpc` goes in the **root image only**. This is why D1 picks
> the official client and confines the PECL dependency to one image.

## 3. The transport seam (component sketch)

```
 Service A (MONOLITH image, Postgres A)                Service B (apps/notification, Postgres B)
 ─────────────────────────────────────                ─────────────────────────────────────────
 saga-worker tick:                                     SendWelcomeEmailHandler  (UNCHANGED, DC6/N3)
   RelayPendingWelcomeEmails ─┐                          claim → render → send → markSent
   (rabbit only)              │                          returns/throws; computes sent|failed
                             ▼ WelcomeEmailRelay port (publish: void)
  ┌────────────── transport selected by WELCOME_EMAIL_TRANSPORT (default rabbit) ──────────────┐
  │ rabbit  RabbitWelcomeEmailRelay  ──AMQP──► notifications.welcome-email ─► SendWelcomeEmailConsumer ─┐
  │ (DEFAULT, async, void)            fire-and-forget; reply queue; sweeper backstop (HW9 intact)       │
  │                                                                                                     ▼
  │ rest    RestWelcomeEmailRelay  ──HTTP/1.1+JSON──► [NEW] POST /internal/welcome-emails ──► WelcomeEmailController ─┤
  │ (opt-in, sync)  waits sent|failed; on outcome → dispatch HandleWelcomeEmailOutcomeCommand           │
  │                                                                                                     │
  │ grpc    GrpcWelcomeEmailRelay  ──HTTP/2+protobuf──► [NEW] WelcomeEmailGrpcService (RoadRunner) ─────┘
  │ (opt-in, sync)  waits sent|failed; on outcome → dispatch HandleWelcomeEmailOutcomeCommand
  └─────────────────────────────────────────────────────────────────────────────────────────────────┘
        sync adapters drive the saga directly via the existing HandleWelcomeEmailOutcomeCommand (D5)
        errors: ExceptionStatusMap → HTTP status (REST) / gRPC status code (gRPC), one map per side (D7)
```

The three relays implement the **same** `WelcomeEmailRelay`-shaped seam (NFR3) and
are interchangeable by flag with **no change** to the saga orchestration. The server
runs the **same** handler for all three (FR5).

## 4. Decisions (D1–D8)

| # | Decision | Rationale | Alternatives rejected |
|---|---|---|---|
| **D1** | **Official gRPC PHP client** (`grpc/grpc` composer lib in **root `composer.json`** + PECL **`ext-grpc`** in the **root/monolith image only**), with the **client stub generated by the remote `buf.build/grpc/php` plugin** (offline fallback: a pinned `grpc_php_plugin` binary). The stub is `Notification\Welcome\V1\WelcomeEmailServiceClient extends \Grpc\BaseStub`. | The Spiral server plugin emits **no client stub** (onboarding §4). The official client is the only battle-tested PHP gRPC client; `ext-grpc` is confined to the one image that runs the client (saga-worker), so Service B stays runtime-pure (§2). buf driving the remote `grpc/php` plugin keeps codegen single-sourced; `grpc/grpc` in root composer also resolves `\Grpc\*` symbols for psalm (RD7). | (b) Hand-written HTTP/2 client over buf messages — re-implements framing, status trailers, deadlines; fragile, high-risk, fails "no hand-waving on the gRPC client". (c) RoadRunner client / Temporal SDK — not a gRPC unary client. Rejected: a *second* client lib. |
| **D2** | **buf v2, multi-plugin, isolated `gen/`.** `buf.yaml` (`modules: - path: proto`; `lint: use: [STANDARD]`; `breaking: use: [FILE]`) + `buf.gen.yaml` with **three plugins** off the one new proto: (1) **remote `buf.build/protocolbuffers/php`** → messages (protobuf-builtin codegen has **no** standalone `protoc-gen-php` binary, RD2); (2) the vendored Spiral `protoc-gen-php-grpc` as the **`local`** plugin → Service B server interface; (3) **remote `buf.build/grpc/php`** → Service A client stub. Out dir `gen/`; new PSR-4 prefixes `Notification\Welcome\V1\` → `gen/Notification/Welcome/V1/` and `GPBMetadata\Proto\Notification\` → `gen/GPBMetadata/...`, added to **both** composer.json files. **Pin** `buf` + plugin versions. | PRD G2/NFR4/DC3 mandate buf greenfield into a dedicated dir separate from `generated/`. Three outputs from one proto = three plugins. `buf lint` is fully **offline** (the hard AC); only `buf generate` reaches buf.build for the two remote plugins (acceptable codegen-time dependency, with a pinned offline fallback). Distinct namespace + dir = zero collision with `Grpc\` → `generated/Grpc/`. | Claiming a standalone `protoc-gen-php` binary exists — false (it is a `protoc --php_out` builtin). Regenerating `generated/` via buf — explicitly out of scope (N7). Single shared gen dir — would collide PSR-4 with the legacy `Grpc\` map. |
| **D3** | **New proto** `proto/notification/welcome/v1/welcome.proto` (path satisfies PACKAGE_DIRECTORY_MATCH against `buf.yaml` `modules: - path: proto`), `package notification.welcome.v1`, `option php_namespace = "Notification\\Welcome\\V1"`, one unary `SendWelcomeEmail(SendWelcomeEmailRequest) returns (SendWelcomeEmailResponse)` with `enum Outcome {OUTCOME_UNSPECIFIED=0; OUTCOME_SENT=1; OUTCOME_FAILED=2;}` (the `OUTCOME_` prefix satisfies ENUM_VALUE_PREFIX) and `int64 subscription_id`; request fields mirror `SendWelcomeEmail/v1` so the handler input is unchanged. Full file in §5. Legacy proto frozen. | FR1/AC1/N2/DC4 + RD1: versioned, documented, unary, separate file/package, **buf-lint-clean under STANDARD with zero findings**; mirroring the HW9 payload keeps `SendWelcomeEmailHandler` input identical (FR5). | Editing the legacy proto / adding the RPC to `ReleaseNotifierService` — breaks N2/FR12. A flat `proto/notification_welcome.proto` path — fails PACKAGE_DIRECTORY_MATCH (RD1). Bare `SENT`/`FAILED` enum values — fail ENUM_VALUE_PREFIX. Streaming RPC — N6. |
| **D4** | **Service B: a RoadRunner gRPC server class** (`App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService implements WelcomeEmailServiceInterface`) + a `.rr.grpc.yaml` for the notification app, **and** a **Slim REST baseline** `POST /internal/welcome-emails` (`App\Sending\Infrastructure\Http\WelcomeEmailController`, sibling of `HealthController`/`MetricsController`) registered in `apps/notification/http/index.php`. Both call the **unchanged** `App\Sending\Application\SendWelcomeEmailHandler` and map exceptions via `App\Sending\Infrastructure\Error\ExceptionStatusMap`. The transport adapter (not the handler) derives the outcome with the exact RD6 catch order (§6.1). | FR3/FR4/FR5/N3 + RD6/RD9: net-new transport adapters only; copy the monolith's proven `bin/grpc.php` + `.rr.grpc.yaml` + `ReleaseNotifierService` shape; the REST endpoint reuses the already-wired Slim app + `ErrorHandlerMiddleware`. | A separate REST micro-app — the Slim app already exists (`http/index.php`); reuse it. Folding REST into RoadRunner HTTP — adds a second HTTP server; the `php -S` one already serves health/metrics. |
| **D5** | **Service A caller seam: an outcome-returning sibling port** `SyncWelcomeEmailSender` (Saga.Domain) — `send(SendWelcomeEmail): App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome` — implemented by `App\Saga\Enrollment\Infrastructure\Rest\RestWelcomeEmailRelay` + `App\Saga\Enrollment\Infrastructure\Grpc\GrpcWelcomeEmailRelay`. The sync relays obtain `sent\|failed` directly and **drive the saga by dispatching the existing `HandleWelcomeEmailOutcomeCommand`** through the in-house `CommandBus`. The async `WelcomeEmailRelay::publish(): void` port is **unchanged**. `config/container.php` selects only the **relay binding** by `WELCOME_EMAIL_TRANSPORT` (default `rabbit`) — `SagaWorker` itself is **not** modified (RD4). | The async port is fire-and-forget `void` (`WelcomeEmailRelay.php:16`); widening it to return an outcome would force the Rabbit adapter to fake an outcome and pollute the relay use-case. A **sibling** port keeps the async contract pristine (FR7/FR12/DC1) while reusing the *already-built* reply-path command (`HandleWelcomeEmailOutcomeCommand`, which carries the caller-side `HandleOutcome\WelcomeOutcome` enum) so the saga state machine code is unchanged. | Extend `WelcomeEmailRelay` to return `?WelcomeOutcome` — leaks sync semantics into the async port + every caller. A brand-new outcome handler — duplicates `HandleWelcomeEmailOutcomeHandler`. Rewiring `SagaWorker` — RD4 forbids a code change. All rejected. |
| **D6** | **Service B gRPC server = a second supervised process inside the notification container** (mirroring `bin/start.sh` forking `php -S :8081` + `exec bin/consumer.php`): `start.sh` also forks `rr serve -c .rr.grpc.yaml` on **`:9002`** plaintext. The forked `rr` runs **unsupervised** under `set -e` (the `/health` probe covers `:8081` only) — an **accepted** risk recorded in ADR-0004 (RD10f). The notification build context becomes the **repo root** (`context: .`, `dockerfile: apps/notification/Dockerfile`) so the image can `COPY proto` + `COPY gen` (RD3); a dedicated compose service `notification-grpc` is **not** added; the gRPC port is published from `notification-svc`. | NFR6/onboarding Open-decision-4: one container, N processes is the established pattern here (`bin/start.sh` already supervises two). Avoids a second image build + duplicate env wiring. Plaintext over the compose network (N6). RD3 repo-root context is the only way the runtime image can reach `proto/`+`gen/`. | A separate `notification-grpc` compose service (own `build`, env, depends_on) — duplicates the image and env; heavier. A per-app build context (`./apps/notification`) — **cannot** COPY repo-root `proto/`/`gen/` (RD3 blocker). Rejected (kept as a noted future option if the gRPC server needs independent scaling). |
| **D7** | **Notification `toGrpcStatus(\Throwable): int`** added to `App\Sending\Infrastructure\Error\ExceptionStatusMap`, returning Spiral `StatusCode::*`, honoring the FR6 HTTP→gRPC table **with a `WelcomeInFlightException` → ABORTED arm placed before the `\RuntimeException` catch-all** (load-bearing — both that and `WelcomeAlreadyFailedException` extend `\RuntimeException`, RD6); the gRPC server's `mapException()` mirrors `ReleaseNotifierService::mapException()` (INTERNAL → `ServiceException`, else `GRPCException`). The same map's `toHttpStatus()` drives the REST status (with the matching `WelcomeInFlightException` → 409 arm), so an exception maps consistently across both sync transports (FR6/R6). | FR6/AC3/AC6: one source of truth per side; the existing map is HTTP-only (onboarding §5) and must gain the gRPC arm; reuse the monolith's exact translation idiom. RD6 requires the InFlight arm so benign lock contention surfaces as ABORTED/409 (not UNAVAILABLE), keeping the caller from retrying lock contention. | A standalone gRPC mapper — violates "one mapping" (project-context). Inlining the match in the server — duplicates mapping. Leaving InFlight to fall through to UNAVAILABLE — the original blocker (RD6). Rejected. |
| **D8** | **New gates:** `make buf-lint` (buf lint, **fully offline**) + the new gRPC/REST tests folded into the existing `make test` / `make notification-unit` suites; a new **`grpc.yml`** workflow whose `name:` is **byte-identical** to its `.github/required-pr-checks.txt` row (RD10e), and the welcome-RPC tests ride the existing `Unit Tests` / `PHPUnit (notification service)` rows. Register **one** new row in `.github/required-pr-checks.txt`. Story 1.3 is a **P0 prove-in-image gate**: actually run `make buf-lint` + `make buf-generate` in the built image and confirm all three plugin outputs land in `gen/` (RD2). deptrac: **Saga.Infrastructure** gains the two new client adapters (already allowed: `Saga.Infrastructure → Saga.Domain/Application/Shared.*`); **Apps.Notification** gains the gRPC server + REST controller (under `Notification.Infrastructure`, already allowed `Infrastructure → Domain/Application/Shared`); both baselines stay `{}`. `gen/` excluded from phpcs; for psalm `gen/` goes in `projectFiles` **and** `ignoreFiles` (resolve symbols, don't analyze, RD7). | NFR5/R7/R8/AC10: buf lint must be enforced; the new tests must run in CI; the deptrac baseline must not grow. Reusing existing layers (no new layer) is what keeps both baselines `{}`. | A new deptrac *layer* for the gen dir — generated code isn't scanned by deptrac anyway (it's outside `src/`/`apps/`), so no layer is needed. Rejected as noise. |

## 5. The proto contract (D3) — designed artifact, do not create yet

**File:** `proto/notification/welcome/v1/welcome.proto` (alongside the frozen
`proto/release_notifier.proto`). The nested `notification/welcome/v1/` path makes the
file location match `package notification.welcome.v1`, satisfying buf STANDARD's
PACKAGE_DIRECTORY_MATCH against `buf.yaml`'s `modules: - path: proto` (RD1).

```proto
syntax = "proto3";

// Versioned package for the welcome-email send RPC (REST→gRPC migration).
// Separate file + package from release_notifier.v1 so the monolith's existing
// Subscription gRPC API and its generated/ tree stay frozen (PRD N2/FR12/DC4).
package notification.welcome.v1;

// Distinct PHP namespace so the buf-generated classes never collide with the
// committed generated/ tree's `Grpc\ReleaseNotifier\V1` PSR-4 map (PRD NFR4/DC3).
option php_namespace = "Notification\\Welcome\\V1";
option php_metadata_namespace = "GPBMetadata\\Proto\\Notification";

// The single synchronous inter-service RPC. Service A (monolith saga relay) calls
// Service B (notification) and BLOCKS for the sent|failed outcome — the deliberate
// caller-waits semantic shift on the opt-in transports (PRD DC1). The async rabbit
// path remains the default and is unaffected by this contract.
service WelcomeEmailService {
  // Render and send the welcome email for a confirmed-pending subscription, then
  // return whether it was sent or failed. Business sent|failed is a normal OK
  // response field; infrastructure/validation errors surface as gRPC STATUS codes
  // (PRD FR6 table), never in-band.
  rpc SendWelcomeEmail(SendWelcomeEmailRequest) returns (SendWelcomeEmailResponse);
}

// Request — field set mirrors the HW9 `SendWelcomeEmail/v1` AMQP payload exactly so
// the unchanged SendWelcomeEmailHandler receives an identical WelcomeEmail VO (FR5).
message SendWelcomeEmailRequest {
  // The orchestrating saga's UUID; echoed back so the caller can correlate the
  // outcome to its saga row (AMQP correlation_id = saga_id on the rabbit path).
  string saga_id = 1;

  // The subscription this welcome is for. The service-side welcome ledger is keyed
  // by this id alone (one welcome per subscription) for exactly-once send dedup.
  // int64 (RD10a) — avoids truncating large subscription ids.
  int64 subscription_id = 2;

  // Recipient email address. Validated by the EmailAddress value object on receipt;
  // a malformed value yields gRPC INVALID_ARGUMENT (PRD FR6).
  string email = 3;

  // The "owner/repo" the subscriber signed up for; rendered into the email body.
  // Validated by the RepositoryName value object on receipt.
  string repository = 4;
}

// Response — carries the business disposition as a normal OK-status field.
message SendWelcomeEmailResponse {
  // sent | failed (or unspecified, never returned by the server — present only so
  // proto3 enum-zero rules are honored).
  Outcome outcome = 1;

  // Human-readable detail, populated only when outcome = FAILED (mirrors the
  // `error` field of WelcomeEmailOutcome/v1). Empty on SENT.
  string error_detail = 2;
}

// The terminal disposition of a welcome send. OUTCOME_UNSPECIFIED = 0 satisfies the
// proto3 default-zero rule and is never emitted by the server. The OUTCOME_ value
// prefix satisfies buf STANDARD's ENUM_VALUE_PREFIX (RD1).
enum Outcome {
  OUTCOME_UNSPECIFIED = 0;
  OUTCOME_SENT = 1;
  OUTCOME_FAILED = 2;
}
```

**`buf.yaml`** (repo root):

```yaml
version: v2
modules:
  - path: proto
lint:
  use:
    - STANDARD
  # The frozen legacy proto predates buf STANDARD lint (its file/package layout is
  # fixed by N2/FR12); exclude it so buf lint gates ONLY the new contract.
  ignore:
    - proto/release_notifier.proto
breaking:
  use:
    - FILE
```

**`buf.gen.yaml`** (repo root) — three plugins (two remote + one local), one isolated
out dir. **Pin** the `buf` CLI release and each plugin version (RD2):

```yaml
version: v2
managed:
  enabled: false   # php_namespace is set explicitly in the proto (DC3 isolation)
plugins:
  # (1) Protobuf MESSAGE classes — REMOTE buf.build/protocolbuffers/php (pinned).
  #     There is NO standalone protoc-gen-php binary; message codegen is a protoc
  #     builtin, so the network path uses the remote plugin (RD2).
  - remote: buf.build/protocolbuffers/php:v29.3
    out: gen
  # (2) Service B SERVER interface — the vendored Spiral plugin as the one LOCAL plugin.
  - local: tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc
    out: gen
  # (3) Service A CLIENT stub — REMOTE buf.build/grpc/php (pinned), emits *Client
  #     extends \Grpc\BaseStub (D1). Installed in the codegen (root) image (§2).
  - remote: buf.build/grpc/php:v1.69.0
    out: gen
    opt:
      - class_suffix=Client
```

> **Offline posture (RD2).** `buf lint` is **fully offline** — that is the hard AC and
> it gates only the new proto. `buf generate` reaches **buf.build** for plugins (1)
> and (3) — an acceptable codegen-time-only network dependency. For an air-gapped
> codegen run, the documented **offline fallback** swaps (1) to a thin
> `protoc-gen-php` shim that shells `protoc --php_out` and (3) to a **pinned**
> `grpc_php_plugin` binary (both installed in the root image, §2); plugin (2) is
> already local. Either way, the three outputs are identical and **version-pinned**.
> Story 1.3 **proves codegen in the built image** (runs `make buf-generate` and
> confirms all three outputs land in `gen/`) before any app code depends on them.

**Generated layout (committed, like `generated/`):**

```
gen/
  Notification/Welcome/V1/
    SendWelcomeEmailRequest.php          # message (plugin 1)
    SendWelcomeEmailResponse.php         # message (plugin 1)
    Outcome.php                          # enum    (plugin 1)
    WelcomeEmailServiceInterface.php     # SERVER interface (plugin 2, Service B)
    WelcomeEmailServiceClient.php        # CLIENT stub      (plugin 3, Service A)
  GPBMetadata/Proto/Notification/
    NotificationWelcome.php              # descriptor metadata (plugin 1)
```

**PSR-4 additions** — root `composer.json` (Service A uses the client stub + messages;
Service B server interface is irrelevant to the monolith but harmless under the same
prefix), AND `apps/notification/composer.json` (Service B uses the server interface +
messages; the client stub is unused there). Both add:

```jsonc
// composer.json  (root)  and  apps/notification/composer.json
"autoload": {
  "psr-4": {
    // …existing…
    "Notification\\Welcome\\V1\\": "gen/Notification/Welcome/V1/",
    "GPBMetadata\\Proto\\Notification\\": "gen/GPBMetadata/Proto/Notification/"
  }
}
```

For `apps/notification`, the build context becomes the **repo root** (RD3:
`context: .`, `dockerfile: apps/notification/Dockerfile`), so the Dockerfile copies the
app subtree explicitly (`COPY apps/notification/{src,bin,config,migrations,http} ...`)
**plus** `COPY proto ./proto` and `COPY gen ./gen` from the root context. The PSR-4
prefix then resolves `Notification\Welcome\V1\` from the copied `gen/` (§10).

## 6. Service B — gRPC server + REST baseline (D4/D7)

### 6.1 gRPC server

`apps/notification/src/Sending/Infrastructure/Grpc/WelcomeEmailGrpcService.php` —
`final readonly class` implementing the generated `WelcomeEmailServiceInterface`,
`#[\Override]` on the RPC method, modeled byte-for-byte on
`src/Grpc/ReleaseNotifierService.php`:

```php
final readonly class WelcomeEmailGrpcService implements WelcomeEmailServiceInterface
{
    public function __construct(
        private SendWelcomeEmailHandler $handler,   // UNCHANGED (FR5/DC6)
        private WelcomeEmailFactory $factory,       // builds WelcomeEmail VO from the request
        private ExceptionStatusMap $statusMap,      // gains toGrpcStatus() (D7)
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function SendWelcomeEmail(ContextInterface $ctx, SendWelcomeEmailRequest $in): SendWelcomeEmailResponse
    {
        // EXACT catch order (RD6) — load-bearing: WelcomeAlreadyFailedException AND
        // WelcomeInFlightException both extend \RuntimeException, so the specific arms
        // MUST precede any \RuntimeException/\Throwable catch-all.
        try {
            $email = $this->factory->fromGrpc($in);   // EmailAddress/RepositoryName VOs validate → INVALID_ARGUMENT on bad input
            $this->handler->handle($email);            // claim/render/send/markSent (also publishes the AMQP reply, harmless)
            // (1) normal return → SENT (covers Claimed→markSent, AlreadySent dedup, fenced/superseded)
            return new SendWelcomeEmailResponse(['outcome' => Outcome::OUTCOME_SENT]);
        } catch (WelcomeAlreadyFailedException $e) {
            // (2) terminal-failed → FAILED as a NORMAL OK response (drives saga compensate)
            return new SendWelcomeEmailResponse(['outcome' => Outcome::OUTCOME_FAILED, 'error_detail' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // (3) WelcomeInFlightException → ABORTED (benign contention), (4) validation
            //     → INVALID_ARGUMENT, (5) any other throw → INTERNAL/UNAVAILABLE — all
            //     resolved by ExceptionStatusMap::toGrpcStatus() (§6.3), whose InFlight
            //     arm sits ahead of the \RuntimeException catch-all.
            throw $this->mapException($e);
        }
    }

    private function mapException(\Throwable $e): GRPCException
    {
        $code = $this->statusMap->toGrpcStatus($e);
        $message = $this->statusMap->toClientMessage($e);
        return $code === StatusCode::INTERNAL
            ? ServiceException::create($message, $code, $e)
            : GRPCException::create($message, $code, $e);
    }
}
```

**Outcome mapping is honest about the existing handler.** `SendWelcomeEmailHandler`
(`App\Sending\Application\SendWelcomeEmailHandler`) returns `void` and *throws*
`WelcomeAlreadyFailedException` on the terminal-failed redelivery path and
`WelcomeInFlightException` on a concurrent lease (both are
`App\Sending\Application\*` and both extend `\RuntimeException`); on a fresh send
failure it rethrows the SMTP/infra exception (so the consumer can retry). The
transport adapter — the new Service B gRPC server / REST controller, **not** the
handler — therefore maps with this **exact catch order** (RD6):
1. handler returns normally (sent / dedup-already-sent / fenced-superseded) →
   `Outcome::OUTCOME_SENT`;
2. `WelcomeAlreadyFailedException` → `Outcome::OUTCOME_FAILED` (business outcome, a
   normal OK response that drives the saga compensate);
3. `WelcomeInFlightException` → gRPC **ABORTED** / REST **409** (benign contention;
   the caller leaves the saga pending and retries next tick — do **not** drive the saga);
4. `ValidationException`/`InvalidArgumentException` (bad shape / invalid VO) →
   **INVALID_ARGUMENT** / 400;
5. any other throw (SMTP down, PDO error, rethrown send failure) → **INTERNAL** /
   500 (transient; the caller leaves the saga pending, next relay tick retries).

All non-trivial arms route through `ExceptionStatusMap` (§6.3); arms 3–5 are
status-code throws. This keeps Service B business logic **unchanged** (FR5/N3): the
surface only reads the handler's existing dispositions; it adds no claim/send logic.
The handler still publishes its AMQP reply as a side effect — harmless on the sync
path (the monolith's reply consumer simply ack-and-drops a no-op reply for an
already-confirmed saga, per HW9 §7 and the idempotency **proof** in §13), and it means
the sync and async planes converge on the same saga row.

### 6.2 REST baseline

`apps/notification/src/Sending/Infrastructure/Http/WelcomeEmailController.php` —
`final readonly class`, registered in `apps/notification/http/index.php`:

```php
$app->post('/internal/welcome-emails', WelcomeEmailController::class);
```

Request JSON (mirrors the proto / the HW9 payload):

```json
{ "sagaId": "5f1c0e2a-…", "subscriptionId": 123, "email": "user@example.com", "repository": "owner/repo" }
```

Response JSON:

```json
{ "outcome": "sent" }                                  // HTTP 200
{ "outcome": "failed", "error": "welcome notification previously failed terminally" }   // HTTP 200 (business failure is a normal result)
{ "error": "welcome send already in flight" }          // HTTP 409 — WelcomeInFlightException (benign contention, RD6); caller retries next tick
{ "error": "Service unavailable" }                     // HTTP 503/500/400 — transport/validation error via ExceptionStatusMap.toHttpStatus()
```

The controller validates the JSON shape (missing fields / non-JSON body →
`ValidationException`, a transport-level shape check per project-context), builds the
`WelcomeEmail` VO through `WelcomeEmailFactory` (VO construction *is* the field
validation), calls the **same** `SendWelcomeEmailHandler`, and lets the existing
`ErrorHandlerMiddleware` + `ExceptionStatusMap.toHttpStatus()` map any throw. The
endpoint is **kept working after gRPC lands** (FR3/FR11/AC5) — it is the benchmark
baseline (FR10) and the simplest sync transport.

> **Auth posture (PRD assumption):** v1 has **no auth** — `/internal/welcome-emails`
> is reachable only on the compose network (N6). The FR6 `401/403` rows are **reserved**
> mappings (§6.3) exercised only if/when auth is added. By contrast the `409`/ABORTED
> row is **reachable** (it is the live `WelcomeInFlightException` arm, RD6). ADR-0004
> records this reachable-vs-reserved split (RD10f).

### 6.3 Notification `ExceptionStatusMap` gains `toGrpcStatus()` (D7)

The existing map is HTTP-only
(`App\Sending\Infrastructure\Error\ExceptionStatusMap`, whose current
`toHttpStatus()` catches `\RuntimeException` → 503 as its first arm). Add a
`toGrpcStatus()` arm honoring the FR6 table, plus the `WelcomeInFlightException` and
validation arms the new sync surface needs — and, **load-bearing per RD6**, place the
`WelcomeInFlightException` arm **before** the `\RuntimeException` catch-all (otherwise
the InFlight subclass falls through to UNAVAILABLE and the caller retries lock
contention — the original blocker). The notification VO factory throws a
notification-local validation exception (`WelcomeRequestValidationException`):

```php
// Welcome*Exception are App\Sending\Application\* and BOTH extend \RuntimeException;
// the specific arms MUST sit ahead of the \RuntimeException catch-all (RD6).
public function toGrpcStatus(\Throwable $e): int
{
    return match (true) {
        $e instanceof WelcomeRequestValidationException => GrpcStatus::INVALID_ARGUMENT, // 3  (bad shape / invalid VO)
        $e instanceof WelcomeInFlightException          => GrpcStatus::ABORTED,           // 10 (benign contention; caller leaves saga pending)
        $e instanceof \RuntimeException,
        $e instanceof \PDOException                      => GrpcStatus::UNAVAILABLE,       // 14 (SMTP/DB/broker down)
        default                                          => GrpcStatus::INTERNAL,          // 13
    };
}
```

And extend `toHttpStatus()` symmetrically — a `400` arm for
`WelcomeRequestValidationException` and a `409` arm for `WelcomeInFlightException`,
both **before** the `\RuntimeException` → 503 arm — so REST and gRPC agree (FR6/RD6):

```php
public function toHttpStatus(\Throwable $e): int
{
    return match (true) {
        $e instanceof WelcomeRequestValidationException => StatusCodeInterface::STATUS_BAD_REQUEST,  // 400
        $e instanceof WelcomeInFlightException          => StatusCodeInterface::STATUS_CONFLICT,     // 409
        $e instanceof \RuntimeException,
        $e instanceof \PDOException                      => StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE, // 503
        default                                          => StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, // 500
    };
}
```

The FR6 table maps fully onto Spiral `StatusCode` constants (verified present:
`INVALID_ARGUMENT=3, NOT_FOUND=5, ALREADY_EXISTS=6, PERMISSION_DENIED=7,
RESOURCE_EXHAUSTED=8, ABORTED=10, INTERNAL=13, UNAVAILABLE=14, DEADLINE_EXCEEDED=4,
UNAUTHENTICATED=16`). `DEADLINE_EXCEEDED`/`UNAVAILABLE` on the **client** side are
produced by the gRPC runtime/transport, not by this map (the map covers the
**server-raised** rows); the client adapter translates a transport
`DEADLINE_EXCEEDED`/`UNAVAILABLE` into its retry/give-up policy (§7). An in-process
server test asserts the `ABORTED` status for `WelcomeInFlightException` (§11, RD6/RD8).

### 6.4 RoadRunner entrypoint + config (Service B)

`apps/notification/bin/grpc.php` — a verbatim shape-copy of `bin/grpc.php`
(`bin/grpc.php:22-31`): build the container, `new Server(new Invoker(), ['debug' =>
false])`, `registerService(WelcomeEmailServiceInterface::class,
$container->get(WelcomeEmailGrpcService::class))`, `serve(Worker::create())`.

`apps/notification/.rr.grpc.yaml`:

```yaml
version: "3"
server:
  command: "php bin/grpc.php"
grpc:
  listen: "tcp://0.0.0.0:9002"
  proto:
    - "proto/notification/welcome/v1/welcome.proto"
```

> `proto/notification/welcome/v1/welcome.proto` must be present in the notification
> image at runtime (RoadRunner reads it to route). Because the notification build
> context is now the **repo root** (RD3: `context: .`, `dockerfile:
> apps/notification/Dockerfile`), the Dockerfile does `COPY proto ./proto` straight
> from the root context — one canonical `proto/` tree, no divergent second copy.

## 7. Service A — caller seam + deadline/retry (D5/NFR2)

### 7.1 The sibling outcome-returning port

**New Saga.Domain port** `src/Saga/Enrollment/Domain/SyncWelcomeEmailSender.php`:

```php
interface SyncWelcomeEmailSender
{
    /** Blocks for the outcome; throws SyncWelcomeSendException on transport exhaustion. */
    public function send(SendWelcomeEmail $message): WelcomeOutcome;   // App\Saga\…\HandleOutcome\WelcomeOutcome
}
```

It returns the **existing caller-side** `App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome`
enum (`sent|failed`) — the same one `HandleWelcomeEmailOutcomeCommand` already
carries. This is a **distinct** enum from the server-side
`App\Sending\Domain\WelcomeOutcome` that `SendWelcomeEmailHandler` publishes; the two
never share a class. The **wire** enum `Notification\Welcome\V1\Outcome` is mapped to
each enum **at its own boundary** (server: server disposition → wire `Outcome`;
caller adapter: wire `Outcome` → caller-side `HandleOutcome\WelcomeOutcome`) — RD9.
The async `WelcomeEmailRelay::publish(): void` port is **untouched** (FR7/FR12/DC1).
This keeps Saga.Domain's edge set unchanged (the new port references only existing
Saga.Domain/Application + Shared.Domain types).

### 7.2 The two sync adapters (Saga.Infrastructure)

- `src/Saga/Enrollment/Infrastructure/Rest/RestWelcomeEmailRelay.php` —
  `final readonly`, implements `SyncWelcomeEmailSender`, POSTs JSON to
  `WELCOME_EMAIL_REST_ENDPOINT` (e.g. `http://notification-svc:8081/internal/welcome-emails`)
  using the already-present **Guzzle** client (`guzzlehttp/guzzle` is in the monolith
  composer), parses `{outcome,error}`, returns `WelcomeOutcome`.
- `src/Saga/Enrollment/Infrastructure/Grpc/GrpcWelcomeEmailRelay.php` —
  `final readonly`, implements `SyncWelcomeEmailSender`, wraps the generated
  `Notification\Welcome\V1\WelcomeEmailServiceClient` (built on
  `WELCOME_EMAIL_GRPC_TARGET`, e.g. `notification-svc:9002`,
  `['credentials' => \Grpc\ChannelCredentials::createInsecure()]` — plaintext, N6),
  issues a real `UnaryCall` to `SendWelcomeEmail`, maps the response **wire** enum
  `Notification\Welcome\V1\Outcome` → the caller-side
  `HandleOutcome\WelcomeOutcome` (`OUTCOME_SENT` → `Sent`, `OUTCOME_FAILED` →
  `Failed`), and on a non-OK status applies the retry/deadline policy below — a
  transport `ABORTED` (gRPC) / `409` (REST) is **benign contention**: the adapter
  does **not** drive the saga and lets the next tick retry (RD6).

Both live in **`Saga.Infrastructure`** (already allowed to depend on Saga.Domain +
Saga.Application + Shared.*). The generated client/messages are under the new PSR-4
prefix, outside any deptrac-scanned layer (like `generated/`), so they add no edge.

### 7.3 Driving the saga from the sync path

The saga-worker's relay tick, when the transport is `rest`/`grpc`, uses a thin
**`RelayPendingWelcomeEmailsSync`** use-case (Saga.Application) that, per due saga,
applies the **drive-only-on-definitive-OK** rule (RD6): it advances the saga in-thread
**only** when the call returns a definitive OK outcome (SENT or FAILED); on
ABORTED/INVALID_ARGUMENT/INTERNAL/UNAVAILABLE/DEADLINE_EXCEEDED it leaves the saga
pending for the next tick (mirroring the async bounded-retry/sweeper). Concretely, per
due saga:
1. builds the `SendWelcomeEmail` via the existing `WelcomeEmailMessageFactory`;
2. calls `SyncWelcomeEmailSender::send()`. On a **definitive OK** it gets
   `sent|failed`. On benign contention (ABORTED/409) or transient/validation failure
   the adapter throws `SyncWelcomeSendException` (after the bounded retry budget is
   spent or immediately for a no-retry status) → **leave the saga
   `Started`/`AwaitingConfirmation` for the next tick**, exactly like a failed rabbit
   publish; **do not** drive the saga;
3. **only on a definitive OK** dispatches the **existing**
   `HandleWelcomeEmailOutcomeCommand($sagaId, $subscriptionId, $outcome)` through the
   in-house `CommandBus` — reusing `HandleWelcomeEmailOutcomeHandler` verbatim to
   confirm/cancel the subscription + advance the saga in one Postgres-A transaction
   (no new saga logic, FR7/AC4).

> **Why dispatch the existing command (not a new handler):** the reply-path
> orchestrator already does the paired conditional `UPDATE subscriptions … WHERE
> status='pending'` + saga transition idempotently for **both** `Sent` and `Failed`
> (`HandleWelcomeEmailOutcomeHandler.php:62-82` — the **proof** in §13). The sync path
> produces the *same* outcome the async reply would have carried, so it feeds the
> *same* command. The only difference is the outcome arrives **in-thread** instead of
> via a queue. Because the handler still publishes its async reply (RD4), the
> in-thread command and the later async reply both target the same saga row; the
> second to arrive is a proven no-op (records `welcome_reply_noop_total`, returns
> success) in **either** order, for **either** outcome (§13, RD5). The sweeper is
> unused on the sync path (the call either returns an outcome or throws), which is
> exactly the "lost never-hangs guarantee" the deadline below replaces.

### 7.4 Concrete deadline + retry/backoff (replaces broker buffer + sweeper)

Single source of truth in `config/settings.php` under a new `welcome_sync` block
(env-overridable), read by both sync adapters:

| Setting | Env | Default | Rationale |
|---|---|---|---|
| per-call deadline | `WELCOME_EMAIL_SYNC_DEADLINE_SECONDS` | **10** | A welcome send (claim + render + SMTP to MailHog) completes in well under 1s in dev; 10s absorbs a slow SMTP without letting the saga-worker tick stall. gRPC sets this as the call deadline (`['timeout' => 10_000_000]` µs); REST sets Guzzle `timeout`/`connect_timeout`. Far below the async `T=900s` sweep — the sync path fails fast and retries next tick. |
| max attempts | `WELCOME_EMAIL_SYNC_MAX_ATTEMPTS` | **3** | Mirrors the AMQP `MAX_REDELIVERIES=3` bound (HW9 §7) so the retry budget matches the async path. |
| backoff | `WELCOME_EMAIL_SYNC_BACKOFF_MS` | **200, 500, 1000** | Bounded exponential-ish backoff between attempts; total worst-case ≈ 1.7s + 3×10s deadline ≪ one worker tick budget. |
| retry-on | (code) | transport `UNAVAILABLE` (14), `DEADLINE_EXCEEDED` (4); REST: connect error / 502 / 503 / 504 | Idempotent retries are safe: the welcome ledger's `UNIQUE(subscription_id)` claim makes a re-sent `SendWelcomeEmail` a no-op (`AlreadySent` → still replies `sent`), so a retried call never double-sends (HW9 §6/§7). |
| no-retry-on | (code) | `INVALID_ARGUMENT` (3), `INTERNAL` (13), and a clean `FAILED` business outcome | A bad request or a deterministic failure won't improve on retry; `FAILED` is a *terminal business outcome*, returned (not retried) → compensate the saga. |

On exhausting attempts, the adapter throws `SyncWelcomeSendException` → the use-case
leaves the saga unadvanced for the next tick (bounded by the saga-worker loop, not a
broker). This is the explicit, documented replacement for the broker buffer + sweeper
on the sync paths (NFR2/R5/DC1).

### 7.5 Flag-driven DI selection (FR8) — relay binding only, SagaWorker unchanged (RD4)

**`SagaWorker` is not modified** (RD4). Its constructor still takes the *same*
`RelayPendingWelcomeEmails` relay-use-case dependency; the AMQP reply consumer +
sweeper keep running on **all** transports, and `tick()`→`relay->relay()` is
transport-agnostic. The migration swaps **only the relay binding** behind the
`WELCOME_EMAIL_TRANSPORT` flag — *the send call*, not the worker.

`config/settings.php` adds `'welcome_email' => ['transport' => $_ENV['WELCOME_EMAIL_TRANSPORT'] ?? 'rabbit', …]`.
`config/container.php` rebinds the relay use-case the worker already consumes:

```php
// Default rabbit (async, void) — the HW9 path, 100% intact (FR8/NFR1).
WelcomeEmailRelay::class => /* unchanged RabbitWelcomeEmailRelay binding */,

// The sync sender is bound by the flag; an absent/unknown value ⇒ rabbit means the
// sync sender is simply never resolved (the worker runs the async relay use-case).
SyncWelcomeEmailSender::class => static fn($c) => match ($settings['welcome_email']['transport']) {
    'grpc' => new GrpcWelcomeEmailRelay(/* target, deadline, retry policy, serializer, logger */),
    'rest' => new RestWelcomeEmailRelay(/* Guzzle client, endpoint, deadline, retry policy, serializer, logger */),
    default => throw new \LogicException('sync sender resolved while transport is rabbit'),
};

// SagaWorker is UNCHANGED: it always receives one RelayPendingWelcomeEmails. The flag
// only swaps WHICH relay use-case satisfies that binding (RD4) — no SagaWorker edit.
RelayPendingWelcomeEmails::class => static fn($c) =>
    $settings['welcome_email']['transport'] === 'rabbit'
        ? /* unchanged async RelayPendingWelcomeEmails (rabbit publish) */
        : $c->get(RelayPendingWelcomeEmailsSync::class),   // sync (new), wraps SyncWelcomeEmailSender + CommandBus
```

(If `RelayPendingWelcomeEmailsSync` is a distinct class, alias it onto the
`RelayPendingWelcomeEmails` constructor-typed dependency the worker consumes — the
worker code never changes, only the bound instance does.) All three transports are
**wired simultaneously** behind the flag; switching the env var switches the path with
**no SagaWorker change and no REST deletion** (FR11/AC4/AC8). "Bind interfaces only /
alias to share" (project-context) is honored: the flag selects the concrete in the
*factory*; the keys bound are the **ports** (`WelcomeEmailRelay`,
`SyncWelcomeEmailSender`) and the relay use-case the worker depends on. The AMQP loop,
reply consumer, and sweeper keep running on every transport; `saga-worker` keeps
`depends_on: rabbitmq` (RD4) — on sync paths no `SendWelcomeEmail` is published, and
the handler's async outcome reply is consumed by the still-running reply consumer as a
no-op (§13/RD5).

## 8. Sequence sketches (three transports)

**`grpc` / `rest` (opt-in, sync, caller-waits):**

```mermaid
sequenceDiagram
    participant W as saga-worker (relay tick)
    participant CL as Grpc/RestWelcomeEmailRelay (deadline+retry)
    participant SB as Service B surface (gRPC :9002 / REST :8081)
    participant H as SendWelcomeEmailHandler (UNCHANGED)
    participant DBB as Postgres B (welcome ledger)
    participant SMTP as MailHog
    participant BUS as CommandBus → HandleWelcomeEmailOutcomeHandler
    participant DBA as Postgres A (subscriptions + sagas)

    W->>CL: send(SendWelcomeEmail)   %% blocks (DC1)
    CL->>SB: SendWelcomeEmail(req)   %% deadline 10s, ≤3 attempts
    SB->>H: handle(WelcomeEmail)
    H->>DBB: claim(subscriptionId)
    H->>SMTP: send welcome
    H->>DBB: markSent
    SB-->>CL: SendWelcomeEmailResponse{outcome=OUTCOME_SENT}  (OK)
    CL-->>W: HandleOutcome\WelcomeOutcome::Sent
    W->>BUS: dispatch HandleWelcomeEmailOutcomeCommand(sagaId, subId, Sent)  %% only on definitive OK(SENT|FAILED), RD6
    BUS->>DBA: confirm subscription + complete saga (one tx, idempotent — §13/RD5)
    Note over SB,BUS: ABORTED/INVALID_ARGUMENT/INTERNAL/UNAVAILABLE/DEADLINE → ExceptionStatusMap (D7); caller leaves saga pending, retries next tick (RD6)
```

**`rabbit` (DEFAULT, async, unchanged HW9):** identical to HW9 architecture.md §8 —
`RelayPendingWelcomeEmails` publishes fire-and-forget, the notification consumer
replies on `notifications.welcome-email-reply`, `WelcomeEmailOutcomeConsumer`
dispatches the same `HandleWelcomeEmailOutcomeCommand`, and `SweepTimedOutSagas` is
the never-hangs backstop. **No diff** (FR12/NFR1/AC8).

## 9. Deptrac & quality-gate isolation (D8)

**Monolith `deptrac.yaml` — no new layer, no new edge, baseline stays `{}`:**
- `App\Saga\Enrollment\Infrastructure\Rest\RestWelcomeEmailRelay`,
  `App\Saga\Enrollment\Infrastructure\Grpc\GrpcWelcomeEmailRelay` →
  `Saga.Infrastructure` (existing layer; allowed `→ Saga.Application, Saga.Domain,
  Shared.*`). They reference the new `SyncWelcomeEmailSender` (Saga.Domain) +
  `SendWelcomeEmail` / the caller-side `HandleOutcome\WelcomeOutcome` (existing) — all
  already-allowed edges. The wire enum `Notification\Welcome\V1\Outcome` lives in
  `gen/` (unscanned).
- `RelayPendingWelcomeEmailsSync` → `Saga.Application` (existing; allowed `→
  Saga.Domain, Shared.*`). It dispatches `HandleWelcomeEmailOutcomeCommand` via the
  Shared `CommandBus` — an existing Shared.Application edge.
- The generated client stub + messages (`Notification\Welcome\V1\*`,
  `GPBMetadata\Proto\Notification\*`) live in `gen/`, **outside** `./src` and
  `./apps` (deptrac `paths:`), so deptrac never scans them — exactly like the
  committed `generated/Grpc/*` is unscanned today (onboarding §6).

**Notification `apps/notification/deptrac.yaml` — no new layer, no new edge, baseline `{}`:**
- `App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService`,
  `App\Sending\Infrastructure\Http\WelcomeEmailController`, `WelcomeEmailFactory` →
  `Notification.Infrastructure` (existing; allowed `→ Notification.Domain,
  Notification.Application, Shared`). They depend on
  `App\Sending\Application\SendWelcomeEmailHandler` (Application) + the generated
  server interface/messages (in `gen/`, unscanned).
- `toGrpcStatus()` lives in the existing
  `App\Sending\Infrastructure\Error\ExceptionStatusMap` — same layer, no boundary
  touched.

**Generated-code exclusion (NFR4/NFR5):**
- **phpcs:** add `gen/` to `<exclude-pattern>` in both `phpcs.xml`/`.phpcs.xml.dist`
  exactly as `generated/` is excluded today (onboarding §6: "phpcs excludes `generated/`").
- **psalm (RD7 correction):** the monolith now **uses** generated client-stub +
  `\Grpc\*` symbols from `src/`, so psalm must *resolve* the `gen/` classes without
  *analyzing* them. In the **root** `psalm.xml`, add `<directory name="gen"/>` to
  `<projectFiles>` **and** `<ignoreFiles><directory name="gen"/></ignoreFiles>`
  (resolve symbols, skip analysis of generated code) — `grpc/grpc` in root composer
  supplies the `\Grpc\BaseStub`/`ChannelCredentials`/`UnaryCall` symbols (RD7).
  Apply the same `projectFiles`+`ignoreFiles` pairing to the notification `psalm.xml`
  if its autoload pulls `gen/` into scope. (Targeted `@psalm-suppress` on
  generated-class usages is an acceptable alternative.) This **supersedes** any
  earlier claim that psalm needs "no change".
- **deptrac:** unscanned (outside `paths:`), as above.

## 10. Deployment / runtime (D6/NFR6)

**Service B gRPC = second supervised process in the notification container.**
`apps/notification/bin/start.sh` becomes:

```sh
#!/bin/sh
set -e
php bin/migrate.php
php -S 0.0.0.0:8081 http/server.php &        # health/metrics + NEW REST baseline (FR3)
rr serve -c .rr.grpc.yaml &                  # NEW gRPC server on :9002 (FR4)
exec php bin/consumer.php                     # AMQP consumer (HW9, unchanged)
```

> **Forked-`rr` supervision risk (RD10f).** Under `set -e` the forked `rr serve` (and
> the forked `php -S`) run **unsupervised**: if `rr` exits, only `bin/consumer.php`
> (the `exec`'d PID 1) keeps the container alive, so a dead gRPC server would not by
> itself crash-restart the container, and the existing `/health` probe covers `:8081`
> only — not `:9002`. ADR-0004 records this as an **accepted** risk (a `wait -n`
> watchdog or a separate `notification-grpc` service is the optional future
> mitigation, kept out of scope per N6).

`docker-compose.yml` `notification-svc` moves its **build context to the repo root**
so the image can reach `proto/` and `gen/` (RD3), publishes the new port, and gets the
transport-target env (the monolith side reads the target):

```yaml
notification-svc:
  build:
    context: .                                 # CHANGED: repo root, so COPY can reach proto/ + gen/ (RD3)
    dockerfile: apps/notification/Dockerfile
  # …existing…
  ports:
    - "${NOTIFICATION_SVC_PORT:-8081}:8081"
    - "${NOTIFICATION_GRPC_PORT:-9002}:9002"   # NEW plaintext gRPC (N6)
```

The monolith `saga-worker` service gets the flag + endpoints:

```yaml
saga-worker:
  # …existing…
  environment:
    WELCOME_EMAIL_TRANSPORT: ${WELCOME_EMAIL_TRANSPORT:-rabbit}   # default rabbit (NFR1)
    WELCOME_EMAIL_REST_ENDPOINT: http://notification-svc:8081/internal/welcome-emails
    WELCOME_EMAIL_GRPC_TARGET: notification-svc:9002
```

**Image changes (§2 + RD2/RD3/RD7):** the **root** `Dockerfile` adds `ext-grpc` (+
optional `ext-protobuf`) and a **pinned** `buf` (plus the pinned offline-fallback
`grpc_php_plugin` + `protoc-gen-php` shim) for codegen, and `grpc/grpc` lands in the
root `composer.json` (RD7).

The **notification** `Dockerfile` is rewritten for the new **repo-root build context**
(RD3): all COPYs are now root-relative, the app subtree is copied explicitly, and
`proto/` + `gen/` are copied from the root context:

```dockerfile
# Build context is the repo root (docker-compose: context: .).
COPY apps/notification/composer.json apps/notification/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY apps/notification/src       src/
COPY apps/notification/bin       bin/
COPY apps/notification/config    config/
COPY apps/notification/migrations migrations/
COPY apps/notification/http      http/
COPY proto                       ./proto      # NEW — RoadRunner reads it at runtime (RD3)
COPY gen                         ./gen        # NEW — server interface + messages (RD3)
```

It also adds `spiral/roadrunner-grpc`+`google/protobuf` (composer) and the `rr`
binary. To keep the now-root build context lean, **`.dockerignore`** excludes
`vendor/`, `apps/notification/vendor/`, `.git`, and test caches (RD3).

Healthcheck for the gRPC server: the `rr serve` process is supervised by the container
loop (forked, see the supervision-risk note above); an optional
`grpcurl`/`grpc_health_probe` is **out of scope** (N6) — liveness rides the container
`restart: unless-stopped` + the existing `/health` HTTP probe on `:8081` (the gRPC
server shares the container's fate).

**Codegen Make targets (D8):**

```make
buf-lint: install   ## buf lint the NEW welcome proto (gate — fully offline)
	$(COMPOSE) run --rm --no-deps app buf lint

buf-generate: install   ## Regenerate gen/ from the new proto (NOT generated/); reaches buf.build for the remote message+client plugins — offline fallback uses the pinned grpc_php_plugin + protoc-gen-php shim (RD2)
	$(COMPOSE) run --rm --no-deps -v "$(PWD):/app" app sh -c "buf generate && chown -R $(HOST_UID):$(HOST_GID) gen"
```

`make buf-lint` is the **offline** CI gate (zero STANDARD findings, RD1); `make
buf-generate` is the codegen step that proves all three outputs land in `gen/` in the
built image (Story 1.3, RD2). `make proto` (legacy raw protoc, `Makefile:129-130`) is
untouched (N7).

## 11. Tests (FR9/AC6) + benchmark (FR10/AC9★)

**gRPC server in-process test (notification — copies `tests/Grpc/ReleaseNotifierServiceTest.php`; RD8(b)):**
`apps/notification/tests/Unit/Sending/Infrastructure/Grpc/WelcomeEmailGrpcServiceTest.php`
— wire the gRPC server class → handler, mock `SendWelcomeEmailHandler` +
`ContextInterface`, **real** `App\Sending\Infrastructure\Error\ExceptionStatusMap`:
- happy path → `SendWelcomeEmailResponse{outcome=OUTCOME_SENT}` (AC6 happy);
- handler throws `WelcomeAlreadyFailedException` → `{outcome=OUTCOME_FAILED}` (business);
- handler throws `WelcomeInFlightException` → assert `GRPCException` with
  `StatusCode::ABORTED` (RD6 — benign contention, **not** UNAVAILABLE);
- handler throws `WelcomeRequestValidationException` → assert `GRPCException` with
  `StatusCode::INVALID_ARGUMENT` (AC6 error-case — correct gRPC status);
- handler throws a plain `\RuntimeException` (SMTP down) → assert `StatusCode::UNAVAILABLE`.

**REST baseline (notification):**
`apps/notification/tests/Unit/Sending/Infrastructure/Http/WelcomeEmailControllerTest.php`
— same dispositions over the Slim request/response, asserting HTTP 200/`sent`,
200/`failed`, **409** for `WelcomeInFlightException` (RD6), and 400/503 via
`ExceptionStatusMap::toHttpStatus()`.

**Client adapters (monolith) — prove the client really calls B (RD8(a)):**
- `tests/Unit/Saga/Enrollment/Infrastructure/Grpc/GrpcWelcomeEmailRelayTest.php` —
  **mock the generated `Notification\Welcome\V1\WelcomeEmailServiceClient` stub** and
  assert the relay issues a real `UnaryCall` with the correctly-mapped
  `SendWelcomeEmailRequest` (saga_id, int64 subscription_id, email, repository), maps
  the response wire `Outcome` → the caller-side `HandleOutcome\WelcomeOutcome`, retries
  on `UNAVAILABLE`/`DEADLINE_EXCEEDED`, gives up after 3, does **not** retry on
  `INVALID_ARGUMENT`, and treats `ABORTED` as benign (leaves the saga undriven).
- `tests/Unit/Saga/Enrollment/Infrastructure/Rest/RestWelcomeEmailRelayTest.php` — a
  Guzzle `MockHandler`, asserting the same outcome mapping + retry/deadline policy (409
  benign, 502/503/504 retried, 400 not retried).

> AC3 e2e wire is proven by (a) the `GrpcWelcomeEmailRelay` UnaryCall unit test + (b)
> the in-process gRPC server test (OUTCOME_SENT + one error status) + the k6 run
> (Story 5.2) — RD8.

All ride the existing PHPUnit `Unit` suites (no docker), picked up by `make test`
(monolith) / `make notification-unit` (Service B), which are the existing CI rows.

**Benchmark (FR10/AC9★ — k6, confirmed-with-user before booting, NFR6):**
one k6 script `bench/welcome-email.js` with two scenarios — HTTP `POST
/internal/welcome-emails` (REST) and `k6/net/grpc` calling `SendWelcomeEmail` against
`:9002` (loading `proto/notification/welcome/v1/welcome.proto`). The k6 harness is
committed unconditionally (**AC9a**); the req/s + p50/p95/p99 table in the README is
gated on a stack boot confirmed with the user (**AC9b**, RD10b). Records the rationale
(HTTP/2 multiplexing, binary protobuf vs text JSON, no per-request schema reparse).
Tool is **k6**, not ghz/autocannon (DC5).

## 12. Migration strategy (dependency-ordered, gates green each phase)

| Phase | Deliverable | Keeps green |
|---|---|---|
| **P0** | Tooling: pinned `buf.yaml`/`buf.gen.yaml`; root Dockerfile += `ext-grpc`(+`protobuf`)+pinned `buf`(+offline-fallback `grpc_php_plugin`+`protoc-gen-php` shim)+`grpc/grpc` in root composer; notification Dockerfile rewritten for **repo-root context** (RD3) += `spiral/roadrunner-grpc`+`google/protobuf`+`rr`+`COPY apps/notification/{…}`+`COPY proto`+`COPY gen`; compose `notification-svc` `context: .`; `.dockerignore` updated. No app code. | builds; `make buf-lint` (no proto yet = noop or add proto in P1) |
| **P1** | `proto/notification/welcome/v1/welcome.proto` (§5, RD1); `make buf-generate` → `gen/` (3 plugin outputs); **prove-in-image** (Story 1.3, RD2) — run codegen in the built image and confirm all three outputs land in `gen/`; PSR-4 in both composer.json; `gen/` excluded from phpcs and added to psalm `projectFiles`+`ignoreFiles` (D8/§9/RD7). | buf lint clean (zero STANDARD findings); lint (gen excluded); psalm |
| **P2** | Notification `WelcomeEmailFactory` + `WelcomeRequestValidationException`; `toGrpcStatus()` + `toHttpStatus()` 400 arm on the notification `ExceptionStatusMap` (D7). Unit tests. | notification lint/deptrac/psalm/unit |
| **P3** | Notification REST baseline: `WelcomeEmailController` + route (FR3); controller test. | notification gates |
| **P4** | Notification gRPC server: `WelcomeEmailGrpcService` + `bin/grpc.php` + `.rr.grpc.yaml` + DI; server unit test (happy + error-status, AC6). | notification gates |
| **P5** | Monolith client seam: `SyncWelcomeEmailSender` port; `RestWelcomeEmailRelay`, `GrpcWelcomeEmailRelay` (deadline+retry, §7); `RelayPendingWelcomeEmailsSync`; adapter tests. | monolith lint/deptrac/psalm/unit |
| **P6** | Flag wiring: `WELCOME_EMAIL_TRANSPORT` in settings + container **relay-binding** swap (FR8) — **no `SagaWorker` code change** (RD4); compose env + `:9002` + `start.sh` fork (D6, with the forked-`rr` risk noted). | monolith gates; compose smoke |
| **P7** | CI gate: `grpc.yml` whose `name:` is **byte-identical** to its `.github/required-pr-checks.txt` row (RD10e; e.g. `name: gRPC contract (buf lint)`), verified after the first PR run; `make buf-lint`/`buf-generate` (D8). | CI green |
| **P8** | ★ k6 `bench/welcome-email.js` + README REST-vs-gRPC table (FR10/AC9) — **after** confirming the stack boot with the user (NFR6). | README; (bench run gated on user) |
| **P9** | Docs: ADR-0004 + LikeC4 sync (AC11) — the new proto/package, gen dir, Service B gRPC+REST surface, Service A client adapters + flag, the status mapping, the client-runtime decision, the deadline/retry policy. | docs |

P0–P1 stand up tooling + contract; P2–P4 the server side (REST then gRPC); P5–P6 the
client side + flag; P7 the gate; P8–P9 benchmark + docs. Each phase is independently
shippable behind the existing gates with the flag at default `rabbit` (HW9 intact).

## 13. Open decisions / tensions

- **`gen/` per-service copy.** The monolith uses the **client** stub + messages; the
  notification app uses the **server** interface + messages. Both autoload the same
  `Notification\Welcome\V1\` prefix from `gen/`. Decision: commit one `gen/` at repo
  root; the notification build `COPY gen/ gen/`'s it (it ignores the unused client
  stub). Tension: the unused stub ships in the notification image — harmless dead
  code (not deptrac-scanned), simpler than a per-service buf module. Rejected: two
  proto modules / two gen dirs (codegen duplication).
- **Handler still publishes an AMQP reply on the sync path — double-signal is PROVEN
  idempotent for BOTH outcomes (RD5).** Reusing `SendWelcomeEmailHandler` unchanged
  means it still publishes `WelcomeEmailOutcome/v1` even when invoked over gRPC/REST,
  so on a sync path the saga row receives **two** signals for the same outcome: the
  in-thread `HandleWelcomeEmailOutcomeCommand` (§7.3) and the later async reply
  consumed by the still-running reply consumer (RD4). **Proof of safety** —
  `HandleWelcomeEmailOutcomeHandler::__invoke()` wraps **one** Postgres-A transaction
  (`HandleWelcomeEmailOutcomeHandler.php:62-73`) applying the paired **conditional**
  UPDATEs for each branch:
  - `WelcomeOutcome::Sent` → `subscriptionWriter->confirm($subscriptionId)` +
    `sagaWriter->complete($sagaId)` (lines 64-67);
  - `WelcomeOutcome::Failed` → `subscriptionWriter->cancel($subscriptionId)` +
    `sagaWriter->compensate($sagaId)` (lines 68-71).

  Each writer's UPDATE is gated on the pre-transition status, so once the row has left
  `pending` **both** UPDATEs return `rowCount()=0`; `applyTransition()` then reports
  `changed=false`, the handler records `welcome_reply_noop_total`
  (`recordWelcomeReplyNoop()`, lines 79-81) and returns success (no domain event,
  since `sagaChanged=false` at lines 75-77). ∴ re-applying `Sent` **or** `Failed` after
  the state leaves `pending` is a guaranteed no-op, in **either** arrival order. The
  sync command and the async reply are therefore both safe for SENT and FAILED; the
  handler stays unchanged (FR5/DC6). A double-apply idempotency test (Story 4.x)
  asserts the second signal is a counted no-op for both outcomes. This is the price of
  DC6 (frozen server logic) — and it is paid in full by the conditional-UPDATE guard,
  not merely "noted".
- **Deadline vs sweeper.** `10s × 3` on the sync path is intentionally **far** below
  the async `T=900s` — the sync path fails fast and lets the next worker tick retry,
  whereas the async path tolerates a 15-minute broker outage. Both converge on the
  same idempotent command. Documented as the DC1 trade-off.
- **`ABORTED` is reachable; `ALREADY_EXISTS` stays reserved (RD6).** `ABORTED` (gRPC)
  / `409` (REST) is the **live** mapping for `WelcomeInFlightException` — benign
  concurrent-lease contention; the caller leaves the saga pending and retries next
  tick (§6.3/§7.3). `ALREADY_EXISTS`, by contrast, stays a **reserved** mapping: the
  current handler surfaces a duplicate as a silent dedup (`AlreadySent` → returns
  `SENT`), not a conflict status, so that row is exercised only if a future handler
  raises an explicit idempotency-conflict. ADR-0004 records this reachable-vs-reserved
  split (RD10f). The `401/403` auth rows are likewise reserved (no auth in v1, §6.2).

## 14. Reference & decision record

- **Reference pattern:** the monolith's existing gRPC stack is copied wholesale —
  `proto/release_notifier.proto` (versioned package + `php_namespace`), `bin/grpc.php`
  (Spiral `Server`/`Invoker`/`Worker`), `.rr.grpc.yaml` (plaintext listen),
  `src/Grpc/ReleaseNotifierService.php` (`mapException()` via `ExceptionStatusMap`),
  and the in-process `tests/Grpc/` harness (mocked `ContextInterface`, real status
  map). The welcome RPC is the same shape in a new package.
- **Adopted:** buf greenfield into an isolated `gen/` with distinct PSR-4 (DC3/NFR4);
  the official PHP gRPC **client** with `ext-grpc` confined to the monolith image
  (D1/R3 resolution — server stays runtime-pure on RoadRunner); a sibling
  outcome-returning `SyncWelcomeEmailSender` port that reuses the existing
  `HandleWelcomeEmailOutcomeCommand` so the saga state machine is unchanged (D5);
  one `ExceptionStatusMap` per side as the single status source, gaining a
  `toGrpcStatus()` arm (D7); a second supervised RoadRunner process in the
  notification container (D6); both deptrac baselines stay `{}` by reusing existing
  Infrastructure layers (D8).
- **Adapted (not copied):** the gRPC **client** stub (Spiral emits server-only, so the
  remote `buf.build/grpc/php` plugin supplies the client — offline fallback: a pinned
  `grpc_php_plugin` binary, D1/RD2); the notification `ExceptionStatusMap` (HTTP-only
  today) gains the gRPC arm (with the InFlight→ABORTED arm, RD6); `start.sh` forks a
  **third** process.
- **Rejected:** a hand-written HTTP/2 client (D1b); a standalone `protoc-gen-php`
  binary (none exists — `protoc --php_out` builtin, RD2); editing the legacy proto /
  regenerating `generated/` (N2/N7); widening the async `void` relay port to return an
  outcome (D5); rewiring `SagaWorker` (RD4 — only the relay binding swaps); a separate
  `notification-grpc` compose service (D6); a new deptrac layer for `gen/` (D8 —
  generated code is unscanned). TLS/mTLS, streaming, auth, a third DB are out of scope
  (N6). Note: `buf generate` **does** use remote plugins (1)+(3) — an accepted
  codegen-time network dependency with a pinned offline fallback (RD2); only `buf lint`
  is fully offline.
- **ADR follow-on (RD10f):** `docs/adr/0004-rest-to-grpc-welcome-email.md` records this
  decision and **must** capture three readiness-resolved points:
  1. **Introduced-REST-baseline rationale** — the "REST baseline" is *introduced* by
     this migration, **not** pre-existing (there was no synchronous inter-service REST
     call before — all monolith↔notification traffic was async RabbitMQ). User-confirmed;
     stated here, in the README, and in the ADR.
  2. **Reachable-vs-reserved status codes** — `OUTCOME_SENT`/`OUTCOME_FAILED` (OK
     responses), `INVALID_ARGUMENT`/400, `ABORTED`/409 (InFlight), `INTERNAL`/500,
     transport `UNAVAILABLE`/`DEADLINE_EXCEEDED` are **reachable**; `ALREADY_EXISTS`
     and the `401/403` auth rows are **reserved** (§13).
  3. **Unsupervised forked `rr` risk** — under `start.sh`'s `set -e`, the forked
     `rr serve` (`:9002`) is unsupervised and the `/health` probe covers `:8081`
     only; the risk is **accepted** (watchdog/separate service optional, N6).

  Context: no sync REST call existed; Decision: opt-in sync twins behind a flag over
  the unchanged handler, official gRPC client with `ext-grpc` in the monolith image,
  buf into `gen/`; Alternatives: hand-written client / extend the void port / separate
  compose service; Consequences: caller-waits semantic shift on opt-in paths,
  deadline+retry replaces the sweeper, both baselines stay `{}`. Continues the
  `0001`–`0003` series.
- **LikeC4 sync (AC11):** add the new gRPC + REST surface on `notification-svc`
  (:9002 / :8081 `/internal/welcome-emails`), the `WELCOME_EMAIL_TRANSPORT` flag on
  `saga-worker`, and the two A→B sync edges to `docs/architecture/` via the
  `likec4-architecture-sync` skill.
```