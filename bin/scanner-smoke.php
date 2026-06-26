<?php

declare(strict_types=1);

use App\Migration\Migrator;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;

require __DIR__ . '/../vendor/autoload.php';

$token = 'smoke-' . bin2hex(random_bytes(4));
$repository = 'smoke/repo-' . $token;
$email = $token . '@example.test';

$_ENV['GITHUB_SMOKE'] = 'true';
$_ENV['GITHUB_SMOKE_REPOSITORY'] = $repository;
$_ENV['GITHUB_SMOKE_TAG_NAME'] = 'v0-' . $token;
$_ENV['GITHUB_SMOKE_NAME'] = 'Smoke Release ' . $token;
$_ENV['GITHUB_SMOKE_HTML_URL'] = 'https://example.test/releases/' . $token;
$_ENV['GITHUB_SMOKE_BODY'] = 'Smoke release body ' . $token;
$_ENV['GITHUB_SMOKE_PUBLISHED_AT'] = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339);

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

$container->get(Migrator::class)->migrate();

// Purge MailHog first so this run starts from a clean mailbox and the delivery
// assertion below cannot false-pass on a message left by a previous run.
@file_get_contents('http://mailhog:8025/api/v1/messages', false, stream_context_create([
    'http' => ['method' => 'DELETE', 'timeout' => 2, 'ignore_errors' => true],
]));

// Drive the real Subscribe use-case through the bus (validation, repository-exists
// guard, tracked-repository registration, and SubscriptionCreated dispatch) instead
// of hand-building the aggregate and reaching across context boundaries.
$container->get(CommandBus::class)->dispatch(new SubscribeCommand($email, $repository));

$container->get(CommandBus::class)->dispatch(new ScanReleasesCommand());

$mailhogUrl = 'http://mailhog:8025/api/v2/messages';
$deadline = time() + 20;
$needle = $token;

do {
    $body = @file_get_contents($mailhogUrl);
    if (is_string($body) && str_contains($body, $needle)) {
        fwrite(STDOUT, sprintf("Scanner smoke delivery observed in MailHog for token %s\n", $needle));
        exit(0);
    }

    usleep(500000);
} while (time() < $deadline);

fwrite(STDERR, sprintf("Scanner smoke delivery not observed in MailHog for token %s\n", $needle));
exit(1);
