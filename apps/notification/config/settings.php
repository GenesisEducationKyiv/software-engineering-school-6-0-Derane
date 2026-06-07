<?php

declare(strict_types=1);

// Env-array settings loader (Decision §5 — read directly from $_ENV/getenv(),
// no phpdotenv: this service has no local-dev .env workflow yet, D6 wires it
// into compose where variables arrive as real container env vars; mirrors the
// monolith's config/settings.php shape so D5/D6 can extend it the same way).
return [
    'notification_db' => [
        'host' => $_ENV['NOTIFICATION_DB_HOST'] ?? 'notification-db',
        'port' => $_ENV['NOTIFICATION_DB_PORT'] ?? '5432',
        'name' => $_ENV['NOTIFICATION_DB_NAME'] ?? 'release_notifications',
        'user' => $_ENV['NOTIFICATION_DB_USER'] ?? 'notification',
        'password' => $_ENV['NOTIFICATION_DB_PASSWORD'] ?? 'secret',
    ],
    'rabbitmq' => [
        'host' => $_ENV['RABBITMQ_HOST'] ?? 'rabbitmq',
        'port' => (int) ($_ENV['RABBITMQ_PORT'] ?? 5672),
        'user' => $_ENV['RABBITMQ_USER'] ?? 'guest',
        'password' => $_ENV['RABBITMQ_PASSWORD'] ?? 'guest',
        'vhost' => $_ENV['RABBITMQ_VHOST'] ?? '/',
    ],
    'smtp' => [
        'host' => $_ENV['SMTP_HOST'] ?? 'mailhog',
        'port' => (int) ($_ENV['SMTP_PORT'] ?? 1025),
        'user' => $_ENV['SMTP_USER'] ?? '',
        'password' => $_ENV['SMTP_PASSWORD'] ?? '',
        'from' => $_ENV['SMTP_FROM'] ?? 'noreply@release-notifier.local',
        'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? '',
    ],
];
