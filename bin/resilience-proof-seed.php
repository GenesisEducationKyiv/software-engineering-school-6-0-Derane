<?php

/**
 * E3 (AC-1/AC-3) helper — seeds ONE fresh smoke release + subscription and runs
 * ONE scan cycle, mirroring `bin/scanner-smoke.php`'s seed/dispatch idiom
 * (smoke repo via `GITHUB_SMOKE_*` env + `TrackedRepositoryRegistrar`/
 * `SubscriptionRepository` + `CommandBus::dispatch(new ScanReleasesCommand())`)
 * but WITHOUT its MailHog poll — `bin/resilience-proof.sh` seeds N of these
 * (one process per fresh release, since `SmokeGitHubReleaseSource` is
 * single-repo/single-release per process by design — see its constructor) and
 * polls MailHog itself, once, for the aggregate `total == N`.
 *
 * Token is supplied by the caller via $RESILIENCE_TOKEN so the orchestrating
 * shell script can correlate seeded releases with observed queue/MailHog state
 * across N invocations. Prints the seeded repository's `full_name` to stdout
 * on success (the shell script captures it for the post-scan `last_seen_tag`
 * assertion in AC-3).
 *
 * Read-only with respect to wire contracts: dispatches the same
 * `ScanReleasesCommand` / `CommandBus` / `SubscriptionRepository` /
 * `TrackedRepositoryRegistrar` surfaces `bin/scanner-smoke.php` already uses.
 *
 * @psalm-api consumed only as a CLI entry point by bin/resilience-proof.sh
 */

declare(strict_types=1);

use App\Migration\Migrator;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;

require __DIR__ . '/../vendor/autoload.php';

$token = $_ENV['RESILIENCE_TOKEN'] ?? ('resilience-' . bin2hex(random_bytes(4)));
$repository = 'resilience/repo-' . $token;
$email = $token . '@example.test';

$_ENV['GITHUB_SMOKE'] = 'true';
$_ENV['GITHUB_SMOKE_REPOSITORY'] = $repository;
$_ENV['GITHUB_SMOKE_TAG_NAME'] = 'v0-' . $token;
$_ENV['GITHUB_SMOKE_NAME'] = 'Resilience Release ' . $token;
$_ENV['GITHUB_SMOKE_HTML_URL'] = 'https://example.test/releases/' . $token;
$_ENV['GITHUB_SMOKE_BODY'] = 'Resilience proof release body ' . $token;
$_ENV['GITHUB_SMOKE_PUBLISHED_AT'] = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339);

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

$container->get(Migrator::class)->migrate();

$container->get(TrackedRepositoryRegistrar::class)->ensureExists($repository);
$container->get(SubscriptionRepository::class)->create(
    Subscription::subscribe(
        new EmailAddress($email),
        new RepositoryName($repository),
        (new DateTimeImmutable())->format(DateTimeInterface::RFC3339)
    )
);

$container->get(CommandBus::class)->dispatch(new ScanReleasesCommand());

fwrite(STDOUT, $repository . PHP_EOL);
exit(0);
