# Manual Test Evidence Checklist — Resilience Proof (NFR3 / AC5)

Use this checklist when a BMAD run requires human-supplied evidence in addition
to the automated `make resilience-proof` CI check. Do not mark items complete
unless they were observed in a fresh run of the current branch.

- **Tester:**
- **Date:**
- **Environment:** local host / CI rerun / other:
- **Command:** `RESILIENCE_RUN_ID=<unique-deterministic-id> make resilience-proof` (plain `make resilience-proof` is acceptable in GitHub Actions because the workflow sets `RESILIENCE_RUN_ID` from run metadata)
- **BMAD evidence path:** set `BMAD_REVIEW_MANUAL_EVIDENCE` to this completed evidence file when invoking the gate.
- **Result:** `ALL SCENARIOS PASSED` observed: [ ]

## Scenario A — RabbitMQ Down (AC-2 / AC-3 / AR-FLOW2)

- [ ] `rabbitmq` was stopped by the proof.
- [ ] REST `GET /health` returned 200 while `rabbitmq` was down.
- [ ] REST `POST /api/subscriptions` returned 200 or 201 while `rabbitmq` was down.
- [ ] gRPC `CreateSubscription` returned the requested `email` and `repository` while `rabbitmq` was down.
- [ ] Broker-down scan left `last_seen_tag` empty after run #1.
- [ ] Broker-down re-run left `last_seen_tag` empty after run #2.
- [ ] REST `GET /health` and `POST /api/subscriptions` still succeeded after the failed scan cycles.
- [ ] gRPC `CreateSubscription` still succeeded after the failed scan cycles.
- [ ] `rabbitmq` was restarted and management API became reachable.

## Scenario B — notification-svc Down, RabbitMQ Up (AC-1)

- [ ] MailHog mailbox was reset to `total == 0`.
- [ ] `notification-svc` was stopped by the proof while `rabbitmq` stayed up.
- [ ] REST `GET /health` returned 200 while `notification-svc` was down.
- [ ] REST `POST /api/subscriptions` returned 200 or 201 while `notification-svc` was down.
- [ ] gRPC `CreateSubscription` returned the requested `email` and `repository` while `notification-svc` was down.
- [ ] At least 2 fresh releases were seeded and scanned.
- [ ] Queue `notifications.send-email` reported `messages_ready` increased by at least 2.
- [ ] REST `GET /health` and `POST /api/subscriptions` still succeeded after queue buffering.
- [ ] gRPC `CreateSubscription` still succeeded after queue buffering.
- [ ] After `notification-svc` restarted, bounded MailHog polling reached `total == 2`.

## Notes

- Attach or paste the relevant proof log excerpts for each checked item.
- Record any non-default ports or environment overrides.
- If a step fails, keep the failed output and do not mark the manual evidence as passing.
