#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

/** @var \PDO $pdo */
$pdo = $container->get(\PDO::class);

$migrationFiles = glob(__DIR__ . '/../migrations/*.sql');
if ($migrationFiles === false) {
    throw new RuntimeException('Failed to enumerate notification migrations');
}

sort($migrationFiles);

foreach ($migrationFiles as $migrationFile) {
    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        throw new RuntimeException(sprintf('Failed to read migration file: %s', $migrationFile));
    }

    $pdo->exec($sql);
    fwrite(STDOUT, sprintf("Applied %s\n", basename($migrationFile)));
}
