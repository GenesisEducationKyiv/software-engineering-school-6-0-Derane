---
artifact: implementation-readiness-report
project: github-release-notifier
date: '2026-06-23'
status: GO-WITH-FIXES → fixes resolved below (apply to specs before P0)
related:
  - specs/rest-to-grpc-migration/prd.md
  - specs/rest-to-grpc-migration/architecture.md
  - specs/rest-to-grpc-migration/epics.md
  - specs/rest-to-grpc-migration/onboarding.md
---

# Implementation-Readiness Report — REST → gRPC Welcome-Email Migration

**Verdict: GO-WITH-FIXES.** Produced by an independent 5-lens review (lenses B/C/E on Claude Sonnet, A/D on Opus, to avoid self-confirmation). The planning bundle is thorough and fully AC-mapped, but contained **six code-verified blockers** plus several majors that would defeat AC2/AC3/AC9 at implementation. All are localized spec corrections, not a re-plan. Each is **resolved** in §Resolution Decisions below (grounded in the real code, re-verified by the orchestrator) and must be applied to the specs before story P0.

## Per-lens summary

- **bmad-readiness-gate (not-ready):** buf STANDARD lint failure, SagaWorker RabbitMQ hard-wiring, handler publishFailed-then-throw double-signal. All upheld.
- **feasibility-adversarial / Sonnet (ready-with-fixes):** `protoc-gen-php` and `grpc_php_plugin` both absent; notification build-context COPY impossibility. Upheld as blockers.
- **traceability-completeness / Sonnet (ready-with-fixes):** no story owns a real A→B client wire test; AC9 can sit "blocked" forever. Upheld.
- **assignment-compliance (ready-with-fixes):** in-process-test-vs-"really calls it" gap; unreal `protoc-gen-php`; unmapped InFlight; namespace drift. Upheld.
- **conventions-regression-risk / Sonnet (ready-with-fixes):** InFlight→UNAVAILABLE retry mismatch; SagaWorker broker dependency; codegen install gaps. Upheld.

## Deduped findings by severity

| Sev | Area | Issue (verified) | Resolution |
|---|---|---|---|
| Blocker | buf lint / AC2 | enum `SENT`/`FAILED` lack `OUTCOME_` prefix (ENUM_VALUE_PREFIX); file path ≠ package dir (PACKAGE_DIRECTORY_MATCH) → 3 STANDARD findings vs AC2 "zero" | RD1 |
| Blocker | buf codegen | `protoc-gen-php` (messages are protoc-builtin, Makefile:130) + `grpc_php_plugin` both absent; no install step | RD2 |
| Blocker | Docker context | notification context `./apps/notification` can't COPY repo-root `proto/`/`gen/`; RoadRunner needs proto at runtime | RD3 |
| Blocker | Caller runtime | `SagaWorker::run()` unconditionally opens AMQP + reply consumer + `exit(1)` on AMQP loss | RD4 |
| Blocker | Saga semantics | handler `publishFailed()` then throws AlreadyFailed → sync path drives saga twice | RD5 (proven idempotent) |
| Major | Status map | `WelcomeInFlightException` (extends RuntimeException) unmapped → UNAVAILABLE → client retries lock contention; catch-order load-bearing | RD6 |
| Major | Test coverage | gRPC test is in-process only; AC3 claims "end-to-end by the gRPC client"; no story drives the generated client | RD8 |
| Major | psalm/deps | `grpc/grpc` absent from both composer.json; `\Grpc\BaseStub` unstubbed; gen/+`\Grpc` refs from src/ fail at errorLevel 1 | RD7 |
| Major | Spec drift | specs say `Notification\Sending`+`http/index.php`; real is `App\Sending`; two `WelcomeOutcome` enums | RD9 |
| Major | Tooling proof | buf/grpc_php_plugin/protoc-gen-php absence makes AC2 unproven with no fallback | RD2 |
| Minor | proto types | `int32 subscription_id` truncation risk | RD10a (→ int64) |
| Minor | AC9 | Story 5.2 can stay "blocked" indefinitely → ★ silently forfeited | RD10b (split AC9a/AC9b) |
| Minor | NFR8 | PRD says sync paths "emit counters"; epics say "reserved" — contradiction | RD10c |
| Minor | AC5 regression | No AC asserts the REST route survives the 2.3 gRPC wiring | RD10d |
| Nit | CI | `grpc.yml` job `name:` must be byte-identical to the required-pr-checks row | RD10e |
| Nit | start.sh | forked `rr` unsupervised under `set -e`; /health probes :8081 only | RD10f (ADR note) |
| Minor | REST framing | "REST baseline" is introduced, not pre-existing | User-confirmed; state in README/ADR (RD10f) |

## AC coverage confirmation

All AC1–AC11 map to ≥1 story (verified against the epics Validation Summary): AC1→1.1, AC2→1.2/1.3, AC3→2.3/4.1, AC4→3.4, AC5→2.2/4.2, AC6→4.1, AC7→4.3, AC8→3.4, AC9→5.1/5.2, AC10→4.3, AC11→5.3. Structurally complete; AC2/AC3/AC9 were only *nominally* covered until the RDs below.

---

# Resolution Decisions (AUTHORITATIVE — apply to prd.md / architecture.md / epics.md before P0)

Each RD is the binding decision; spec edits must conform exactly. Code citations are orchestrator-verified against the live tree on 2026-06-23.

### RD1 — buf-lint-clean proto (Blocker)
- New proto path: **`proto/notification/welcome/v1/welcome.proto`** (satisfies PACKAGE_DIRECTORY_MATCH with `buf.yaml` `modules: - path: proto`).
- Enum: `enum Outcome { OUTCOME_UNSPECIFIED = 0; OUTCOME_SENT = 1; OUTCOME_FAILED = 2; }` (satisfies ENUM_VALUE_PREFIX).
- `int64 subscription_id` (RD10a). `option php_namespace = "Notification\\Welcome\\V1";` (greenfield; no collision with `Grpc\` → `generated/Grpc/`).
- AC2 stays "buf lint reports zero findings under STANDARD" — now true.

### RD2 — real, proven codegen (Blocker + Major "tooling proof")
- `buf.gen.yaml` plugins → out `gen`: (1) messages = **remote `buf.build/protocolbuffers/php`**; (2) server interface = **local vendored Spiral** `tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc`; (3) client stub = **remote `buf.build/grpc/php`**.
- `buf lint` is fully **offline** (the hard AC). `buf generate` needs network for the two remote plugins (acceptable codegen-time dependency). **Offline fallback** documented: install a pinned `grpc_php_plugin` binary + a `protoc-gen-php` shim wrapping `protoc --php_out`. **Pin** `buf` + plugin versions.
- Install `buf` in the **root** Dockerfile. **Story 1.3 is a P0 "prove-in-image" gate**: actually run `make buf-lint` + `make buf-generate` and confirm all three outputs land in `gen/` before any app code depends on them.

### RD3 — notification image can reach proto/gen (Blocker)
- `docker-compose.yml` `notification-svc.build`: **`context: .`**, `dockerfile: apps/notification/Dockerfile`.
- Rewrite Dockerfile COPYs: `COPY apps/notification/{src,bin,config,migrations,http} ...` + `COPY proto ./proto` + `COPY gen ./gen`.
- Update `.dockerignore` to exclude `vendor/`, `apps/notification/vendor/`, `.git`, test caches so the root context stays lean.
- `apps/notification/.rr.grpc.yaml` proto path = `proto/notification/welcome/v1/welcome.proto`.

### RD4 — SagaWorker runs on all transports without refactor (Blocker)
- **No SagaWorker code change.** `tick()`→`relay->relay()` and the AMQP reply-loop are transport-agnostic. The migration swaps only the **relay implementation** (`RabbitWelcomeEmailRelay` ↔ `Rest`/`Grpc` adapter) via the DI flag.
- The AMQP reply consumer + sweeper keep running on **all** transports; `saga-worker` keeps `depends_on: rabbitmq`. Document explicitly: we migrate the **send** call (relay→notification) from AMQP-publish to gRPC/REST; the **reply leg + sweeper stay AMQP** (one call migrated, per the assignment). On sync paths no `SendWelcomeEmail` is published; the handler's async outcome reply is consumed by the running reply consumer as a no-op (RD5).

### RD5 — double-signal is idempotent for BOTH branches (Blocker) — PROVEN
- Evidence: `src/Saga/Enrollment/Application/HandleOutcome/HandleWelcomeEmailOutcomeHandler.php:62-82` applies conditional UPDATEs (`subscriptionWriter->confirm|cancel` + `sagaWriter->complete|compensate`); docblock+code (lines 30-34, 79-81) confirm that when both UPDATEs are no-ops it records `welcome_reply_noop_total` and returns success. ∴ re-applying `Sent` **or** `Failed` after the state leaves `pending` is a guaranteed no-op, in either order.
- ∴ the sync path's in-thread `HandleWelcomeEmailOutcomeCommand` + the handler's still-published async reply (consumed by the running reply consumer) are both safe for SENT and FAILED. Handler stays unchanged (FR5). Add a double-apply idempotency test (Story 4.x). Strengthen architecture §13 with this evidence.

### RD6 — transport-adapter outcome derivation + catch order + InFlight (Major)
The NEW Service B transport adapter (gRPC server class + REST controller) — **not** the handler — wraps `SendWelcomeEmailHandler::handle()` (which is `void` and throws). Map with this **exact catch order** (load-bearing: `WelcomeAlreadyFailedException` and `WelcomeInFlightException` both extend `\RuntimeException`):
1. normal return → `Outcome = OUTCOME_SENT` (covers Claimed-markSent, AlreadySent dedup, fenced).
2. `WelcomeAlreadyFailedException` → response `Outcome = OUTCOME_FAILED` (a normal OK response; drives saga compensate).
3. `WelcomeInFlightException` → gRPC **ABORTED** / REST **409** (benign contention; caller leaves saga pending, retries next tick — do NOT drive saga).
4. `ValidationException`/`InvalidArgumentException` → **INVALID_ARGUMENT** / 400.
5. `\Throwable` (rethrown send failure) → **INTERNAL** / 500 (transient; caller leaves saga pending, next relay tick retries).

Sync relay drives the saga in-thread **only** on a definitive OK(SENT|FAILED); on ABORTED/INVALID_ARGUMENT/INTERNAL/UNAVAILABLE/DEADLINE_EXCEEDED it leaves the saga pending for the next tick (mirrors async bounded-retry/sweeper). Add a `WelcomeInFlightException` arm to the notification `ExceptionStatusMap::toGrpcStatus()`/`toHttpStatus()`; add an InFlight test asserting ABORTED; document the catch order.

### RD7 — grpc/grpc dependency + psalm (Major)
- Add `grpc/grpc: ^1.x` to **root** `composer.json` `require` (provides `\Grpc\BaseStub`/`ChannelCredentials`/`UnaryCall` for runtime + psalm symbol resolution).
- Add PECL **`ext-grpc`** to the **root** Dockerfile (`install-php-extensions grpc`) — client image only; Service B keeps RoadRunner (no `ext-grpc`).
- psalm (root `psalm.xml`): add `<directory name="gen"/>` to projectFiles **with** `<ignoreFiles><directory name="gen"/></ignoreFiles>` (resolve symbols, don't analyze generated code), or targeted `@psalm-suppress` on generated-class usages. Correct architecture §9 (it claimed "no psalm change").

### RD8 — prove the client really calls B (Major)
- Add (a) a `GrpcWelcomeEmailRelay` **unit test** mocking the generated `*Client` stub, asserting it issues a real `UnaryCall` with the correctly-mapped request; (b) an **in-process server test** wiring the gRPC server class → handler asserting OUTCOME_SENT + one error status (ABORTED or INVALID_ARGUMENT).
- Reword AC3: e2e wire proven by (a) + (b) + the k6 run (Story 5.2).

### RD9 — fix namespace/path/enum drift (Major) — GROUND-TRUTH CONFIRMED
- apps/notification PSR-4 = **`App\` → src/** (`apps/notification/composer.json:22-24`).
- Hand-written Service B code: handler `App\Sending\Application\SendWelcomeEmailHandler`; new gRPC server `App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService`; new REST controller `App\Sending\Infrastructure\Http\WelcomeEmailController` (sibling of `HealthController`/`MetricsController`); error map `App\Sending\Infrastructure\Error\ExceptionStatusMap`.
- REST route registered in **`apps/notification/http/index.php`** (where `/health`, `/metrics` are added; `index.php` returns the Slim app, `server.php` runs it).
- Two enums, disambiguated: server-side `App\Sending\Domain\WelcomeOutcome` (handler publishes) vs caller-side `App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome` (the `HandleWelcomeEmailOutcomeCommand` carries). The wire enum `Notification\Welcome\V1\Outcome` maps to each at its boundary (server: disposition→wire; caller: wire→`HandleOutcome\WelcomeOutcome`).
- The buf prefix `Notification\Welcome\V1` is fine (greenfield, no collision).

### RD10 — minors/nits
- (a) `int64 subscription_id` in the proto (done in RD1).
- (b) Split AC9 → **AC9a** (k6 harness committed, unconditional) + **AC9b** (req/s + p50/p95/p99 in README, gated on stack boot).
- (c) NFR8: reword PRD to "MAY emit per-transport counters; deferred / non-gating" to match the epics.
- (d) Add AC: the REST route still works **after** the 2.3 gRPC wiring (Story 4.2/4.3).
- (e) State the exact `grpc.yml` job `name:` string and require it byte-matches the `.github/required-pr-checks.txt` row; verify after the first PR run.
- (f) ADR-0004 records: introduced-REST-baseline rationale (user-confirmed), reachable-vs-reserved status codes, and the unsupervised forked `rr` risk (watchdog optional, or accepted).

**Once RD1–RD10 are applied to the three specs and a re-check confirms them, this is GO.**
