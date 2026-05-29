<?php

declare(strict_types=1);

$app = require __DIR__ . '/../config/app.php';

do {
    $running = \frankenphp_handle_request(static function () use ($app) {
        $app->run();
    });
} while ($running);
