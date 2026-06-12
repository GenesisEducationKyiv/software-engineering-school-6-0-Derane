# Automated Test Index — Key FR/NFR Coverage

This file maps critical functional requirements and architectural invariants to their
automated test locations, for reviewers who cannot exhaustively traverse 210+ test files.

## FR5 / AR-FLOW2: Publish failure aborts marker advancement (outbox-free guarantee)

**Test**: `testDoesNotMarkTheReleaseSeenWhenTheNewReleaseDetectedDispatchThrows`
**File**: `tests/Scanning/Scanner/Application/ScanReleases/ScanReleasesHandlerTest.php:205`
**What it proves**: When `EventDispatcherInterface::dispatch()` throws (mirroring
`WhenNewReleaseDetectedThenPublishReleaseEmails` propagating a `RabbitPublisher` failure),
`markReleaseSeen()` is **never called**. This is the load-bearing outbox-free invariant:
a publish failure keeps the release marker un-advanced, so the scanner retries on the
next cycle rather than silently losing the notification.

**Additional context**: `testContinuesToTheNextRepositoryAfterAGenericScanError` (line 248)
proves the per-repo `catch(\Exception)` swallows the error and continues the batch — so
the publish-failure does not abort the entire scan cycle, only the affected repository's
marker advancement.

## FR9: Bounded retry → DLQ (N-retry exhaustion)

**Test**: `testRoutesToDlqWhenRedeliveryBoundExceeded`
**File**: `apps/notification/tests/Unit/Sending/Infrastructure/Rabbit/SendReleaseEmailConsumerTest.php:269`
**What it proves**: A message carrying `x-retry-count = MAX_REDELIVERIES` (currently 3)
that fails to process is routed to the DLQ via `nack(requeue: false)` instead of being
re-queued. This exercises the full path: `shouldRouteToDlq()` returns true → consumer
nacks to DLQ → `recordDlq` metric fired.

**Complementary test**: `testRequeuesOnTransientFailureBelowRedeliveryBound` (line 173)
proves the mirror: `x-retry-count = MAX_REDELIVERIES - 1` on a failing message triggers
`requeueWithRetry()` (publish-then-ack) instead of DLQ routing.

**RabbitConsumer boundary tests**: `RabbitConsumerTest::testShouldRouteToDlqFiresOnlyWhenTheRedeliveryBoundIsExceeded`
(tests/Shared/Infrastructure/Messaging/Rabbit/RabbitConsumerTest.php:108) uses a data
provider `[below, at, one-over, far-over] × [false, false, true, true]` to prove the
exact boundary condition (count > maxRedeliveries, not >=).

## FR8 / NFR1: Idempotency — redelivery dedupe

**Test**: `IdempotencyProofTest`
**File**: `apps/notification/tests/Integration/Sending/Infrastructure/IdempotencyProofTest.php`
**What it proves**: A redelivered message (same `subscription_id + repository + tag_name`)
is deduplicated by the `UNIQUE` constraint in the ledger — the second delivery is acked
without re-sending email.

**Crash-window caveat**: The window between `$mailer->send()` success and `$ledger->markSent()`
is acknowledged as an at-most-once duplicate risk (PRD §6 NFR1). This is a documented
architectural trade-off (no SMTP-side idempotency keys), not a testable invariant. Bounded
to ≤1 duplicate per claim-lease window.

## DLQ topology (integration)

**Test**: `DlqRoutingProofTest::testMalformedMessageIsDeadLetteredAndCountedWithoutTouchingTheLedger`
**File**: `apps/notification/tests/Integration/Sending/Infrastructure/DlqRoutingProofTest.php:29`
**What it proves**: Malformed JSON is routed straight to the DLQ (bypassing retry bound)
without creating ledger rows, and the `dlq_total` metric is incremented.
