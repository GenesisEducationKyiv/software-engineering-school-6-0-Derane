# Architecture (LikeC4)

The authoritative architecture model lives in the `.c4` files in this
directory and is rendered with [LikeC4](https://likec4.dev). It is
**architecture-as-code**: when the code changes, the model changes in the
same PR, and the layer rules drawn here are executable — they are enforced
by deptrac on every push (see [Architecture tests](#architecture-tests-deptrac)).

## Runtime topology

Application processes (docker-compose services):

| Service | Role | Technology |
|---|---|---|
| `app` | Monolith HTTP API (REST + HTML form, health, metrics) | PHP 8.4, Slim 4, FrankenPHP |
| `grpc` | Monolith gRPC API over the same CQRS buses | PHP 8.4, RoadRunner gRPC |
| `scanner` | Release-detection worker; publishes `SendReleaseEmail/v1` | PHP 8.4 CLI loop |
| `saga-worker` | Enrollment-saga orchestrator: outbox relay, `WelcomeEmailOutcome` reply consumer, timeout sweeper | PHP 8.4 CLI loop |
| `notification-svc` | Extracted notification-delivery service. One container, three co-located processes: the RabbitMQ consumer loop, a `php -S` HTTP surface on `:8081` (health/metrics + synchronous `POST /internal/welcome-emails`), and a RoadRunner gRPC server on `:9002` (`notification.welcome.v1.WelcomeEmailService`). Only `:8081` is health-checked; the forked HTTP/gRPC processes run unsupervised (accepted risk, ADR-0004) | PHP 8.2 |

Supporting infrastructure: monolith PostgreSQL, notification PostgreSQL,
Redis (GitHub-API response cache only), RabbitMQ, MailHog (local SMTP sink).

Data ownership is split:

- **Monolith PostgreSQL** — `subscriptions` (with the
  pending/confirmed/cancelled status lifecycle), `repositories`,
  `enrollment_sagas` (saga state), `saga_metrics` (read-model).
- **Notification PostgreSQL** — `release_notifications` and
  `welcome_notifications` (idempotency ledgers), `notification_metrics`.

(Each database also carries its own `migrations` bookkeeping table.)

Cross-service integration is RabbitMQ by default; the welcome-email send can
opt into a synchronous REST or gRPC call (`WELCOME_EMAIL_TRANSPORT`,
ADR-0004 — see flow 4). The wire messages are versioned JSON schemas in
[`contracts/`](../../contracts):

- `SendReleaseEmail/v1` — scanner → notification-svc (recipients already resolved)
- `SendWelcomeEmail/v1` — saga-worker → notification-svc
- `WelcomeEmailOutcome/v1` — notification-svc → saga-worker (reply)

These schemas plus the welcome-email protobuf contract
(`proto/notification/welcome/v1/welcome.proto`, buf-generated stubs in
`gen/`, autoloaded by both composer.json files) are the **only artifacts
shared between the two codebases** — no composer dependency in either
direction.

## Code architecture — bounded contexts and layers

The monolith `src/` is organized by bounded context, each split into Clean
Architecture layers with the dependency rule pointing **inward**
(Domain ← Application ← Infrastructure). See the
`Code View — Bounded-Context Map` and `Code View — Clean Architecture Layers`
diagrams (`layers.c4`).

| Context (module) | Path | Layers | Responsibility |
|---|---|---|---|
| Subscription (Subscriptions) | `src/Subscription/Subscriptions` | D + A + I | Subscription aggregate + lifecycle; subscribe starts the enrollment saga atomically |
| RepositoryTracking (Repositories) | `src/RepositoryTracking/Repositories` | D + I | Tracked-repository registry; Domain = ports + `RepositoryStatus` read-model, **Application layer empty** (placeholder dir; deptrac layer reserved) |
| Releases (Sourcing) | `src/Releases/Sourcing` | D + A + I | Release facts from GitHub via the `ReleaseSource` port; Redis-cached adapter |
| Scanning (Scanner) | `src/Scanning/Scanner` | A + I | Scan orchestration; **no Domain layer** — owns no model |
| Saga (Enrollment) | `src/Saga/Enrollment` | D + A + I | `EnrollmentSaga` aggregate, relay/outcome/sweep use-cases, three relay transports |
| Notification (Publishing) | `src/Notification/Publishing` | D + A + I | Publisher side: resolves recipients, publishes `SendReleaseEmail/v1` |
| Shared kernel | `src/Shared` | D + A + I | VOs, `AggregateRoot`, CQRS bus contracts + in-memory buses, PSR-14 plane, `ExceptionStatusMap`, metrics/health |
| Legacy (transitional) | `src/Controller`, `src/Grpc`, `src/Migration` | I only | Strangler remainder: health/metrics controllers, gRPC service, migrator |

The extracted service (`apps/notification/src`) has its own
Sending context (D + A + I) plus a service-local `Shared` infrastructure
layer, governed by its own `deptrac.yaml`.

Rules that keep the boundaries clean:

- **Dependency rule.** Within a context, Application sees only Domain;
  Infrastructure sees Application + Domain. Domain sees nothing outward.
- **Shared-kernel ring rule.** A context layer may only use the Shared layer
  of the same or an inner ring: Domain → `Shared.Domain` only;
  Application → + `Shared.Application`; Infrastructure → + `Shared.Infrastructure`.
- **Cross-context coupling is port-only.** Every allowed edge is an explicit
  grant in `deptrac.yaml`, e.g. Subscription.Application →
  Releases.Domain (`ReleaseSource::repositoryExists()`), Saga.Application →
  Subscription.Domain (`SubscriptionConfirmationWriter`),
  NotificationPublishing.Application → Subscription.Domain (`SubscriberFinder`).
  Saga.Domain and Subscription.Domain stay mutually independent (correlation
  by primitive `subscriptionId`) so the two opposite-direction grants cannot
  form a Domain↔Domain cycle.
- **Two event planes.** In-process domain events go through the synchronous
  PSR-14 plane (listener exceptions propagate — a publish failure aborts
  marker advancement, keeping the release flow outbox-free). Cross-service
  messages go through RabbitMQ as versioned integration commands/replies.

## Core flows

Modeled as dynamic views in `views.c4`:

1. **Create Subscription** — controller → `SubscribeCommand` → GitHub
   validation (cached) → in ONE transaction: `subscriptions(status=pending)`
   insert + `enrollment_sagas(state=started)` insert. The API never blocks on
   the broker.
2. **Publish And Send Release Notification** — scanner detects a new release,
   dispatches `NewReleaseDetected` (PSR-14), the Publishing listener resolves
   subscribers and publishes a `SendReleaseEmail/v1` batch with one
   confirm-wait; `last_seen_tag` advances **only after publish succeeds**
   (no outbox on this path). The service consumes, claims in its ledger
   (idempotent), renders, and sends via SMTP.
3. **Confirmed-Subscription Saga** — the saga-worker relays
   `SendWelcomeEmail/v1` from the `enrollment_sagas` outbox (publisher
   confirms), the service sends the welcome email and replies
   `WelcomeEmailOutcome/v1`, and the orchestrator confirms or compensates the
   subscription + saga in one transaction; a timeout sweeper guarantees
   convergence.
4. **Synchronous Welcome-Email Send** — opt-in `WELCOME_EMAIL_TRANSPORT=rest|grpc`
   swaps the `WelcomeEmailRelay` binding: the unchanged relay use-case calls
   the notification service synchronously (REST `:8081` or gRPC `:9002`) and
   drives the saga in-thread on a definitive outcome.

## Architecture tests (deptrac)

The layer separation drawn in `layers.c4` is not documentation-only — it is
enforced as an executable test suite:

- **`deptrac.yaml`** (repo root) defines one deptrac layer per context layer
  above (`Subscription.Domain`, `Subscription.Application`, … ,
  `Legacy.Infrastructure`, `Apps.Monolith`, `Apps.Notification`) and a
  ruleset that matches the diagram edge-for-edge.
- **`deptrac.baseline.yaml` is EMPTY** (`skip_violations: {}`) — zero
  grandfathered violations.
- **`apps/notification/deptrac.yaml`** enforces the extracted service's own
  Domain/Application/Infrastructure layering independently.

Run them:

```bash
composer lint                  # PHPCS + deptrac (monolith gate)
make deptrac                   # monolith gate in Docker
make notification-deptrac      # notification-service gate in Docker
```

Both gates run in CI on every push and pull request
(`.github/workflows/architecture.yml`, job `deptrac`). Current state:
**0 violations** on both, warnings 0; classes not yet assigned to a layer
show up as `Uncovered` and shrink as the Strangler migration completes.

## Architectural decisions (ADRs)

- [ADR-0001 — FrankenPHP worker mode](../adr/0001-frankenphp-worker-mode.md)
- [ADR-0002 — Notification service extraction](../adr/0002-notification-service-extraction.md)
- [ADR-0003 — Orchestrated saga for subscription confirmation](../adr/0003-orchestrated-saga-subscription-confirmation.md)
- [ADR-0004 — gRPC transport for the welcome-email send](../adr/0004-grpc-transport-for-welcome-email-send.md)

Decisions reflected in the model: Clean Architecture boundaries in both
codebases; synchronous PSR-14 for in-process domain events; RabbitMQ for
cross-service integration; **outbox-free release flow** (publish-gated marker
advancement) vs. an **outbox-style relay for the enrollment saga**
(`enrollment_sagas` rows + publisher confirms + sweeper); split data
ownership between the two PostgreSQL databases; wire contracts as the only
shared artifact.

## Model files

| File | What it describes |
|------|-------------------|
| `specification.c4` | element/relationship kinds and styling (runtime + code-view families) |
| `landscape.c4` | subscriber, GitHub, and the system boundary |
| `containers.c4` | deployables and infrastructure in the final split stack |
| `components.c4` | components inside HTTP, gRPC, scanner, saga-worker, and the notification service (consumer + gRPC server) |
| `layers.c4` | **code structure**: bounded contexts × Clean Architecture layers, mirroring the deptrac rulesets |
| `views.c4` | all views: structural, code views, and dynamic flows |

Views: `index`, `containers`, `httpApiComponents`, `grpcApiComponents`,
`scannerComponents`, `sagaWorkerComponents`, `notificationServiceComponents`,
`notificationGrpcComponents`, `contextMap`, `monolithLayers`,
`notificationLayers`, plus the four dynamic flows listed above.

## Working with the model

Validate the model from the repo root:

```bash
make c4-validate
```

Preview it locally:

```bash
make c4-up      # http://localhost:5173
make c4-down
```

For one-off image exports use the **Export** button in the LikeC4 UI, or the
`npm run export:png` script in `docs/architecture/package.json`.
