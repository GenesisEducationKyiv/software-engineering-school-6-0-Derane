# 2. Observability: structured logging to ELK, RED metrics to Prometheus, Grafana dashboards

- **Status:** Accepted
- **Date:** 2026-05-30
- **Scope:** All three runtimes (HTTP API, gRPC API, scanner).

## TL;DR

- Structured JSON logs → Filebeat → Elasticsearch/Kibana; RED metrics → Prometheus
  (shared Redis registry, one scrape target) → Grafana.
- Two planes: transport middleware/interceptors record what crossed the wire;
  services publish application events, listeners turn them into metrics/logs.
- Everything fails open: a broken sink (Redis, ES, a buggy listener) can never
  change a business outcome.
- Subscriber emails are redacted centrally before any log leaves the process.

## Context

The service had no real observability: unstructured logs without a correlation id,
and `/metrics` exposed only business gauges — no request Rate, Errors or Duration
for HTTP/gRPC/scanner. We needed logs searchable in Kibana, RED metrics in
Prometheus, and a Grafana dashboard, under two constraints:

- **Observability must never change a business outcome** — a slow or down sink
  must not fail, slow, or alter a request, gRPC call, or scan cycle.
- The app runs as **three share-nothing processes**, so anything cross-process
  must live in shared infrastructure, not in-process state.

## Decision

**Transport plane.** Edge instrumentation only the boundary can see (method, route,
status, duration, correlation id): HTTP middleware (`CorrelationIdMiddleware`,
`RouteTagMiddleware`, `RequestMetricsMiddleware`) and the gRPC `MeasuredInvoker`
decorating RoadRunner's single dispatch point. Server-side failures get one `error`
log where transport owns the failure.

**Application-event plane.** Business facts ("subscription created", "scan cycle
completed") are not logged or metered inline. Services publish anemic events through
an app-owned `EventPublisherInterface`; listeners (`ScanMetricsListener`,
`ApplicationEventLogger`) decide how each fact becomes a metric or a log with a
stable `event=` field. The event factory is the sanitization boundary: a `\Throwable`
is reduced to scalar `errorClass`/`errorMessage`.

**Fail-open at every sink.** `EventPublisherInterface.publish()` is no-throw
(narrower than PSR-14): each listener is isolated, failures go to a stderr
`FallbackLogger`. `SafeMetricsStorage` wraps the Redis metrics store so a metric
write never throws; reads still propagate, so a failed `/metrics` scrape surfaces
honestly as target-down.

**PII redaction.** `EmailRedactingProcessor` (Monolog) masks emails in the message
and all nested context of every record — centralized, not per-call-site.

**Pipelines.** Monolog emits JSON to stderr with `component`, `env`,
`correlation_id` (honouring inbound `X-Request-Id`); Filebeat tails container
stdout and ships to Elasticsearch; Kibana reads it (no Logstash — already
structured). Metrics use `promphp/prometheus_client_php` over shared Redis, so all
three processes write to one registry and Prometheus scrapes one target. A dev-only
`docker-compose.observability.yml` overlay (`make obs-up`) provisions ELK,
Prometheus, and Grafana with the RED dashboard.

## Alternatives considered

- **Services log/meter directly (no event plane)** — scattered the metric taxonomy across services and coupled them to observability.
- **Publish to PSR-14's `EventDispatcherInterface`** — lets listener exceptions bubble; fail-open belongs in the type services depend on.
- **Async/queued delivery** — overkill; synchronous fan-out with cheap, isolated listeners is enough.
- **Monolog `ElasticsearchHandler` (app → ES)** — couples the request path to ES; stdout + Filebeat keeps logging a local, non-throwing write.
- **Per-process metrics endpoints / Pushgateway** — a shared Redis registry is simpler and idiomatic for promphp.

## Consequences

- Searchable, correlated logs and standard RED signals; business logic decoupled
  from observability; fail-open end-to-end; PII masked before leaving the process.
- **Per-role aggregate metrics** (no instance label) — scaling a role beyond one
  replica needs a per-instance label or per-process exporters.
- **Redis on the metrics path, best-effort** — an outage silently drops metric
  writes (warned once) instead of breaking requests.
- **Masking is lossy** — the full email can't be recovered, by design.
- Future: alerting rules + Alertmanager, ECS-aligned field names.
