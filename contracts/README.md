# Cross-service contracts

Single source of truth for the wire formats exchanged between the monolith
(producer) and the extracted notification service (consumer). The two sides are
independently deployable — they share **no PHP code** (deptrac forbids
`apps/notification` from depending on monolith contexts) — so a committed golden
example is what keeps their independent encode/decode implementations from
silently drifting apart.

## `send-release-email.v1.json`

The canonical `SendReleaseEmail/v1` message published to the `notifications`
exchange (routing key `release.email`) and consumed off `notifications.send-email`.

Both sides assert against this exact file:

- **Producer:** `tests/Notification/Publishing/Contract/SendReleaseEmailWireContractTest.php`
  — `SendReleaseEmailSerializer::toArray()` must equal this file (keys, order, types).
- **Consumer:** `apps/notification/tests/Unit/Sending/Infrastructure/Rabbit/SendReleaseEmailWireContractTest.php`
  — `SendReleaseEmailMessageMapper::fromJson()` must map this file into a `ReleaseEmail`.

If the producer's output drifts, the producer test fails. If you edit this file,
the consumer test fails unless the consumer agrees with the change. A breaking
shape change therefore forces a coordinated, reviewed update on both sides — and,
per the additive-only versioning rule, a new `SendReleaseEmail/v2` rather than an
in-place mutation of v1.
