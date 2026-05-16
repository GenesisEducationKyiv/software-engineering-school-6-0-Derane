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
    'github' => [
        'token' => $_ENV['GITHUB_TOKEN'] ?? '',
        'scan_interval' => (int) ($_ENV['GITHUB_SCAN_INTERVAL'] ?? 300),
        'scan_batch_size' => (int) ($_ENV['GITHUB_SCAN_BATCH_SIZE'] ?? 100),
        'stub' => filter_var($_ENV['GITHUB_STUB'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],
    'smtp' => [
        'host' => $_ENV['SMTP_HOST'] ?? 'localhost',
        'port' => (int) ($_ENV['SMTP_PORT'] ?? 1025),
        'user' => $_ENV['SMTP_USER'] ?? '',
        'password' => $_ENV['SMTP_PASSWORD'] ?? '',
        'from' => $_ENV['SMTP_FROM'] ?? 'noreply@release-notifier.local',
        'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? '',
    ],
    'api_key' => $_ENV['API_KEY'] ?? '',
    'bootstrap' => [
        'run_migrations_on_boot' => filter_var(
            $_ENV['RUN_MIGRATIONS_ON_BOOT'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
];
