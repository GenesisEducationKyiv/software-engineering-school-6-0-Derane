#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

/** @var \PDO $pdo */
$pdo = $container->get(\PDO::class);

$retentionDays = (int) ($_ENV['RETENTION_DAYS'] ?? 90);
$stmt = $pdo->prepare(
    'DELETE FROM release_notifications WHERE sent_at < NOW() - INTERVAL :interval'
);
$stmt->execute(['interval' => $retentionDays . ' days']);
$deleted = $stmt->rowCount();

fwrite(STDOUT, sprintf("Purged %d release_notification row(s) older than %d days.\n", $deleted, $retentionDays));
