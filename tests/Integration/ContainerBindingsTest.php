<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\HealthController;
use App\Controller\SubscriptionController;
use App\Grpc\ReleaseNotifierService;
use App\Service\ScannerService;
use App\Service\SubscriptionServiceInterface;

/**
 * Container smoke test for the DB-backed critical bindings — the integration
 * counterpart of {@see \Tests\Config\ContainerTest::testCriticalDatabaselessBindingsResolve}.
 * Resolving the full graph here catches a DI regression in the database-backed
 * subtree (repositories, services, controllers, gRPC) that the unit suite cannot
 * boot.
 */
final class ContainerBindingsTest extends IntegrationTestCase
{
    public function testDatabaseBackedCriticalBindingsResolve(): void
    {
        $bindings = [
            SubscriptionServiceInterface::class,
            SubscriptionController::class,
            HealthController::class,
            ScannerService::class,
            ReleaseNotifierService::class,
        ];

        foreach ($bindings as $id) {
            self::assertIsObject($this->c->get($id), "{$id} should resolve");
        }
    }
}
