# 4. Add a synchronous gRPC (and REST) transport for the welcome-email send, behind a flag

- **Status:** Accepted
- **Date:** 2026-06-23
- **Scope:** The welcome-email *send* leg of the HW9 enrollment saga (monolith saga relay → notification service), its transport selection, and the buf/gRPC toolchain.

## Context

HW9 (ADR-0003) made the welcome-email confirmation flow **asynchronous** on both
legs: the `saga-worker` relays `SendWelcomeEmail/v1` to RabbitMQ with publisher
confirms, the notification service sends and replies `WelcomeEmailOutcome/v1` on a
reply queue, and a timeout sweeper guarantees the saga "never hangs." There is **no
synchronous service-to-service REST call anywhere in the system** — all monolith ↔
notification traffic is RabbitMQ.

The task is to replace one *synchronous HTTP REST call between two microservices*
with a gRPC unary RPC, driven by **buf**, keeping the REST path alongside behind a
feature flag, with correct gRPC status-code mapping. Because no such REST call
exists, the honest framing is: pick the most request/response-shaped inter-service
interaction (the welcome **send**), **introduce** a thin synchronous REST endpoint
as the baseline, and add a gRPC twin — without disturbing the HW9 default.

The repo already runs Spiral RoadRunner gRPC for the monolith's own Subscription API
(`proto/release_notifier.proto`), but that is an *external client → service* API, not
service-to-service, and its Spiral plugin emits a **server** interface only — no
client stub. buf is greenfield here.

## Decision

Migrate exactly the welcome **send** call. Service A = the monolith saga relay;
Service B = the notification service, reusing `SendWelcomeEmailHandler` **unchanged**.

### One new contract, buf-toolchained, isolated from the frozen legacy proto

A new `proto/notification/welcome/v1/welcome.proto` (`package notification.welcome.v1`,
`php_namespace Notification\Welcome\V1`) declares one unary RPC
`SendWelcomeEmail(SendWelcomeEmailRequest) returns (SendWelcomeEmailResponse)` whose
response carries an `Outcome {OUTCOME_UNSPECIFIED, OUTCOME_SENT, OUTCOME_FAILED}`.
`buf.yaml` lints it under STANDARD (fully offline; the legacy proto is ignored, never
re-linted or re-generated). `buf.gen.yaml` runs three plugins off the one proto into a
dedicated `gen/` tree (separate from the committed `generated/`): remote
`protocolbuffers/php` (messages), the vendored Spiral plugin as the one local plugin
(server interface), and remote `grpc/php` (the `*Client extends \Grpc\BaseStub`
stub). `buf lint` is the offline CI gate; `buf generate` reaches buf.build for the two
remote plugins (a codegen-time-only dependency, with a documented pinned offline
fallback).

### Service B — two transport adapters over the unchanged handler

A RoadRunner gRPC server (`WelcomeEmailGrpcService` on `:9002`, a second supervised
process in the notification container) and a Slim REST endpoint
(`POST /internal/welcome-emails`) both invoke the **same unchanged**
`SendWelcomeEmailHandler`. Crucially the gRPC **server** needs **no `ext-grpc`** —
RoadRunner's Go process terminates gRPC and hands decoded messages to the pure-PHP
Spiral worker, exactly as the monolith's existing `grpc` service runs. The transport
adapter (not the handler) derives the result: normal return → `OUTCOME_SENT`;
`WelcomeAlreadyFailedException` → `OUTCOME_FAILED` (a normal OK response);
`WelcomeInFlightException` → `ABORTED`/409; validation → `INVALID_ARGUMENT`/400;
anything else → `INTERNAL`/500 — a load-bearing catch order (both domain exceptions
extend `\RuntimeException`) centralised in the one `ExceptionStatusMap`
(`toGrpcStatus()` + `toHttpStatus()`).

### Service A — port impl-swap behind a flag, no `SagaWorker` change

The existing `WelcomeEmailRelay` port is bound, by `WELCOME_EMAIL_TRANSPORT ∈
{rabbit, rest, grpc}` (default `rabbit`), to one of three adapters; only the selected
one is built (the gRPC client — `grpc/grpc` + PECL `ext-grpc`, confined to the
monolith image — is instantiated lazily). The sync adapters call Service B with a 10s
deadline and bounded retry (3 attempts, 200/500/1000 ms) on `UNAVAILABLE`/
`DEADLINE_EXCEEDED` (and 502/503/504/connect for REST), and on a definitive OK drive
the saga **in-thread** via the existing `HandleWelcomeEmailOutcomeCommand`. This is
safe because `complete()`/`compensate()` accept `Started`, the subscription
`status='pending'` guard makes re-application a no-op, and `markPublished()` then
no-ops; the async reply the unchanged handler still publishes is an idempotent
backstop. `SagaWorker`, `RelayPendingWelcomeEmails`, the port signature, and
`RabbitWelcomeEmailRelay` are untouched.

### Only the send leg migrates

The `WelcomeEmailOutcome/v1` reply and the timeout sweeper stay on RabbitMQ; the
`saga-worker` keeps `depends_on: rabbitmq`. We migrate one *call*, not the broker.

## Alternatives considered

- **A sibling `SyncWelcomeEmailSender` port + a `RelayPendingWelcomeEmailsSync`
  use-case.** Cleaner separation of the async `void` contract, but it requires
  `SagaWorker` to call a different use-case. Rejected once the saga writer's
  `Started`-accepting `complete()`/`compensate()` proved the in-thread drive safe via
  a pure DI impl-swap (no `SagaWorker` change).
- **Migrate `SendReleaseEmail` instead.** Fan-out, fire-and-forget, no reply path —
  not a caller-waits request/response. Rejected.
- **Reuse the existing Subscription REST + gRPC dual-expose.** External client →
  service, not service-to-service, and the proto already exists — neither satisfies
  the brief nor leaves meaningful work. Used only as the reference pattern.
- **Make a sync transport the production default.** Deferred. `rabbit` stays default
  so the broker buffer and the sweeper's never-hangs guarantee remain the norm; the
  sync paths are opt-in.
- **A hand-written PHP HTTP/2 gRPC client.** Re-implements framing/trailers/
  deadlines; fragile. Rejected for the official `grpc/grpc` + `ext-grpc`, confined to
  the one image that runs the client.
- **Regenerate everything (incl. the legacy proto) with buf.** Out of scope; the
  legacy proto + `generated/` stay frozen and buf output is isolated in `gen/`.

## Consequences

### Positive

- A real REST-vs-gRPC comparison on the same handler: HTTP/1.1 + JSON vs HTTP/2 +
  binary protobuf, switchable by one env var, with the REST baseline kept working.
- HW9 is untouched by default (`rabbit`): same saga, same tests, same wire contracts.
- One status mapping for both transports; deptrac baselines stay `{}` on both
  deployables; `gen/` is isolated from `generated/` and excluded from the hand-written
  gates exactly as `generated/` is.
- `ext-grpc` is confined to the monolith (client) image; Service B stays runtime-pure.

### Negative

- On the sync paths the caller blocks and loses the broker buffer and the sweeper's
  never-hangs guarantee — mitigated by the client deadline + bounded retry, and by
  `rabbit` remaining the default (which retains the sweeper).
- The unchanged handler still publishes its async `WelcomeEmailOutcome` reply on the
  sync paths (FR5: no handler change), so the saga is signalled twice — harmless: the
  conditional UPDATEs make the second an idempotent no-op (`welcome_reply_noop_total`).
- The sync in-thread drive skips the `WelcomePublished` in-process event
  (`markPublished()` no-ops), so the `welcome.published` log line is absent on the
  sync transports — a minor observability gap, not a state difference.
- `buf generate` depends on buf.build for the two remote plugins (offline fallback
  documented); a new PECL extension (`ext-grpc`) is added to the monolith image.

## Follow-ups

- **Benchmark.** `make bench-rest` / `make bench-grpc` (k6: HTTP + `k6/net/grpc`)
  produce the README's req/s + p50/p95/p99 comparison.
- **LikeC4 model.** Synced with the new gRPC server container/port, the REST endpoint,
  the three flag-selected relays, and the welcome contract.
- **Promote sync to default (deferred).** Only after the sync deadline/retry policy is
  proven in production; the async path remains the fallback regardless.
- Keep the welcome proto additive-only, as with the `SendWelcomeEmail/v1` AMQP contract.
