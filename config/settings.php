<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? '5432',
        'name' => $_ENV['DB_NAME'] ?? 'release_notifier',
        'user' => $_ENV['DB_USER'] ?? 'app',
        'password' => $_ENV['DB_PASSWORD'] ?? 'secret',
    ],
    'redis' => [
        'host' => $_ENV['REDIS_HOST'] ?? 'localhost',
        'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        'cache_ttl' => (int) ($_ENV['REDIS_CACHE_TTL'] ?? 600),
    ],
    'rabbitmq' => [
        'host' => $_ENV['RABBITMQ_HOST'] ?? 'rabbitmq',
        'port' => (int) ($_ENV['RABBITMQ_PORT'] ?? 5672),
        'user' => $_ENV['RABBITMQ_USER'] ?? 'guest',
        'password' => $_ENV['RABBITMQ_PASSWORD'] ?? 'guest',
        'vhost' => $_ENV['RABBITMQ_VHOST'] ?? '/',
    ],
    'github' => [
        'token' => $_ENV['GITHUB_TOKEN'] ?? '',
        'scan_interval' => (int) ($_ENV['GITHUB_SCAN_INTERVAL'] ?? 300),
        'scan_batch_size' => (int) ($_ENV['GITHUB_SCAN_BATCH_SIZE'] ?? 100),
        'stub' => filter_var($_ENV['GITHUB_STUB'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'smoke' => filter_var($_ENV['GITHUB_SMOKE'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'smoke_repository' => $_ENV['GITHUB_SMOKE_REPOSITORY'] ?? 'smoke/repo',
        'smoke_tag_name' => $_ENV['GITHUB_SMOKE_TAG_NAME'] ?? 'v0-smoke',
        'smoke_name' => $_ENV['GITHUB_SMOKE_NAME'] ?? 'Smoke Release',
        'smoke_html_url' => $_ENV['GITHUB_SMOKE_HTML_URL'] ?? 'https://example.test/releases/smoke',
        'smoke_published_at' => $_ENV['GITHUB_SMOKE_PUBLISHED_AT']
            ?? (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        'smoke_body' => $_ENV['GITHUB_SMOKE_BODY'] ?? 'Smoke release body',
    ],
    'api_key' => $_ENV['API_KEY'] ?? '',
    'bootstrap' => [
        'run_migrations_on_boot' => filter_var(
            $_ENV['RUN_MIGRATIONS_ON_BOOT'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
];
