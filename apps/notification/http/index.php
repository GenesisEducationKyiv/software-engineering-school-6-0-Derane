<?php

declare(strict_types=1);

use App\Sending\Infrastructure\Http\ErrorHandlerMiddleware;
use App\Sending\Infrastructure\Http\HealthController;
use App\Sending\Infrastructure\Http\MetricsController;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->add($container->get(ErrorHandlerMiddleware::class));
$app->get('/health', HealthController::class);
$app->get('/metrics', MetricsController::class);

return $app;
