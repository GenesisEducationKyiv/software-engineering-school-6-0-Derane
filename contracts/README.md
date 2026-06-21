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

A second guard, `tests/Notification/Publishing/Contract/SendReleaseEmailGoldenUnchangedTest.php`,
pins this file byte-for-byte (sha256) so the welcome-saga work cannot perturb the
frozen release shape (FR13).

## `send-welcome-email.v1.json`

The canonical `SendWelcomeEmail/v1` integration command the monolith saga-worker
(relay) publishes to the `notifications` exchange (routing key
`subscription.welcome-email`) and the notification service consumes off
`notifications.welcome-email`. Correlation is by AMQP `correlation_id = sagaId`
plus the primitive `subscriptionId`; the consumer tolerates unknown fields.

Both sides assert against this exact file:

- **Producer (monolith):** `tests/Saga/Enrollment/Infrastructure/Rabbit/Contract/SendWelcomeEmailWireContractTest.php`
  — `SendWelcomeEmailSerializer::toArray()` must equal this file (keys, order, types).
- **Consumer (notification):** `apps/notification/tests/Unit/Sending/Infrastructure/Rabbit/SendWelcomeEmailWireContractTest.php`
  — `SendWelcomeEmailMessageMapper::fromJson()` must map this file into a `WelcomeEmail`,
  tolerate an extra unknown field, and reject a wrong `schema`.

## `welcome-email-outcome.v1.json`

The canonical `WelcomeEmailOutcome/v1` reply the notification service publishes
back to the `notifications` exchange (routing key `subscription.welcome-email.reply`)
and the monolith saga-worker consumes off `notifications.welcome-email-reply`.
`outcome` ∈ `{ "sent", "failed" }`; `error` is a short string only on `failed`,
`null` otherwise. This file is the `sent` exemplar. The consumer tolerates unknown
fields.

Both sides assert against this exact file:

- **Producer (notification):** `apps/notification/tests/Unit/Sending/Infrastructure/Rabbit/WelcomeEmailOutcomeWireContractTest.php`
  — `WelcomeOutcomeSerializer::toArray()` must equal this file (keys, order, types);
  the `failed`/`sent` two-state of `error` is proven against the same key set.
- **Consumer (monolith):** `tests/Saga/Enrollment/Infrastructure/Rabbit/Contract/WelcomeEmailOutcomeWireContractTest.php`
  — `WelcomeEmailOutcomeMessageMapper::fromJson()` must map this file into a
  `HandleWelcomeEmailOutcomeCommand`, tolerate an extra unknown field, and reject a
  wrong `schema`.

Both welcome contracts follow the same additive-only versioning rule: a breaking
change is a new `/v2` file plus a coordinated update on both sides, never an
in-place mutation of v1.
