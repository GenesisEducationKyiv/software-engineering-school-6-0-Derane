---
artifact: onboarding-rest-to-grpc
project: github-release-notifier
author: valerii
date: '2026-06-23'
status: draft
related:
  - specs/hw7-clean-architecture-microservices/architecture.md
  - specs/hw9-saga-subscription-confirmation/architecture.md
  - specs/project-context.md
---

# Onboarding: REST → gRPC Migration (one synchronous inter-service call)

> **Goal of the initiative:** replace ONE synchronous HTTP REST call between two microservices with a gRPC unary RPC (`.proto` contract + buf tooling), keeping the REST path working alongside behind an env/feature flag, with correct gRPC status-code error mapping.
>
> **Headline reality (load-bearing):** there is **no synchronous service-to-service REST call in this system today**. All monolith ↔ notification traffic is asynchronous RabbitMQ. So this is not a literal "swap call X for gRPC" — it is "pick the most request/response-shaped inter-service interaction, stand up a thin synchronous REST baseline on the server side, and add a gRPC alternative behind a flag." See §4–§6.

## 1. Service topology (deployables)

| # | Process | Entrypoint | Transport(s) | Inbound port | compose service | Image |
|---|---|---|---|---|---|---|
| 1 | Monolith REST API | `public/index.php` → `config/app.php` (FrankenPHP worker `bin/worker.php`, `Caddyfile:9`) | REST in; HTTPS→GitHub, PDO→Postgres A, Redis | **8080** (`docker-compose.yml:5`) | `app` | root `Dockerfile` (PHP 8.4 / FrankenPHP) |
| 2 | Monolith gRPC API | `bin/grpc.php` (`rr serve -c .rr.grpc.yaml`) | gRPC unary **in**; PDO, Redis | **9001** (`.rr.grpc.yaml:5`, `docker-compose.yml:37`) | `grpc` | root `Dockerfile` + RoadRunner |
| 3 | Monolith scanner | `bin/scanner.php` → `ScannerCliRunner` | CLI loop; HTTPS→GitHub, PDO, Redis; emits `NewReleaseDetected` → publishes `SendReleaseEmail` to RabbitMQ | none | `scanner` | root `Dockerfile` |
| 4 | Monolith saga worker (HW9) | `bin/saga-worker.php` → `SagaWorker::run()` | AMQP consume+publish; PDO | none | `saga-worker` | root `Dockerfile` |
| 5 | **Notification service** (`apps/notification`, `Notification\Sending`) | `apps/notification/bin/start.sh` forks `php -S …:8081 http/server.php` **and** `exec php bin/consumer.php` | REST health/metrics in + AMQP consume/publish; PDO→notification-db; SMTP→MailHog | **8081** (health/metrics only, `bin/start.sh:9`, `docker-compose.yml:142`) | `notification-svc` | `apps/notification/Dockerfile` (PHP 8.2-cli-alpine) |
| 6 | Postgres A (monolith DB) | image | TCP 5432 | 5432 | `postgres` | postgres:16-alpine |
| 7 | Postgres (notification DB) | image | TCP 5432→host 5433 | 5433/5432 (`docker-compose.yml:117`) | `notification-db` | postgres:16-alpine |
| 8 | Redis (GitHub-API cache only) | image | 6379 | 6379 | `redis` | redis:7-alpine |
| 9 | RabbitMQ | image | 5672 / 15672 | 5672/15672 | `rabbitmq` | rabbitmq:4-management-alpine |
| 10 | MailHog (dev SMTP sink) | image | 1025 / 8025 | 1025/8025 | `mailhog` | mailhog/mailhog:v1.0.1 |

**Two genuine microservices:** the **monolith** (one image / `src/` / DI container, fanned into compose services `app`, `grpc`, `scanner`, `saga-worker`, all sharing Postgres A) and **`apps/notification`** (own `composer.json`, `src/`, `Dockerfile`, migrations, own `notification-db`). The `:8081` HTTP server inside notification-svc is an in-process health/metrics sidecar (`apps/notification/http/index.php:20-21`), **not** a second service. `scanner`/`saga-worker` are background workers of the monolith, not independent services.

## 2. Communication map (sync vs async)

| Hop | Direction | Sync/Async | Exchange | Routing key | Queue | Evidence |
|---|---|---|---|---|---|---|
| External client → Subscription CRUD | client→monolith | **SYNC** REST | — | — | — | `config/app.php:35-41` (`/api/subscriptions`) |
| External client → Subscription CRUD | client→monolith | **SYNC** gRPC | — | — | — | `proto/release_notifier.proto:7-13`; `src/Grpc/ReleaseNotifierService.php` |
| Monolith → GitHub API | monolith→3rd party | **SYNC** HTTPS (Guzzle) | — | — | — | `src/Releases/Sourcing/Infrastructure/GitHubApiClient.php:7,12,35` |
| Subscribe → saga start | in-process (monolith) | **SYNC** (1 DB tx) | — | — | — | `SubscribeCommandHandler.php:69-84`; `StartEnrollmentSagaService.php:37-48` |
| `NewReleaseDetected` | in-process (monolith) | **SYNC** PSR-14 | — | — | — | `ScanReleasesHandler.php:70-76`; `InMemoryEventDispatcher.php:34` |
| `SendReleaseEmail` | monolith→notif | **ASYNC** | `notifications` | `release.email` | `notifications.send-email` | `RabbitReleaseNotificationPublisher.php:28-33`; `RabbitConnection.php:40,50` |
| `SendWelcomeEmail` | monolith→notif | **ASYNC** | `notifications` | `subscription.welcome-email` | `notifications.welcome-email` | `RabbitWelcomeEmailRelay.php:85-90`; `RabbitConnection.php:43-44` |
| `WelcomeEmailOutcome` reply | notif→monolith | **ASYNC** | `notifications` | `subscription.welcome-email.reply` | `notifications.welcome-email-reply` | `RabbitWelcomeOutcomePublisher.php:70-75`; `RabbitConnection.php:47-48` |

**The only synchronous HTTP today** is (a) the monolith's own inbound public API on :8080 (mirrored by gRPC :9001) and (b) the outbound GitHub API. The only inbound HTTP on the notification service is `GET /health` + `GET /metrics` (`apps/notification/http/index.php:20-21`) — infrastructure probes that do no domain work and are never called by the monolith. There is **no outbound HTTP/Guzzle client in `apps/notification` at all**, and no gRPC client anywhere in the repo (searches for `ServiceClient`/`ChannelCredentials`/`UnaryCall` return only `generated/` + tests).

## 3. The HW9 welcome/confirmation round-trip (the only real cross-service request/response)

This is a **request/reply collapsed into two async RabbitMQ legs**, correlated by `correlation_id = sagaId`:

1. `SubscribeCommandHandler` inserts the subscription (state `PENDING`) and starts the saga in one DB transaction (`SubscribeCommandHandler.php:81`). No email in the request thread.
2. `SagaWorker` tick → `RelayPendingWelcomeEmails::relay()` reads STARTED sagas and publishes `SendWelcomeEmail` via the **`WelcomeEmailRelay` port** (`src/Saga/Enrollment/Domain/WelcomeEmailRelay.php` — single method `publish(SendWelcomeEmail): void`, **returns void / fire-and-forget**, throws on unconfirmed publish). Advances to `AwaitingConfirmation` only after a confirmed publish (`RelayPendingWelcomeEmails.php:48-89`).
3. Notification `SendWelcomeEmailConsumer` → `SendWelcomeEmailHandler::handle()` (claim/render/send/markSent). The handler also **returns void** and publishes the outcome to a reply queue on every disposition (`apps/notification/src/Sending/Application/SendWelcomeEmailHandler.php:42-121`).
4. `RabbitWelcomeOutcomePublisher` publishes `WelcomeEmailOutcome/v1` (`sent`/`failed`) on `subscription.welcome-email.reply`.
5. Monolith `WelcomeEmailOutcomeConsumer` maps the reply to `HandleWelcomeEmailOutcomeCommand` → confirm subscription + complete saga, or cancel + compensate, in one Postgres transaction (`WelcomeEmailOutcomeConsumer.php:36`; `HandleWelcomeEmailOutcomeHandler.php:62-82`).
6. **Backstop:** `SweepTimedOutSagas` compensates sagas that never get a reply (`SweepTimedOutSagas.php:52-99`), so the saga never hangs.

**Why this matters for the migration:** the welcome path is logically "API/saga side asks the notification service to send the welcome email and waits for sent/failed" — the canonical "VerifyEmail" RPC shape. But today **both legs are async**, the caller does **not** block (`SagaWorker` polls a durable reply queue with a timeout sweeper), and **neither side returns the outcome** (both `publish()` and `handle()` are `void`). Converting it to a synchronous caller-waits RPC removes the broker buffer and the sweeper's "never hangs" guarantee, so it would need its own deadline/retry policy — this is a real semantic change, not a transport swap.

## 4. Existing gRPC / proto / buf state (current truth)

- **Contract:** single file `proto/release_notifier.proto`. `package release_notifier.v1;` (versioned), `option php_namespace = "Grpc\\ReleaseNotifier\\V1"`. Service `ReleaseNotifierService` with **5 unary RPCs** (`Health`, `CreateSubscription`, `ListSubscriptions`, `GetSubscription`, `DeleteSubscription`) — all `(Request) returns (Reply)`, no streaming, no imports. This is the **monolith's own external Subscription API**, not an inter-service call. `SubscriptionReply.status = 5` is an additive HW9 field (proto:45-46).
- **Generated code:** `generated/Grpc/ReleaseNotifier/V1/*` (messages + **server interface** `ReleaseNotifierServiceInterface extends GRPC\ServiceInterface`) + `generated/GPBMetadata/Proto/ReleaseNotifier.php`. PSR-4: `Grpc\` → `generated/Grpc/`, `GPBMetadata\` → `generated/GPBMetadata/` (`composer.json:40-41`). **Server interface only — the Spiral `protoc-gen-php-grpc` plugin emits NO client stub.**
- **No buf anywhere.** No `buf.yaml`/`buf.gen.yaml`/`buf.lock`/`buf.work.yaml`. Codegen is raw `protoc` + the vendored Spiral plugin `tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc`, run by the single Makefile target **`make proto`** (`Makefile:129-130`) inside the `app` container. `protoc` comes from the Docker image (`Dockerfile:4` installs `protobuf-compiler`). `generated/` is committed and shipped via `COPY . .`; no CI step regenerates it. **Introducing buf is greenfield** for this repo.
- **Server runtime:** `bin/grpc.php:22-31` builds a Spiral `Server`, registers `ReleaseNotifierServiceInterface::class` → `ReleaseNotifierService` (DI at `config/container.php:545`), serves on `tcp://0.0.0.0:9001`, plaintext, no TLS/interceptors (`.rr.grpc.yaml`).
- **`apps/notification` has ZERO gRPC:** `apps/notification/composer.json` requires only `php-amqplib`, `phpmailer`, `php-di`, `slim/*`, `psr/log` — **no `spiral/roadrunner-grpc`, `google/protobuf`, or any gRPC dep**. No proto, no `generated/`, no gRPC server, no `.rr.grpc.yaml`. A gRPC server here is **net-new** (deps + RoadRunner entrypoint + DI + a second container process alongside `bin/consumer.php`).

## 5. Error mapping (the "correct gRPC status-code" requirement)

`src/Shared/Infrastructure/Error/ExceptionStatusMap.php` is the **single source of truth** used by both `ErrorHandlerMiddleware` (HTTP) and `Grpc\ReleaseNotifierService` (gRPC):

- `ValidationException` / `InvalidArgumentException` → `INVALID_ARGUMENT` (HTTP 400)
- `RepositoryNotFoundException` / `SubscriptionNotFoundException` / `SagaNotFoundException` → `NOT_FOUND` (404)
- `RateLimitException` → `RESOURCE_EXHAUSTED` (429)
- default → `INTERNAL` (500)

`ReleaseNotifierService::mapException()` (`src/Grpc/ReleaseNotifierService.php:137-150`) wraps every RPC (except `Health`) and translates via this map: `INTERNAL` → Spiral `ServiceException`, everything else → `GRPCException`. **`apps/notification` has its own independent, HTTP-only `ExceptionStatusMap`** (`apps/notification/src/Sending/Infrastructure/Error/ExceptionStatusMap.php`) — if a gRPC server lands there, it needs a `toGrpcStatus()` of its own (no gRPC dep there today).

## 6. Quality gates

**Monolith (root):**
- `composer lint` → `phpcs` (PSR-12) **then** `deptrac analyse --no-progress --no-cache` (`composer.json:51-54`).
- `./vendor/bin/phpunit --no-coverage --testsuite Unit` — env-independent gate. The `Unit` suite = everything under `tests/` **except** `tests/Acceptance`, `tests/Integration`, `tests/e2e` (`phpunit.xml:8-13`); there is **no `tests/Unit/` dir** in the monolith. `Integration` suite needs Postgres+Redis (`make integration-up`). Behat/e2e need the full stack — ask before booting.
- `composer psalm` → `psalm` (errorLevel 1, phpVersion 8.4, scans `src/` only).
- `deptrac.yaml` scans `./src` + `./apps`; **baseline is empty `{}`** (`deptrac.baseline.yaml:2`) and must only shrink. `phpcs` excludes `generated/`; psalm/deptrac don't scope `generated/` — so **generated stubs won't break static gates, but hand-written client/server adapters are fully gated.**
- `make proto` (`Makefile:129-130`) is the only proto/grpc Make target (generation). `composer.json:60` `"grpc"` runs the server, not codegen.

**`apps/notification`:** `composer lint` (phpcs + deptrac), `composer psalm` (errorLevel 1, phpVersion 8.2, **suppresses** `UnusedClass`/`PossiblyUnusedMethod`/`PossiblyUnusedProperty`). **No `composer test` script** — PHPUnit run directly; `defaultTestSuite="Unit"` over `tests/Unit/` (a real dir here). Integration needs notification-db+RabbitMQ+MailHog inside the container. Self-contained 4-layer `deptrac.yaml` (Domain/Application/Infrastructure/Shared).

**CI:** one GitHub-Actions workflow per gate, each invoking a `make` target (`lint.yml`, `architecture.yml`, `unit-tests.yml`, `notification-unit-tests.yml`, `integration-tests.yml`, `notification-integration-tests.yml`, `acceptance-tests.yml`, `e2e-tests.yml`, `scanner-smoke.yml`, `supply-chain.yml`). A NEW gate must be added to `.github/required-pr-checks.txt` (12 checks) AND as a `make` target consumed by a workflow whose `name:` matches a row.

**Existing gRPC test harness (where new tests go):**
- Server unit: `tests/Grpc/ReleaseNotifierServiceTest.php` — mocks `CommandBus`/`QueryBus`/`HealthCheckInterface`/`ContextInterface`, real `ExceptionStatusMap`, asserts `GRPCException` + `StatusCode::*`. Picked up by the `Unit` suite (no docker).
- Server integration: `tests/Integration/Grpc/ReleaseNotifierServiceIntegrationTest.php` — pulls the handler from DI, real Postgres, asserts NOT_FOUND/INVALID_ARGUMENT mappings. Both invoke handlers **in-process** (mocked `ContextInterface`), never over a live socket.
- **No client-side test harness exists** (no gRPC client exists). Client tests and any notification-side gRPC tests are net-new.

## 7. Candidate analysis (what to expose over gRPC)

Because no synchronous inter-service REST call exists, the candidates differ in **how the baseline REST path is established** and **how disruptive the change is**. Ranked best→worst for the assignment:

### Candidate 1/2 (recommended) — Welcome-email send/confirm: monolith (saga) → notification, `SendWelcomeEmail` as sync unary RPC, with a thin sync REST twin as baseline
- **Caller side:** the `WelcomeEmailRelay` port (`src/Saga/Enrollment/Domain/WelcomeEmailRelay.php`) is a clean, single-method seam. Today only `RabbitWelcomeEmailRelay` implements it; a REST adapter (baseline) and a gRPC adapter (alternative) can both implement the same port and be selected by an env flag in `config/container.php`. **Caveat:** the current port returns `void`; a caller-waits design needs the outcome (`sent`/`failed`) to come back through the call, which changes the port shape and the saga's relay→reply→sweeper machinery.
- **Server side:** `SendWelcomeEmailHandler` already computes the disposition — the natural RPC/REST handler body. Requires a **net-new** synchronous inbound surface in `apps/notification` (it has none for domain ops) plus, for gRPC, net-new gRPC deps + RoadRunner entrypoint there.
- **Best fit** for the canonical "API → Mail Verification Service: VerifyEmail" example and the only real cross-service request/response; **highest effort and real semantic change** (loses the async broker buffer + timeout-sweeper "never hangs" guarantee). **Mitigation:** keep the async RabbitMQ path as the DEFAULT fallback (flag off) so HW9 saga + tests + contracts remain intact; REST/gRPC sync transports are opt-in.

### Candidate 3 — Release-email send: scanner/monolith → notification (`SendReleaseEmail`)
- Poor fit: fan-out, fire-and-forget, batch, **no reply path** (`SendReleaseEmailConsumer` has no outcome publisher). Not a caller-waits-for-result interaction. Rejected.

### Non-candidate — the existing Subscription REST+gRPC dual-expose
- The monolith already dual-exposes Subscription CRUD over REST (`/api/subscriptions`) and gRPC (`ReleaseNotifierService`). This is **client→service (external), not service-to-service**, and the proto already exists, so it neither satisfies "between two microservices" nor leaves meaningful work. It is useful only as the **reference pattern** (proto, status mapping, RoadRunner wiring) to copy. Does not count.

## 8. Recommendation

Pick the **welcome/confirm interaction** (monolith saga ↔ notification), introducing a thin synchronous REST baseline on the notification service and a gRPC unary alternative behind an env flag — **with the async RabbitMQ relay/reply/sweeper path retained as the DEFAULT fallback** so HW9 stays intact. It is the leanest path that satisfies every acceptance criterion: two genuine microservices, a true caller-waits-for-result unary RPC (`SendWelcomeEmail(...) returns (...)`), the REST path kept as a live baseline behind a flag, and gRPC status mapping reusing the established `ExceptionStatusMap` pattern. It reuses the existing proto/buf/RoadRunner conventions and the clean `WelcomeEmailRelay` port seam, while keeping scope contained (one RPC, one new server surface, one client adapter). Plan the change as a deliberate semantic shift (caller waits; the async path is the fallback) rather than a transport-only swap.

## 9. Open decisions for the user (resolve before Planning)

1. **AC framing** — accept introducing a thin synchronous REST endpoint on `apps/notification` as the BASELINE (then gRPC twin), since no synchronous inter-service REST call exists to swap one-for-one?
2. **Semantic change** — accept making the welcome/confirm path caller-waits on the new sync transports, with the async RabbitMQ path kept as the flagged DEFAULT fallback (protecting HW9)?
3. **buf adoption** — the assignment mandates buf; adopt buf for the new proto (greenfield here). Client-stub generation strategy (official grpc PHP plugin vs hand-written adapter; PHP gRPC client needs the `grpc` PECL extension) is an architecture-phase decision.
4. **gRPC server location** — net-new RoadRunner gRPC entrypoint inside `apps/notification` (adds `spiral/roadrunner-grpc` + `google/protobuf` + a second container process), governed by `apps/notification`'s own deptrac.
