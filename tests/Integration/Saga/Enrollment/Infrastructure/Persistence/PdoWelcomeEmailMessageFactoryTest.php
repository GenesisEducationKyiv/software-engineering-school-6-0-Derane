<?php

declare(strict_types=1);

namespace Tests\Integration\Saga\Enrollment\Infrastructure\Persistence;

use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\SagaState;
use App\Saga\Enrollment\Domain\WelcomeEmailMessageFactory;
use App\Shared\Domain\ValueObject\SagaId;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * The relay's message factory resolves (email, repository) for the saga's
 * subscription off the real subscriptions table (a primitive-keyed cross-table
 * read, no Subscription.Domain edge).
 */
final class PdoWelcomeEmailMessageFactoryTest extends IntegrationTestCase
{
    public function testBuildsSendWelcomeEmailFromTheSubscriptionRow(): void
    {
        $pdo = $this->c->get(PDO::class);
        $pdo->exec("INSERT INTO subscriptions (email, repository) VALUES ('person@example.com', 'owner/repo')");
        $subscriptionId = (int) $pdo->lastInsertId('subscriptions_id_seq');

        /** @var WelcomeEmailMessageFactory $factory */
        $factory = $this->c->get(WelcomeEmailMessageFactory::class);

        $message = $factory->forSaga($this->sagaFor($subscriptionId));

        $this->assertSame('SendWelcomeEmail/v1', $message->schema);
        $this->assertSame($subscriptionId, $message->subscriptionId);
        $this->assertSame('person@example.com', $message->email->value());
        $this->assertSame('owner/repo', $message->repository->value());
        $this->assertSame('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', $message->sagaId);
    }

    public function testThrowsWhenTheSubscriptionIsMissing(): void
    {
        /** @var WelcomeEmailMessageFactory $factory */
        $factory = $this->c->get(WelcomeEmailMessageFactory::class);

        $this->expectException(\RuntimeException::class);

        $factory->forSaga($this->sagaFor(999999));
    }

    private function sagaFor(int $subscriptionId): EnrollmentSaga
    {
        return EnrollmentSaga::reconstitute(
            SagaId::fromString('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33'),
            $subscriptionId,
            SagaState::Started,
            null,
        );
    }
}
