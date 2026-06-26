#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Migration\Migrator;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

/** @var \PDO $pdo */
$pdo = $container->get(\PDO::class);

(new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();
fwrite(STDOUT, "Migrations completed.\n");
