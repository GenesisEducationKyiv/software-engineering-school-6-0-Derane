# 2. Observability: structured logging to ELK, RED metrics to Prometheus, Grafana dashboards

- **Status:** Accepted
- **Date:** 2026-05-29
- **Scope:** All three runtimes (HTTP API, gRPC API, scanner). Adds a dev-only observability stack overlay; the application code change is production-relevant.

## Context

The service had no real observability:

1. **Logs were unstructured.** The Monolog logger wrote to `php://stderr` with the
   default line formatter and no correlation id, so logs could not be searched,
   correlated across a request, or aggregated.
2. **No RED metrics.** `/metrics` existed but only exposed point-in-time business
   gauges (subscriptions/repositories) read from Postgres at scrape time. There
   was no request **R**ate, **E**rror rate, or **D**uration for HTTP or gRPC, and
   nothing for the scanner.

We needed: structured logging shipped to Elasticsearch (searchable in Kibana),
RED instrumentation exported to Prometheus, and a Grafana dashboard over the
metrics — without coupling the request path to external systems.

A complication shapes both pipelines: the app runs as **three separate
processes** (FrankenPHP HTTP worker, RoadRunner gRPC, scanner CLI loop), and
PHP is share-nothing per request.

## Decision

### Structured logging → Filebeat → Elasticsearch → Kibana

- Monolog emits **JSON** (`JsonFormatter`) to stderr, plus `PsrLogMessageProcessor`
  and a new `ContextProcessor` that stamps every line with `component`
  (api/grpc/scanner), `env`, and `correlation_id`.
- A per-unit-of-work **correlation id** is held in a request-scoped
  `CorrelationContext` (mutable singleton): set by `CorrelationIdMiddleware` for
  HTTP (honouring an inbound `X-Request-Id`), by `MeasuredInvoker` per gRPC call
  (honouring an inbound `x-request-id` metadata entry), and per cycle in the
  scanner loop. The id is minted by a shared `CorrelationIdGeneratorInterface`
  when none arrives from upstream, so a trace can span service boundaries.
- Every request/call gets a structured **access log**: HTTP via
  `RequestMetricsMiddleware` (`http request handled`), gRPC via `MeasuredInvoker`
  (`grpc call handled`, with `grpc_method`/`grpc_code`/`duration_ms`). Server-side
  failures (HTTP 500 / gRPC `INTERNAL`) additionally get an `error`-level log with
  the exception — for HTTP in `ErrorHandlerMiddleware`, for gRPC in `MeasuredInvoker`,
  each being the single place its transport logs unhandled failures.
- The app stays unaware of Elasticsearch. **Filebeat** tails container
  stdout/stderr, decodes the JSON, and ships it to Elasticsearch; **Kibana**
  reads it. No Logstash — the logs are already structured.
- A one-shot **`kibana-setup`** service waits for Kibana and creates the
  `release-notifier-logs-*` **data view** via the Data Views API, so the logs are
  searchable in Kibana on first `make obs-up` with no manual step. It is
  idempotent (a pre-existing data view is treated as success).

### RED metrics → Prometheus → Grafana

- Adopt **`promphp/prometheus_client_php`** with a **Redis storage backend**.
  All three processes write counters/histograms to the same Redis, so the HTTP
  `/metrics` endpoint renders the whole registry and **Prometheus scrapes one
  target** yet sees HTTP + gRPC + scanner metrics.
- Instrumentation points, each behind a small app interface
  (`HttpMetrics`/`GrpcMetrics`/`ScanMetrics`):
  - HTTP: `RequestMetricsMiddleware` records `http_requests_total{method,route,status}`
    and `http_request_duration_seconds`. It runs inside the routing middleware
    (so the label is the bounded route **pattern**) and reuses `ExceptionStatusMap`
    to label a thrown exception with the same status the error handler will map
    it to, then re-throws.
  - gRPC: `MeasuredInvoker` decorates RoadRunner's `InvokerInterface` — the single
    dispatch point — recording `grpc_server_handled_total{grpc_method,grpc_code}`
    and `grpc_server_handling_seconds`. No per-method wrappers; the service is
    untouched. The `StatusCode` → name mapping lives once in `GrpcStatusName`,
    shared by the metric label and the access log below.
  - Scanner: `ScannerService` records cycle count/duration, `scan_errors_total{type}`,
    releases detected and notification outcomes at the orchestration layer.
- `MetricsService` now renders **everything** through the promphp registry,
  including the existing business gauges (output stays Prometheus-compatible, so
  the `/metrics` contract holds). The hand-rolled `PrometheusFormatter`/`Metric`/`Gauge`
  are retired.
- **Grafana** is provisioned with a Prometheus datasource and a RED dashboard.

### Infrastructure

A `docker-compose.observability.yml` overlay adds Elasticsearch, Kibana,
Filebeat, Prometheus and Grafana, plus per-process `APP_COMPONENT`/`LOG_FORMAT`.
Run via `make obs-up`.

## Alternatives considered

- **Monolog `ElasticsearchHandler` (app → ES directly)** — couples the request
  path to ES availability and adds network work per log line. Rejected for the
  decoupled stdout + Filebeat pipeline.
- **Filebeat → Logstash → ES** — Logstash adds a parsing/enrichment stage we
  don't need; the logs are already structured JSON.
- **Per-process metrics endpoints / Pushgateway** — the scanner has no HTTP
  endpoint and gRPC runs separately. A shared Redis registry rendered by the HTTP
  app is simpler and idiomatic for promphp's multi-process design.
- **Re-implementing counters/histograms over our own value objects** — would
  mean re-deriving histogram bucket math and atomic cross-process increments;
  promphp already solves this.

## Consequences

### Positive

- Logs are searchable and aggregatable in Kibana, correlated per request/call/cycle.
- Standard RED signals for HTTP and gRPC, plus scanner throughput, in Prometheus
  and on a Grafana dashboard.
- One scrape target covers all three processes (shared Redis registry).
- `/metrics` stays a valid Prometheus exposition and keeps its existing gauges.

### Negative

- **Metrics are per-role aggregates, not per-instance.** The shared Redis store
  has no instance label; fine for single-replica roles, but scaling a role to
  multiple replicas would need a per-instance label or per-process exporters.
- **Redis is now on the metrics path.** It already backed the GitHub cache; it is
  now also the metrics registry. Counters persist across worker restarts (until
  Redis is flushed), which is normal for Prometheus.
- **gRPC log shipping depends on RoadRunner not wrapping worker stderr.**
  `.rr.grpc.yaml` sets `logs.mode: raw` so the worker's JSON passes through for
  Filebeat. If the gRPC container fails to start, remove that `logs:` block (the
  metrics path is unaffected). HTTP and scanner logs are plain stderr and always
  ship cleanly.
- **More moving parts in dev.** ELK + Prometheus + Grafana are memory-hungry; the
  overlay is dev-only and not part of the CI test stack (which uses
  `METRICS_STORAGE=memory`).

## Follow-ups

- Add Prometheus alerting rules (error-ratio / latency SLOs) and wire Alertmanager.
- Consider ECS-aligned field names in the log JSON for tighter Elastic integration.
- If any role scales beyond one replica, add an instance label / dedicated exporter.
- Verify the RoadRunner `logs.mode: raw` path end-to-end once the stack is booted.
