<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\MetricsController;
use App\Controller\SubscriptionController;
use App\Migration\Migrator;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\RequestMetricsMiddleware;
use App\Middleware\RouteTagMiddleware;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$settings = require __DIR__ . '/settings.php';
$buildContainer = require __DIR__ . '/container.php';
$container = $buildContainer($settings);

if (($settings['bootstrap']['run_migrations_on_boot'] ?? false) === true) {
    $container->get(Migrator::class)->migrate();
}

AppFactory::setContainer($container);
$app = AppFactory::create();

// Middleware execution order (outermost first):
//   CorrelationId -> RequestMetrics -> ErrorHandler -> Routing -> RouteTag -> ApiKey -> BodyParsing -> handler
// RequestMetrics runs OUTSIDE routing + error handling so it records every response
// (including 404/405 raised by the router); RouteTag runs INSIDE routing to capture
// the matched route pattern for the metric label. LIFO: later add() == outer layer.
$app->addBodyParsingMiddleware();
$app->add($container->get(ApiKeyMiddleware::class));
$app->add($container->get(RouteTagMiddleware::class));
$app->addRoutingMiddleware();
$app->add($container->get(ErrorHandlerMiddleware::class));
$app->add($container->get(RequestMetricsMiddleware::class));
$app->add($container->get(CorrelationIdMiddleware::class));

$app->get('/health', HealthController::class);
$app->get('/metrics', MetricsController::class);

$app->post('/api/subscriptions', [SubscriptionController::class, 'create']);
$app->get('/api/subscriptions', [SubscriptionController::class, 'list']);
$app->get('/api/subscriptions/{id}', [SubscriptionController::class, 'get']);
$app->delete('/api/subscriptions/{id}', [SubscriptionController::class, 'delete']);

$app->get('/', function ($request, $response) {
    $html = file_get_contents(__DIR__ . '/../public/index.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

return $app;
