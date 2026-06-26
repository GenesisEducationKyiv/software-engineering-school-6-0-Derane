<?php

declare(strict_types=1);

/**
 * Example 2: Fixing Domain → PDO / SQL / Predis violations
 *
 * VIOLATION:
 *   RepositoryTracking.Domain must not depend on Shared.Infrastructure
 *     src/RepositoryTracking/Repositories/Domain/TrackedRepository.php:8
 *       uses PDO
 *
 * (Equivalent shapes: a Predis\Client imported into Domain, or raw SQL executed
 *  directly inside a Domain entity/service.)
 *
 * Fix: declare a per-consumer PORT in Domain (ISP: *Reader / *Writer /
 * *Registrar / *Source) and implement the PDO ADAPTER in Infrastructure.
 * Schema changes go in raw SQL migrations (migrations/00X_*.sql) — there is no
 * ORM and no mapping annotations anywhere. Predis is only ever the GitHub-API
 * cache and lives in Releases/Sourcing/Infrastructure/Cache/ — never in Domain.
 */

// ============================================================================
// BEFORE (WRONG) — Domain reaches straight into PDO + raw SQL
// ============================================================================

namespace Example\RepositoryTracking\Repositories\Domain;

use PDO; // VIOLATION! persistence detail in Domain

final class TrackedRepositoryBefore
{
    public function __construct(private PDO $pdo) // VIOLATION!
    {
    }

    public function ensureExists(string $fullName): void
    {
        // VIOLATION! raw SQL executed inside the Domain
        $stmt = $this->pdo->prepare(
            'INSERT INTO repositories (full_name) VALUES (:full_name) ON CONFLICT DO NOTHING'
        );
        $stmt->execute(['full_name' => $fullName]);
    }

    public function markReleaseSeen(string $fullName, string $tag): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_seen_tag = :tag WHERE full_name = :repository'
        );
        $stmt->execute(['tag' => $tag, 'repository' => $fullName]);
    }
}

// ============================================================================
// AFTER (CORRECT) — per-consumer Domain PORTS (no PDO anywhere in Domain)
//
// Per-consumer ISP: split the contract into narrow roles so each caller depends
// only on what it uses. These mirror the REAL ports in
// src/RepositoryTracking/Repositories/Domain/.
// ============================================================================

namespace Example\RepositoryTracking\Repositories\Domain;

/**
 * Port: idempotent registration of a repository in the scan registry. Called
 * from the Subscription context when a new subscription references a repository.
 *
 * @psalm-api
 */
interface TrackedRepositoryRegistrar
{
    public function ensureExists(string $fullName): void;
}

namespace Example\RepositoryTracking\Repositories\Domain;

/**
 * Port: advance scan progress markers for a tracked repository.
 *
 * @psalm-api
 */
interface ScanProgressWriter
{
    public function markChecked(string $fullName): void;

    public function markReleaseSeen(string $fullName, string $tag): void;
}

namespace Example\RepositoryTracking\Repositories\Domain;

/**
 * Port: read the current scan state of a tracked repository. Returns null when
 * the repository has not been registered yet.
 *
 * @psalm-api
 */
interface RepositoryStatusReader
{
    public function getStatus(string $fullName): ?RepositoryStatus;
}

// ============================================================================
// DOMAIN — anemic readonly snapshot built through a *FactoryInterface (no from*)
// ============================================================================

namespace Example\RepositoryTracking\Repositories\Domain;

/** @psalm-api */
final readonly class RepositoryStatus
{
    public function __construct(
        public string $fullName,
        public ?string $lastSeenTag,
        public ?string $lastCheckedAt
    ) {
    }
}

namespace Example\RepositoryTracking\Repositories\Infrastructure\Factory;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;

/**
 * Anemic DTO snapshots are constructed through a *FactoryInterface — never via a
 * from* static method on the DTO itself.
 *
 * @psalm-api
 */
interface RepositoryStatusFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): RepositoryStatus;
}

namespace Example\RepositoryTracking\Repositories\Infrastructure\Factory;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;

/** @psalm-api */
final readonly class RepositoryStatusFactory implements RepositoryStatusFactoryInterface
{
    #[\Override]
    public function fromRow(array $row): RepositoryStatus
    {
        /** @var array{full_name: string, last_seen_tag: ?string, last_checked_at: ?string} $row */
        return new RepositoryStatus(
            $row['full_name'],
            $row['last_seen_tag'],
            $row['last_checked_at']
        );
    }
}

// ============================================================================
// INFRASTRUCTURE — PDO ADAPTERS implement the Domain ports (PostgreSQL, raw SQL)
//
// One class may implement several narrow interfaces (per-consumer ISP). This
// mirrors src/RepositoryTracking/Repositories/Infrastructure/Persistence/.
// ============================================================================

namespace Example\RepositoryTracking\Repositories\Infrastructure\Persistence;

use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use PDO;

/** @psalm-api */
final readonly class PdoTrackedRepositoryWriter implements TrackedRepositoryRegistrar, ScanProgressWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function ensureExists(string $fullName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO repositories (full_name) VALUES (:full_name) ON CONFLICT (full_name) DO NOTHING'
        );
        $stmt->execute(['full_name' => $fullName]);
    }

    #[\Override]
    public function markChecked(string $fullName): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['repository' => $fullName]);
    }

    #[\Override]
    public function markReleaseSeen(string $fullName, string $tag): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_seen_tag = :tag, last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['tag' => $tag, 'repository' => $fullName]);
    }
}

namespace Example\RepositoryTracking\Repositories\Infrastructure\Persistence;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\RepositoryTracking\Repositories\Infrastructure\Factory\RepositoryStatusFactoryInterface;
use PDO;

/** @psalm-api */
final readonly class PdoTrackedRepositoryReader implements RepositoryStatusReader
{
    public function __construct(
        private PDO $pdo,
        private RepositoryStatusFactoryInterface $statusFactory
    ) {
    }

    #[\Override]
    public function getStatus(string $fullName): ?RepositoryStatus
    {
        $stmt = $this->pdo->prepare('SELECT * FROM repositories WHERE full_name = :full_name');
        $stmt->execute(['full_name' => $fullName]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->statusFactory->fromRow($row) : null;
    }
}

// ============================================================================
// APPLICATION — consumers depend on the PORT only (never on PDO or the adapter)
// ============================================================================

namespace Example\Subscription\Subscriptions\Application\Subscribe;

use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;

/**
 * The handler needs only the *Registrar role — not the reader, not the writer.
 * DI binds PdoTrackedRepositoryWriter to TrackedRepositoryRegistrar.
 *
 * @psalm-api
 */
final readonly class RegisterRepositoryStep
{
    public function __construct(private TrackedRepositoryRegistrar $trackedRepositories)
    {
    }

    public function __invoke(string $fullName): void
    {
        $this->trackedRepositories->ensureExists($fullName);
    }
}

// ============================================================================
// PREDIS NOTE — cache is GitHub-API only, never Domain state
//
// A Predis\Client in any Domain is always a violation. The cache lives behind a
// cache port in Releases/Sourcing/Infrastructure/Cache/ (RedisGitHubCache,
// wrapped by SafeGitHubCacheDecorator). Domain never knows the cache exists.
// ============================================================================

/*
File: migrations/006_add_last_seen_tag_to_repositories.sql

ALTER TABLE repositories ADD COLUMN IF NOT EXISTS last_seen_tag TEXT;
ALTER TABLE repositories ADD COLUMN IF NOT EXISTS last_checked_at TIMESTAMPTZ;

-- Schema lives in raw SQL migrations. NEVER mapping annotations in Domain.
*/

// ============================================================================
// KEY POINTS:
// 1. Domain declares per-consumer PORTS (*Registrar / *Writer / *Reader) — no PDO.
// 2. Infrastructure PDO adapters implement those ports with raw SQL (PostgreSQL).
// 3. One adapter may implement several narrow interfaces (ISP).
// 4. Anemic snapshots are built via a *FactoryInterface, not a from* static method.
// 5. Schema changes go in migrations/00X_*.sql — no ORM, no annotations.
// 6. Predis is the GitHub-API cache only, in Releases Infrastructure — never Domain.
// 7. DI binds interfaces only; alias a second interface to share one instance.
// ============================================================================
