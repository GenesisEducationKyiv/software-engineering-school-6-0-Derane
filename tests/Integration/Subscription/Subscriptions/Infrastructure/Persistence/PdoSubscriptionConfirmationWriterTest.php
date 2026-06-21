<?php

declare(strict_types=1);

namespace Tests\Integration\Subscription\Subscriptions\Infrastructure\Persistence;

use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Real-Postgres coverage of the conditional confirm/cancel writer (FR9 reply-side
 * no-op): the `WHERE status = 'pending'` guard makes a replay a no-op and never
 * resurrects a terminal status.
 */
final class PdoSubscriptionConfirmationWriterTest extends IntegrationTestCase
{
    private SubscriptionConfirmationWriter $writer;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->c->get(SubscriptionConfirmationWriter::class);
        $this->pdo = $this->c->get(PDO::class);
    }

    public function testConfirmOnPendingSucceedsOnceThenIsANoOp(): void
    {
        $id = $this->insertSubscription('a@example.com', 'owner/repo', 'pending');

        $this->assertTrue($this->writer->confirm($id));
        $this->assertSame('confirmed', $this->statusOf($id));

        // Replayed reply: the row is no longer pending -> rowCount() = 0 -> no-op.
        $this->assertFalse($this->writer->confirm($id));
        $this->assertSame('confirmed', $this->statusOf($id));
    }

    public function testCancelOnPendingSucceedsOnceThenIsANoOp(): void
    {
        $id = $this->insertSubscription('b@example.com', 'owner/repo', 'pending');

        $this->assertTrue($this->writer->cancel($id));
        $this->assertSame('cancelled', $this->statusOf($id));

        $this->assertFalse($this->writer->cancel($id));
        $this->assertSame('cancelled', $this->statusOf($id));
    }

    public function testConfirmOnCancelledIsNoOpNoResurrection(): void
    {
        $id = $this->insertSubscription('c@example.com', 'owner/repo', 'cancelled');

        $this->assertFalse($this->writer->confirm($id));
        $this->assertSame('cancelled', $this->statusOf($id));
    }

    public function testCancelOnConfirmedIsNoOp(): void
    {
        $id = $this->insertSubscription('d@example.com', 'owner/repo', 'confirmed');

        $this->assertFalse($this->writer->cancel($id));
        $this->assertSame('confirmed', $this->statusOf($id));
    }

    private function insertSubscription(string $email, string $repository, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (email, repository, status)
             VALUES (:email, :repository, :status)
             RETURNING id'
        );
        $stmt->execute([':email' => $email, ':repository' => $repository, ':status' => $status]);

        return (int) $stmt->fetchColumn();
    }

    private function statusOf(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM subscriptions WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (string) $stmt->fetchColumn();
    }
}
