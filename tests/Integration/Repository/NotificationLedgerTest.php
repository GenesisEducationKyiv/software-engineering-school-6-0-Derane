<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Repository\NotificationLedgerInterface;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PDO;
use Tests\Integration\IntegrationTestCase;

final class NotificationLedgerTest extends IntegrationTestCase
{
    private NotificationLedgerInterface $ledger;
    private SubscriptionRepository $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = $this->c->get(NotificationLedgerInterface::class);
        $this->subscriptions = $this->c->get(SubscriptionRepository::class);
    }

    public function testHasSuccessfulNotificationIsFalseBeforeRecording(): void
    {
        $sub = $this->subscribe($this->faker->safeEmail(), $this->repoName());

        $this->assertFalse(
            $this->ledger->hasSuccessfulNotification((int) $sub->id(), $sub->repository(), 'v1.0.0')
        );
    }

    public function testRecordResultMarksSuccess(): void
    {
        $sub = $this->subscribe($this->faker->safeEmail(), $this->repoName());
        $tag = 'v' . $this->faker->numerify('#.#.#');

        $this->ledger->recordResult((int) $sub->id(), $sub->repository(), $tag, true);

        $this->assertTrue(
            $this->ledger->hasSuccessfulNotification((int) $sub->id(), $sub->repository(), $tag)
        );
    }

    public function testRecordResultUpsertsOnRetry(): void
    {
        $sub = $this->subscribe($this->faker->safeEmail(), $this->repoName());
        $tag = 'v' . $this->faker->numerify('#.#.#');

        $this->ledger->recordResult((int) $sub->id(), $sub->repository(), $tag, false, 'smtp down');

        $afterFailure = $this->fetchNotificationRow((int) $sub->id(), $sub->repository(), $tag);
        $this->assertSame(1, (int) $afterFailure['attempts']);
        $this->assertSame('smtp down', $afterFailure['last_error']);
        $this->assertNull($afterFailure['sent_at']);
        $this->assertFalse(
            $this->ledger->hasSuccessfulNotification((int) $sub->id(), $sub->repository(), $tag)
        );

        $this->ledger->recordResult((int) $sub->id(), $sub->repository(), $tag, true);

        $afterSuccess = $this->fetchNotificationRow((int) $sub->id(), $sub->repository(), $tag);
        $this->assertSame(2, (int) $afterSuccess['attempts']);
        $this->assertNull($afterSuccess['last_error']);
        $this->assertNotNull($afterSuccess['sent_at']);
        $this->assertTrue(
            $this->ledger->hasSuccessfulNotification((int) $sub->id(), $sub->repository(), $tag)
        );
    }

    public function testDeletingSubscriptionCascadesNotifications(): void
    {
        $sub = $this->subscribe($this->faker->safeEmail(), $this->repoName());
        $tag = 'v' . $this->faker->numerify('#.#.#');

        $this->ledger->recordResult((int) $sub->id(), $sub->repository(), $tag, true);
        $this->assertSame(1, $this->countNotificationsFor((int) $sub->id()));

        $this->subscriptions->delete((int) $sub->id());

        $this->assertSame(
            0,
            $this->countNotificationsFor((int) $sub->id()),
            'release_notifications rows should cascade when subscription is deleted'
        );
    }

    private function subscribe(string $email, string $repository): Subscription
    {
        return $this->subscriptions->create(Subscription::subscribe(
            new EmailAddress($email),
            new RepositoryName($repository),
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        ));
    }

    /**
     * @return array{attempts: int, last_error: ?string, sent_at: ?string}
     */
    private function fetchNotificationRow(int $subscriptionId, string $repository, string $tag): array
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT attempts, last_error, sent_at
               FROM release_notifications
              WHERE subscription_id = :sid AND repository = :repo AND tag_name = :tag'
        );
        $stmt->execute(['sid' => $subscriptionId, 'repo' => $repository, 'tag' => $tag]);
        /** @var array{attempts: int, last_error: ?string, sent_at: ?string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'release_notifications row missing after recordResult');

        return $row;
    }

    private function countNotificationsFor(int $subscriptionId): int
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT COUNT(*) FROM release_notifications WHERE subscription_id = :sid'
        );
        $stmt->execute(['sid' => $subscriptionId]);

        return (int) $stmt->fetchColumn();
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
