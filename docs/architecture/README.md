# Architecture — Quick Start

The `.c4` files in this directory describe the post-cutover architecture:
monolith HTTP/gRPC/scanner publishing `SendReleaseEmail/v1` to RabbitMQ, and
`notification-svc` consuming it with its own Postgres.

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
