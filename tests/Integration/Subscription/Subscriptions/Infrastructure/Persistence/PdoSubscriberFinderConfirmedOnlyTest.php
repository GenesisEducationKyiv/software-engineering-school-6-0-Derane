<?php

declare(strict_types=1);

namespace Tests\Integration\Subscription\Subscriptions\Infrastructure\Persistence;

use App\Shared\Domain\ValueObject\Pagination;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Recipient-resolution filter (FR14 / AC6a, Story E1): release emails go ONLY to
 * CONFIRMED subscribers. `findSubscribersByRepository` carries the new
 * `AND status = 'confirmed'` clause (served by idx_subscriptions_repository_status,
 * migration 004) — while the read/list SELECTs stay unfiltered, so an owner still
 * sees their own pending/cancelled rows.
 */
final class PdoSubscriberFinderConfirmedOnlyTest extends IntegrationTestCase
{
    private SubscriberFinder $finder;
    private SubscriptionRepository $repo;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = $this->c->get(SubscriberFinder::class);
        $this->repo = $this->c->get(SubscriptionRepository::class);
        $this->pdo = $this->c->get(PDO::class);
    }

    public function testResolvesOnlyConfirmedSubscriberWhenACancelledOneAlsoExists(): void
    {
        $repository = 'owner/release-filter';
        $confirmedId = $this->insertSubscription('confirmed@example.com', $repository, 'confirmed');
        $this->insertSubscription('cancelled@example.com', $repository, 'cancelled');

        $subscribers = $this->finder->findSubscribersByRepository(new RepositoryName($repository));

        $refs = iterator_to_array($subscribers);
        $this->assertCount(1, $refs);
        $this->assertSame($confirmedId, $refs[0]->id);
        $this->assertSame('confirmed@example.com', $refs[0]->email);
    }

    public function testExcludesPendingSubscribers(): void
    {
        $repository = 'owner/pending-filter';
        $confirmedId = $this->insertSubscription('confirmed@example.com', $repository, 'confirmed');
        $this->insertSubscription('pending@example.com', $repository, 'pending');

        $subscribers = $this->finder->findSubscribersByRepository(new RepositoryName($repository));

        $ids = array_map(static fn($ref) => $ref->id, iterator_to_array($subscribers));
        $this->assertSame([$confirmedId], $ids);
    }

    public function testReturnsEmptyWhenNoConfirmedSubscriberExists(): void
    {
        $repository = 'owner/none-confirmed';
        $this->insertSubscription('pending@example.com', $repository, 'pending');
        $this->insertSubscription('cancelled@example.com', $repository, 'cancelled');

        $subscribers = $this->finder->findSubscribersByRepository(new RepositoryName($repository));

        $this->assertCount(0, iterator_to_array($subscribers));
    }

    public function testReadAndListEndpointsStayUnfilteredAcrossAllStatuses(): void
    {
        // The recipient SELECT is filtered; the read/list SELECTs are NOT — the
        // owner must still see their own pending/cancelled subscriptions.
        $owner = 'owner@example.com';
        $confirmedRepo = 'owner/confirmed-read';
        $cancelledRepo = 'owner/cancelled-read';
        $pendingRepo = 'owner/pending-read';

        $confirmedId = $this->insertSubscription($owner, $confirmedRepo, 'confirmed');
        $cancelledId = $this->insertSubscription($owner, $cancelledRepo, 'cancelled');
        $pendingId = $this->insertSubscription($owner, $pendingRepo, 'pending');

        // findById sees every status.
        $this->assertSame('confirmed', $this->repo->findById($confirmedId)?->status());
        $this->assertSame('cancelled', $this->repo->findById($cancelledId)?->status());
        $this->assertSame('pending', $this->repo->findById($pendingId)?->status());

        // findByEmail lists all three rows regardless of status.
        $page = $this->repo->findByEmail($owner, new Pagination(10, 0));
        $this->assertSame(3, $page->total);
        $listedStatuses = array_map(static fn($s) => $s->status(), $page->items);
        sort($listedStatuses);
        $this->assertSame(['cancelled', 'confirmed', 'pending'], $listedStatuses);
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
}
