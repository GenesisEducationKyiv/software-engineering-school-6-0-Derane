# Manual Test Evidence — Resilience Proof (NFR3 / AC5)

- **Tester:** valerii (executed via Claude Code on the maintainer's host)
- **Date:** 2026-06-11
- **Related requirements:** NFR3 (resilience), AC5 (monolith serves while broker/service down; queued notifications deliver on recovery), FR5 / AR-FLOW2 (publish-then-mark; marker not advanced on publish failure)
- **Command:** `make resilience-proof` (runs `bin/resilience-proof.sh` against the live docker-compose stack)
- **Note on host ports:** an unrelated project (`horoshop`) was holding host ports `5672` and `8080`, so the run was executed with remapped host ports `APP_PORT=8090 RABBITMQ_PORT=5673 RABBITMQ_MANAGEMENT_PORT=15673`. Internal (container-to-container) ports are unchanged; the proof logic is identical.
- **Result:** ✅ `ALL SCENARIOS PASSED` (exit code 0).

## Scenario A — RabbitMQ down (AC-2/AC-3)

Steps: stop `rabbitmq`; assert REST surface alive; seed a release and run two scan cycles with the broker down; assert the marker is not advanced and REST stays alive.

Observed:
- `[rabbitmq DOWN]: GET /health -> 200 (OK)`
- `[rabbitmq DOWN]: POST /api/subscriptions -> 201 (OK — REST surface alive)`
- `last_seen_tag after run #1 (rabbitmq down): ''` (marker NOT advanced — AR-FLOW2)
- `last_seen_tag after run #2 (rabbitmq still down): ''` (release re-detected, still not advanced)
- Publish failure surfaced as a scoped, logged error and the scan cycle continued without taking down the process:
  `app.ERROR: Scan error {"repository":"resilience/repo-...","error":"...Unable to connect to tcp://rabbitmq:5672..."}`
- `[rabbitmq DOWN, post-scan]: GET /health -> 200` and `POST /api/subscriptions -> 201`
- `=== SCENARIO A complete: REST/health stayed green throughout a real broker outage; AR-FLOW2 marker-gating held ===`

## Scenario B — notification-svc down, RabbitMQ up (AC-1)

Steps: reset MailHog; stop `notification-svc`; assert REST alive; seed 2 releases (broker up → publish succeeds → marker advances); assert 2 messages buffer durably in the queue; restart `notification-svc`; bounded MailHog poll until `total == 2`.

Observed:
- `[notification-svc DOWN]: GET /health -> 200` and `POST /api/subscriptions -> 201`
- Two scans with the broker up advanced the marker (`marker after publish: 'v0-resilienceB1-...'`, `'v0-resilienceB2-...'`)
- `Queue 'notifications.send-email' messages_ready AFTER seeding: 2 (delta = 2 — durable buffering confirmed, no competing consumer attached)`
- After restarting the service: `poll #2/15: MailHog total=2 (target 2)` → `Reached total == 2 after 2 attempt(s) (<= 4s)`
- `=== SCENARIO B complete: ... all 2 delivered to MailHog deterministically once the service restarted ===`

## Verdict

`=== ALL SCENARIOS PASSED ===` — NFR3 / AC5 are empirically demonstrated end-to-end: the monolith's REST/subscribe surface stays available during a real RabbitMQ outage and a real notification-service outage; publish failures leave `last_seen_tag` un-advanced (self-healing re-detection, no outbox); and durably-buffered notifications deliver deterministically once the service recovers.

The same core invariant ("the subscribe/HTTP path resolves no AMQP connection, so a broker outage cannot break it") is additionally guarded in CI by the deterministic unit test `tests/Subscription/Subscriptions/Infrastructure/SubscribePathDoesNotResolveAmqpConnectionTest.php`.
