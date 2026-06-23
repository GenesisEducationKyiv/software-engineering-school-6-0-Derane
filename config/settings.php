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
    'saga' => [
        // Primary sweep deadline T: AwaitingConfirmation sagas past
        // awaiting_since + T are compensated. Single source of truth, must exceed
        // the retry+claim envelope (arch §11).
        'timeout_seconds' => (int) ($_ENV['SAGA_TIMEOUT_SECONDS'] ?? 900),
        // Secondary start-sweep deadline T_start (>= T): never-published STARTED
        // sagas past created_at + T_start (the broker-down backstop, NFR3).
        'start_timeout_seconds' => (int) ($_ENV['SAGA_START_TIMEOUT_SECONDS'] ?? 900),
        // The saga-worker tick cadence and how often (in ticks) it runs the sweep.
        'worker_wait_seconds' => (int) ($_ENV['SAGA_WORKER_WAIT_SECONDS'] ?? 1),
        'sweep_every_ticks' => (int) ($_ENV['SAGA_SWEEP_EVERY_TICKS'] ?? 60),
        'relay_batch_size' => (int) ($_ENV['SAGA_RELAY_BATCH_SIZE'] ?? 50),
    ],
    // REST->gRPC welcome-email migration (Epic 3). The outbound welcome SEND leg is
    // selected by WELCOME_EMAIL_TRANSPORT (rabbit | rest | grpc); absent/unknown ->
    // rabbit (the HW9 async default, 100% intact). The reply leg + sweeper stay AMQP on
    // all transports (RD4). The welcome_sync block is the single source of truth for the
    // sync paths' deadline + bounded-retry policy (the broker-buffer/sweeper replacement).
    'welcome_email' => [
        'transport' => $_ENV['WELCOME_EMAIL_TRANSPORT'] ?? 'rabbit',
        'rest_endpoint' => $_ENV['NOTIFICATION_REST_BASE_URL'] ?? 'http://notification-svc:8081',
        'grpc_target' => $_ENV['NOTIFICATION_GRPC_TARGET'] ?? 'notification-svc:9002',
        'sync' => [
            'deadline_seconds' => (int) ($_ENV['WELCOME_EMAIL_SYNC_DEADLINE_SECONDS'] ?? 10),
            'max_attempts' => (int) ($_ENV['WELCOME_EMAIL_SYNC_MAX_ATTEMPTS'] ?? 3),
            // Comma-separated per-attempt backoff in ms (before the NEXT attempt).
            'backoff_ms' => array_values(array_filter(array_map(
                static fn (string $v): int => (int) trim($v),
                explode(',', (string) ($_ENV['WELCOME_EMAIL_SYNC_BACKOFF_MS'] ?? '200,500,1000'))
            ), static fn (int $v): bool => $v >= 0)) ?: [200, 500, 1000],
        ],
    ],
    'api_key' => $_ENV['API_KEY'] ?? '',
    'bootstrap' => [
        'run_migrations_on_boot' => filter_var(
            $_ENV['RUN_MIGRATIONS_ON_BOOT'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
];
