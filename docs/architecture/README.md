# Architecture — Quick Start

The `.c4` files in this directory describe the post-cutover architecture:
the monolith (HTTP / gRPC / scanner / saga-worker) integrating with the
extracted `notification-svc` (own Postgres) through RabbitMQ by default —
`SendReleaseEmail/v1`, `SendWelcomeEmail/v1` and the `WelcomeEmailOutcome/v1`
reply — plus the opt-in synchronous welcome-email transports (REST `:8081`,
gRPC `:9002`). `layers.c4` additionally models the code-level layer
separation (bounded contexts × Domain/Application/Infrastructure) that
deptrac enforces as architecture tests.

See [`ARCHITECTURE.md`](./ARCHITECTURE.md) for the high-level explanation.

## Run

From the repo root:

```bash
make c4-up
make c4-logs
make c4-validate
make c4-down
```

Set `LIKEC4_PORT=5174` if `5173` is busy.

## Diagrams

The `.c4` source is the source of truth. Export PNG/SVG from the LikeC4 UI when
you need refreshed static diagrams.

## VS Code

Install the `likec4` extension for preview and autocomplete.
