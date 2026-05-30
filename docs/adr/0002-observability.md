# 2. Observability: structured logging to ELK, RED metrics to Prometheus, Grafana dashboards

- **Status:** Accepted
- **Date:** 2026-05-30
- **Scope:** All three runtimes (HTTP API, gRPC API, scanner). Adds a dev-only
  observability overlay; the application code change is production-relevant.

## Context

The service had no real observability: logs were unstructured (default Monolog line
formatter to stderr, no correlation id) and `/metrics` exposed only point-in-time
business gauges from Postgres — no request **R**ate, **E**rror rate or **D**uration for
HTTP/gRPC, nothing for the scanner.

We needed structured logs searchable in Kibana, RED metrics in Prometheus, and a Grafana
dashboard, under two constraints:

- **Observability must never change a business outcome.** A slow or down logging,
  metrics, or shipping dependency must not fail, slow, or alter a request, gRPC call, or
  scan cycle.
- The app runs as **three share-nothing processes**, so anything cross-process must be
  carried by shared infrastructure, not in-process state.

## Decision

Observability is split into two planes with different owners.

### Transport plane — what crossed the wire

Edge instrumentation only the HTTP/gRPC boundary can see (method, route, status, duration,
correlation id):

- **HTTP**: `CorrelationIdMiddleware` (mint/propagate the id), `RouteTagMiddleware` (capture
  the bounded route *pattern* inside routing), and `RequestMetricsMiddleware` (RED metrics +
  a `http request handled` access log) outside routing so it sees every response, incl. 404/405.
- **gRPC**: `MeasuredInvoker` decorates RoadRunner's `InvokerInterface` — the single dispatch
  point — for RED metrics and a `grpc call handled` access log.
- Server-side failures get one `error` log where transport owns failure:
  `ErrorHandlerMiddleware` (HTTP 500), `MeasuredInvoker` (gRPC `INTERNAL`).

### Application-event plane — business facts

Business facts ("subscription created", "scan cycle completed") are not logged or metered
inline. Services depend on an app-owned `EventPublisherInterface` and publish anemic
`App\Application\Event\*` events; listeners decide how each fact becomes a metric or log.
Services carry no `LoggerInterface` or `ScanMetrics` dependency.

- Events are built through narrow per-consumer `*EventFactoryInterface`s implemented by one
  `ApplicationEventFactory`, which is also the **sanitization boundary**: a `\Throwable` is
  reduced to scalar `errorClass`/`errorMessage`, so events stay serialization-safe and no
  live exception reaches a sink.
- `ObservabilityListenerProvider` is the single event → sink wiring. Sinks: `ScanMetricsListener`
  (scanner RED/throughput, incl. the `rate_limit|error|cycle` taxonomy) and `ApplicationEventLogger`
  (logs with a stable `event=` field — `subscription.created`, `release.detected`, … — so Kibana
  aggregates on a field, not message text).

### Fail-open at every sink

The "never change a business outcome" force is enforced in types and adapters:

- **`EventPublisherInterface.publish()` is no-throw** — narrower than PSR-14, whose dispatch lets
  listener exceptions bubble. `EventDispatcher` isolates each listener; a failure is caught and
  reported by `FallbackLogger` (a stderr JSON line shaped like a Monolog record so Filebeat keeps
  it, bypassing the Monolog/listeners that failed).
- **`SafeMetricsStorage`** wraps the Redis Prometheus store so a metric *write* never throws out of
  the `finally` blocks that record RED (a Redis outage is swallowed, logged once). Reads (`collect()`)
  still propagate, so a failed `/metrics` scrape surfaces honestly as target-down.

### PII redaction

Subscriber emails travel in events and errors; redaction is centralized, not per-call-site.
`EmailRedactingProcessor` (a Monolog processor) masks emails (`j***@example.com`) in the message and
every nested `context`/`extra` string of every record, so generic error paths get the same protection
as event logs. With the factory's exception-to-scalar sanitization, raw PII is never indexed in ES.

### Logging pipeline → Filebeat → Elasticsearch → Kibana

Monolog emits **JSON** to stderr with `PsrLogMessageProcessor`, `EmailRedactingProcessor`, and a
`ContextProcessor` stamping `component`, `env`, `correlation_id`. The id lives in a request-scoped
`CorrelationContext`: set by `CorrelationIdMiddleware` (honouring inbound `X-Request-Id`), by
`MeasuredInvoker` per gRPC call (inbound `x-request-id` metadata), and per scanner cycle; minted by a
shared `CorrelationIdGeneratorInterface` otherwise, so a trace spans services. **Filebeat** tails
container stdout, decodes the JSON, drops records without `extra.component`, and ships to Elasticsearch;
**Kibana** reads it (no Logstash — already structured). A one-shot, idempotent **`kibana-setup`** creates
the `release-notifier-logs-*` data view so logs are searchable on first `make obs-up`.

### RED metrics → Prometheus → Grafana

**`promphp/prometheus_client_php`** over a Redis backend (wrapped by `SafeMetricsStorage`): all three
processes write to the same Redis, so the HTTP `/metrics` renders the whole registry and **Prometheus
scrapes one target** yet sees HTTP + gRPC + scanner. Recorders sit behind `HttpMetrics`/`GrpcMetrics`/`ScanMetrics`;
HTTP RED from `RequestMetricsMiddleware` (reusing `ExceptionStatusMap` for thrown-exception status), gRPC
from `MeasuredInvoker` (`StatusCode`→name in `GrpcStatusName`), scanner from `ScanMetricsListener`.
`MetricsService` renders everything incl. business gauges (from `MetricsRepository`), so the `/metrics`
contract holds; the hand-rolled formatter is retired. **Grafana** is provisioned with the datasource and
a RED dashboard. A `docker-compose.observability.yml` overlay (`make obs-up`) adds ELK + Prometheus +
Grafana and per-process `APP_COMPONENT`/`LOG_FORMAT`.

## Alternatives considered

- **Services log/meter directly (no event plane).** The first cut; scattered the metric taxonomy and log
  wording across services and coupled them to observability. The event plane keeps every sink decision in
  one place.
- **Publish to PSR-14's `EventDispatcherInterface`.** Lets listener exceptions bubble; the fail-open
  guarantee belongs in the type services depend on.
- **Async/queued delivery, or per-sink masking.** Overkill / fragile: synchronous fan-out with cheap,
  isolated listeners is enough, and one redaction processor beats masking at each call site.
- **Monolog `ElasticsearchHandler` (app → ES).** Couples the request path to ES; stdout + Filebeat keeps
  logging a local, non-throwing write (Logstash similarly unneeded — logs are already JSON).
- **Per-process metrics endpoints / Pushgateway, or hand-rolled counters.** A shared Redis registry is
  simpler and idiomatic for promphp's multi-process design.

## Consequences

### Positive

- Searchable, correlated logs and standard RED signals; one scrape target for all three processes.
- Business logic is decoupled from observability — services publish facts; each becomes a metric/log in
  one place.
- **Fail-open end-to-end**: a Redis/ES outage or a buggy listener cannot change a business outcome
  (`SafeMetricsStorage`, `EventPublisher` isolation + `FallbackLogger`, stderr logs).
- Subscriber PII is masked and exceptions reduced to scalars before leaving the process.

### Negative / Neutral

- **Per-role aggregate metrics** (shared Redis, no instance label) — scaling a role to multiple replicas
  needs a per-instance label or per-process exporters.
- **Redis on the metrics path, best-effort**: an outage silently drops metric writes (warned once) instead
  of breaking requests; `/metrics` still fails honestly (scrape down).
- **Indirection + synchronous fan-out**: a fact is published then translated by a listener; a slow listener
  adds latency (acceptable — listeners are cheap and isolated).
- **Masking is lossy** (the full email can't be recovered) — by design.
- **gRPC log shipping needs `logs.mode: raw`** in `.rr.grpc.yaml` so RoadRunner doesn't wrap worker stderr;
  the metrics path is unaffected if removed.
- **More moving parts in dev** — the ELK/Prometheus/Grafana overlay is dev-only (CI uses `METRICS_STORAGE=memory`).

## Follow-ups

- Prometheus alerting rules (error-ratio / latency SLOs) + Alertmanager.
- ECS-aligned log field names for tighter Elastic integration.
- Per-instance label / dedicated exporter if any role scales beyond one replica.
