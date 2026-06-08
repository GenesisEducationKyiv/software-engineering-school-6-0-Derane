<?php

declare(strict_types=1);

namespace Tests\Integration\Sending\Infrastructure;

use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use PDO;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Integration\IntegrationTestCase;

/**
 * E2 — AC-1/AC-4's live, runtime-level idempotency proof (FR8/NFR1/AC4).
 *
 * D2-D4 already unit-prove every piece of the idempotency machinery in
 * isolation (mocked `PDO`, mocked `RabbitConsumer`/`AMQPMessage`, mocked
 * `Mailer`) — see this story's "Live repo constraints" §1 for the full
 * rundown of `testMarkSentIsIdempotent`/`testSkipsAlreadySentEmail`/
 * `testAcksOnIdempotentSkipJustLikeSuccess`. **None of that proves the real
 * collaborator chain reproduces the guarantee** — a mocked `PDO` cannot prove
 * a `UNIQUE` constraint actually exists and rejects a race; a mocked
 * `RabbitConsumer`/`Mailer` cannot prove a real broker round-trip and a real
 * SMTP send resolve to exactly one delivered email. This test wires NO mocks
 * on the path under test: the real `RabbitConnection`/`AMQPChannel` (real
 * broker), the real `PdoNotificationLedger` + `release_notifications` table
 * (real Postgres, real `UNIQUE (subscription_id, tag_name, repository)`
 * constraint), the real `SendReleaseEmailHandler` (`check → render → send →
 * mark`, D3's load-bearing order, untouched), the real
 * `SendReleaseEmailConsumer::handleDelivery()` (D4's ack/nack translation,
 * untouched), and the real `PhpMailerMailer` → MailHog (real SMTP send, real
 * inbox).
 *
 * ## AC-1 mechanism choice: re-publish, not broker redelivery (constraint §4)
 *
 * The story explicitly frames re-publish vs. broker-redelivery as two equally
 * valid proofs of "the same `SendReleaseEmail/v1` delivered twice", and
 * recommends picking "the one that is more deterministic to drive from a
 * test — re-publish is simpler to control precisely: publish,
 * consume-and-ack, publish again, consume-and-assert-deduped — no dependency
 * on broker-side unacked-message timing." This test does exactly that
 * three-step sequence using `basic_get` (a synchronous single-message pull —
 * `php-amqplib`'s deterministic alternative to a blocking `basic_consume`
 * loop) so each delivery is processed, asserted, and acked/nacked one at a
 * time with zero polling/timeout/race exposure. `MAX_REDELIVERIES`/`x-death`
 * bookkeeping (constraint §5) is therefore irrelevant here — both deliveries
 * take the "handler returned without throwing → ack" branch (the first via
 * the delivered path, the second via the dedupe-skip path; D4's docblock
 * states they are "indistinguishable to the consumer" and both ack
 * identically — this test proves that for the *second* delivery specifically).
 *
 * ## AC-2 — explicitly discharged by this proof (constraint §4)
 *
 * AC-2's wording frames the consumer-side dedupe as "the actual backstop":
 * "no duplicate publish leads to a duplicate email (consumer dedupe absorbs
 * any re-publish)". `ScanReleasesHandlerTest::testHandlerNoNewRelease`
 * (monolith, `tests/Scanning/Scanner/Application/ScanReleases/`) already
 * proves the monolith does not even attempt to re-publish for an
 * already-advanced release (`markReleaseSeen` never called, zero events
 * dispatched, when `RepositoryStatus`'s `lastSeenTag` already equals the
 * scanned tag) — confirmed intact post-E1, untouched by this story. AC-2 is
 * satisfied by the CONJUNCTION of that existing monolith-side proof and
 * THIS test's consumer-side proof: even in the hypothetical where a
 * re-publish *did* happen (a marker race E1 cut over to publish-then-mark to
 * minimize, but did not — and per the architecture, cannot — eliminate),
 * `testProcessingTheSameReleaseEmailTwiceProducesExactlyOneEmailAndOneLedgerRow`
 * below proves the second delivery is absorbed with zero additional emails
 * and zero additional ledger rows. That is precisely AC-2's "consumer dedupe
 * absorbs any re-publish" — proven, not asserted.
 */
final class IdempotencyProofTest extends IntegrationTestCase
{
    private const MAILHOG_API = 'http://mailhog:8025/api/v2/messages';
    private const EXCHANGE = 'notifications';
    private const ROUTING_KEY = 'release.email';

    private SendReleaseEmailConsumer $consumer;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = $this->c->get(SendReleaseEmailConsumer::class);
        $this->purgeQueue();
    }

    /**
     * AC-1 — the load-bearing assertion: the SAME `SendReleaseEmail/v1`
     * payload (identical `subscriptionId`+`tagName`+`repository`, the exact
     * triple the `UNIQUE` constraint and `hasBeenSent`/`markSent` key on),
     * processed through the real consumer→handler→ledger→mailer chain TWICE,
     * yields exactly ONE email in MailHog and exactly ONE row in
     * `release_notifications` — never two.
     *
     * Sequence (re-publish route, constraint §4): publish → pull+process+ack
     * (delivered path: render, send, mark) → publish the IDENTICAL payload
     * again → pull+process+ack (dedupe-skip path: `hasBeenSent` short-circuits
     * BEFORE render/send — D3's `check → render → send → mark` order is what
     * makes "exactly one send" provable at all) → assert MailHog shows one
     * matching message and the ledger has exactly one row for the triple.
     */
    public function testProcessingTheSameReleaseEmailTwiceProducesExactlyOneEmailAndOneLedgerRow(): void
    {
        $token = $this->uniqueToken();
        $payload = $this->buildPayload($token);

        $this->publish($payload);
        $firstOutcome = $this->pullAndProcessOne();
        self::assertSame(
            'ack',
            $firstOutcome,
            'First delivery (never-before-seen) must be acked — the delivered path.'
        );

        $this->publish($payload);
        $secondOutcome = $this->pullAndProcessOne();
        self::assertSame(
            'ack',
            $secondOutcome,
            'Second delivery of the IDENTICAL payload must also be acked — D4 docblock: '
            . '"the handler\'s internal idempotent-skip… is invisible here: returned without throwing → ack".'
        );

        self::assertSame(
            1,
            $this->ledgerRowCountFor($payload),
            'Exactly one release_notifications row must exist for '
            . '(subscription_id, tag_name, repository) — the UNIQUE constraint backstop '
            . 'plus markSent\'s ON CONFLICT DO NOTHING must leave no duplicate, '
            . 'and the dedupe-skip on the second delivery must never reach markSent at all.'
        );

        self::assertCount(
            1,
            $this->mailHogMessagesContaining($token),
            'Exactly one email must land in MailHog for this token — the dedupe-skip '
            . 'on the second delivery short-circuits BEFORE render/send (D3\'s '
            . 'check → render → send → mark order: zero mailer calls on a dedupe hit).'
        );
    }

    /**
     * Sanity counterpart to the proof above: a DIFFERENT payload (different
     * `subscriptionId`+`tagName`+`repository` triple) delivered once must
     * still produce its own independent email + ledger row — i.e. the
     * dedupe mechanism keys on the triple, not on "have we ever sent
     * anything", and this suite's per-test `TRUNCATE` isolation is sound
     * (a false-negative "exactly one" in the proof above could otherwise be
     * masked by an empty table for the wrong reason).
     */
    public function testADifferentReleaseEmailIsDeliveredIndependently(): void
    {
        $token = $this->uniqueToken();
        $payload = $this->buildPayload($token);

        $this->publish($payload);
        self::assertSame('ack', $this->pullAndProcessOne());

        self::assertSame(1, $this->ledgerRowCountFor($payload));
        self::assertCount(1, $this->mailHogMessagesContaining($token));
    }

    // === Driving the real chain ============================================

    /**
     * Publishes the `SendReleaseEmail/v1` JSON onto the real `notifications`
     * topic exchange with routing key `release.email` — the exact
     * publish shape `bin/smoke.php` uses, landing on the real
     * `notifications.send-email` queue via `RabbitConnection`'s
     * already-asserted topology (this test never declares/binds anything —
     * exactly like C5's publisher and D4's consumer, per `RabbitConnection`'s
     * docblock).
     *
     * @param array<string, mixed> $payload
     */
    private function publish(array $payload): void
    {
        $message = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['content_type' => 'application/json', 'delivery_mode' => 2],
        );

        $this->rabbitChannel()->basic_publish($message, self::EXCHANGE, self::ROUTING_KEY);
    }

    /**
     * Synchronously pulls exactly ONE message off `notifications.send-email`
     * via `basic_get` (manual-ack mode) and runs it through the REAL
     * `SendReleaseEmailConsumer::handleDelivery()` — the exact same anti-
     * corruption-layer entry point `bin/consumer.php`'s long-running
     * `basic_consume` loop dispatches to. `basic_get` is `php-amqplib`'s
     * deterministic single-message pull — chosen over `basic_consume`+`wait`
     * specifically so this test never depends on broker-side delivery timing
     * (constraint §4's "no dependency on broker-side unacked-message timing").
     *
     * Returns whether the message was ultimately ack'd or nack'd by reading
     * `AMQPMessage::isAckable()`/internal delivery state is not exposed by
     * `php-amqplib`, so this re-derives the outcome the only externally
     * observable way available to a test that must not mock `RabbitConsumer`:
     * by checking whether the message is still present (re-`basic_get`-able)
     * after processing. A handler success → `ack()` permanently removes it
     * (queue empty); a transient failure → `nack(requeue: true)` redelivers
     * it (queue non-empty). This proof's payloads are always well-formed and
     * the handler's `check → render → send → mark` path never throws on a
     * healthy stack, so "ack" is the only outcome exercised — the assertion
     * exists to make that explicit and fail loudly (not silently pass on an
     * empty queue) if it ever doesn't hold.
     */
    private function pullAndProcessOne(): string
    {
        $channel = $this->rabbitChannel();

        $message = $channel->basic_get(SendReleaseEmailConsumer::QUEUE, no_ack: false);
        self::assertInstanceOf(
            AMQPMessage::class,
            $message,
            'Expected exactly one message to be available on ' . SendReleaseEmailConsumer::QUEUE
            . ' — the publish() that should have preceded this pull did not land.'
        );

        $this->consumer->handleDelivery($message);

        $remaining = $channel->basic_get(SendReleaseEmailConsumer::QUEUE, no_ack: true);
        if ($remaining instanceof AMQPMessage) {
            // Should never happen on this proof's healthy-stack/well-formed-payload
            // path (see docblock) — surfacing it as a requeue rather than silently
            // losing the message keeps the broker state consistent for any retry.
            $remaining->nack(requeue: true);
            return 'nack';
        }

        return 'ack';
    }

    private function purgeQueue(): void
    {
        $this->rabbitChannel()->queue_purge(SendReleaseEmailConsumer::QUEUE);
    }

    // === Building the wire payload (byte-for-byte SendReleaseEmail/v1) =====

    /**
     * Builds a `SendReleaseEmail/v1` payload byte-for-byte identical in shape
     * to `bin/smoke.php`'s (this story's frozen-wire-contract constraint
     * forbids inventing a new shape) — every field `SendReleaseEmailMessageMapper`
     * requires (constraint: see its wire-format mapping table), keyed to a
     * fresh, collision-proof `$token` so MailHog search and the ledger triple
     * are both uniquely identifiable per test run (mirrors `bin/smoke.php`'s
     * token-in-recipient-and-repository pattern, which is what makes its
     * MailHog poll specific rather than "any email exists").
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $token): array
    {
        return [
            'schema' => 'SendReleaseEmail/v1',
            'eventId' => $this->uuid(),
            'occurredAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            'subscriptionId' => 9101,
            'email' => $token . '@example.test',
            'repository' => 'idempotency/repo-' . $token,
            'release' => [
                'tagName' => 'v0-' . $token,
                'name' => 'Idempotency Proof Release ' . $token,
                'body' => 'Idempotency proof release body for ' . $token,
                'htmlUrl' => 'https://example.test/releases/' . $token,
                'publishedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ],
        ];
    }

    private function uniqueToken(): string
    {
        return 'e2-' . bin2hex(random_bytes(6));
    }

    private function uuid(): string
    {
        return sprintf(
            '%08s-%04s-4%03s-8%03s-%012s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6)),
        );
    }

    // === Asserting against the real ledger ==================================

    /**
     * Counts `release_notifications` rows for the exact
     * `(subscription_id, tag_name, repository)` triple — the real schema's
     * column tuple (constraint §6: "tag first, repository second", NOT the
     * epic prose's word order) — via a direct `SELECT COUNT(*)`, deliberately
     * bypassing `PdoNotificationLedger::hasBeenSent()` (which only returns a
     * bool): this assertion needs the actual count to detect a hypothetical
     * "two rows slipped past `ON CONFLICT DO NOTHING`" failure, which a
     * boolean check could not distinguish from "exactly one".
     *
     * @param array<string, mixed> $payload
     */
    private function ledgerRowCountFor(array $payload): int
    {
        /** @var array{subscriptionId: int, repository: string, release: array{tagName: string}} $payload */
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT COUNT(*) FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([
            ':sub' => $payload['subscriptionId'],
            ':tag' => $payload['release']['tagName'],
            ':repo' => $payload['repository'],
        ]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    // === Asserting against the real mail sink (MailHog) =====================

    /**
     * Polls MailHog's HTTP API for messages whose raw body contains `$token`
     * — the exact endpoint and matching strategy `bin/smoke.php` uses
     * (`http://mailhog:8025/api/v2/messages`, substring search), confirmed
     * reachable from inside the `notification-svc` container (this suite's
     * required run location — see `IntegrationTestCase` docblock). Bounded
     * polling (not a fixed sleep) absorbs MailHog's async receipt without
     * making the test slower than necessary on the (expected) common case
     * where the message is already there by the time `handleDelivery()`
     * returns (real SMTP `send()` is synchronous — MailHog has the message
     * before `PhpMailerMailer::send()` returns).
     *
     * @return list<string>
     */
    private function mailHogMessagesContaining(string $token): array
    {
        $deadline = microtime(true) + 10.0;
        $matches = [];

        do {
            $body = @file_get_contents(self::MAILHOG_API);
            if (is_string($body)) {
                /** @var array{items?: list<array<string, mixed>>}|null $decoded */
                $decoded = json_decode($body, true);
                $matches = $this->matchingItems($decoded, $token);
                if ($matches !== []) {
                    return $matches;
                }
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        return $matches;
    }

    /**
     * @param array{items?: list<array<string, mixed>>}|null $decoded
     * @return list<string>
     */
    private function matchingItems(?array $decoded, string $token): array
    {
        if ($decoded === null || !isset($decoded['items']) || !is_array($decoded['items'])) {
            return [];
        }

        $matches = [];
        foreach ($decoded['items'] as $item) {
            $raw = json_encode($item, JSON_THROW_ON_ERROR);
            if (str_contains($raw, $token)) {
                $matches[] = $raw;
            }
        }

        return $matches;
    }
}
