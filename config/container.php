<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\MetricsController;
use App\Controller\SubscriptionController;
use App\Cache\GitHubCacheInterface;
use App\Cache\RedisGitHubCache;
use App\Factory\MailerFactoryInterface;
use App\Factory\PHPMailerFactory;
use App\Grpc\ReleaseNotifierService;
use App\Migration\Migrator;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionRepositoryInterface;
use App\Service\GitHubService;
use App\Service\GitHubServiceInterface;
use App\Service\MetricsService;
use App\Service\MetricsServiceInterface;
use App\Service\NotifierInterface;
use App\Service\NotifierService;
use App\Service\ScannerService;
use App\Service\SubscriptionService;
use App\Service\SubscriptionServiceInterface;
use DI\Container;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return static function (array $settings): Container {
    $containerBuilder = new ContainerBuilder();

    $containerBuilder->addDefinitions([
        'settings' => $settings,

        LoggerInterface::class => static function () {
            $logger = new Logger('app');
            $logger->pushHandler(new StreamHandler('php://stderr'));
            return $logger;
        },

        PDO::class => static function () use ($settings) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $settings['db']['host'],
                $settings['db']['port'],
                $settings['db']['name']
            );
            $pdo = new PDO($dsn, $settings['db']['user'], $settings['db']['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        },

        RedisClient::class => static function () use ($settings) {
            return new RedisClient([
                'scheme' => 'tcp',
                'host' => $settings['redis']['host'],
                'port' => $settings['redis']['port'],
            ]);
        },

        ResponseFactoryInterface::class => static fn() => new ResponseFactory(),
        GuzzleClient::class => static fn() => new GuzzleClient(),
        MailerFactoryInterface::class => static fn() => new PHPMailerFactory(),
        GitHubCacheInterface::class => static fn($c) => new RedisGitHubCache(
            $c->get(RedisClient::class),
            $c->get(LoggerInterface::class)
        ),

        SubscriptionRepositoryInterface::class => static fn($c) => new SubscriptionRepository($c->get(PDO::class)),
        GitHubServiceInterface::class => static function ($c) use ($settings) {
            return new GitHubService(
                $c->get(GuzzleClient::class),
                $c->get(GitHubCacheInterface::class),
                $c->get(LoggerInterface::class),
                $settings['github']['token'],
                $settings['redis']['cache_ttl']
            );
        },
        NotifierInterface::class => static function ($c) use ($settings) {
            return new NotifierService(
                $settings['smtp'],
                $c->get(LoggerInterface::class),
                $c->get(MailerFactoryInterface::class)
            );
        },
        MetricsServiceInterface::class => static fn($c) => new MetricsService(
            $c->get(SubscriptionRepositoryInterface::class)
        ),
        SubscriptionServiceInterface::class => static fn($c) => new SubscriptionService(
            $c->get(SubscriptionRepositoryInterface::class),
            $c->get(GitHubServiceInterface::class),
            $c->get(LoggerInterface::class)
        ),
        ScannerService::class => static fn($c) => new ScannerService(
            $c->get(SubscriptionRepositoryInterface::class),
            $c->get(GitHubServiceInterface::class),
            $c->get(NotifierInterface::class),
            $c->get(LoggerInterface::class),
            $settings['github']['scan_batch_size']
        ),

        SubscriptionController::class => static fn($c) => new SubscriptionController(
            $c->get(SubscriptionServiceInterface::class)
        ),
        ReleaseNotifierService::class => static fn($c) => new ReleaseNotifierService(
            $c->get(SubscriptionServiceInterface::class),
            $c->get(PDO::class),
            $c->get(LoggerInterface::class)
        ),
        MetricsController::class => static fn($c) => new MetricsController(
            $c->get(MetricsServiceInterface::class)
        ),
        HealthController::class => static fn($c) => new HealthController(
            $c->get(PDO::class),
            $c->get(LoggerInterface::class)
        ),
        ApiKeyMiddleware::class => static fn($c) => new ApiKeyMiddleware(
            $settings['api_key'],
            $c->get(ResponseFactoryInterface::class)
        ),
        ErrorHandlerMiddleware::class => static fn($c) => new ErrorHandlerMiddleware(
            $c->get(LoggerInterface::class),
            $c->get(ResponseFactoryInterface::class)
        ),

        Migrator::class => static fn($c) => new Migrator(
            $c->get(PDO::class),
            dirname(__DIR__) . '/migrations',
            $c->get(LoggerInterface::class)
        ),
    ]);

    return $containerBuilder->build();
};
