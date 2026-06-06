<?php

declare(strict_types=1);

use App\Cache\GitHubCacheInterface;
use App\Cache\RedisGitHubCache;
use App\Cache\SafeGitHubCacheDecorator;
use App\Config\Factory\SmtpConfigFactory;
use App\Config\Factory\SmtpConfigFactoryInterface;
use App\Config\SmtpConfig;
use App\Controller\HealthController;
use App\Controller\MetricsController;
use App\Domain\Factory\ReleaseFactory;
use App\Domain\Factory\ReleaseFactoryInterface;
use App\Domain\Factory\RepositoryStatusFactory;
use App\Domain\Factory\RepositoryStatusFactoryInterface;
use App\Exception\ExceptionStatusMap;
use App\Factory\MailerFactoryInterface;
use App\Factory\PHPMailerFactory;
use App\GitHub\GitHubApiClient;
use App\GitHub\GitHubApiClientInterface;
use App\GitHub\GitHubReleaseCache;
use App\GitHub\GitHubRepositoryCache;
use App\GitHub\LatestReleaseCacheInterface;
use App\GitHub\RepositoryExistenceCacheInterface;
use App\Grpc\ReleaseNotifierService;
use App\Health\DatabaseHealthCheck;
use App\Health\HealthCheckInterface;
use App\Metrics\PrometheusFormatter;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Migration\Migrator;
use App\Notifier\MailerInterface;
use App\Notifier\ReleaseEmailRenderer;
use App\Notifier\SmtpMailer;
use App\Repository\MetricsRepository;
use App\Repository\MetricsRepositoryInterface;
use App\Repository\NotificationLedger;
use App\Repository\NotificationLedgerInterface;
use App\Repository\RepositoryStatusReader;
use App\Repository\ScanCandidateSource;
use App\Repository\ScanProgressWriter;
use App\Repository\TrackedRepositoryReader;
use App\Repository\TrackedRepositoryRegistrar;
use App\Repository\TrackedRepositoryWriter;
use App\Service\GitHubService;
use App\Service\GitHubServiceInterface;
use Tests\Support\FakeGitHubService;
use App\Service\MetricsService;
use App\Service\MetricsServiceInterface;
use App\Service\NotificationDispatcher;
use App\Service\NotificationDispatcherInterface;
use App\Service\NotifierInterface;
use App\Service\NotifierService;
use App\Service\ReleaseDetector;
use App\Service\ScannerService;
use App\Shared\Application\Pagination\PaginationFactory;
use App\Shared\Application\Pagination\PaginationFactoryInterface;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Shared\Infrastructure\Bus\InMemoryCommandBus;
use App\Shared\Infrastructure\Bus\InMemoryQueryBus;
use App\Shared\Infrastructure\Event\InMemoryEventDispatcher;
use App\Shared\Infrastructure\Event\ListenerProvider;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryHandler;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryQuery;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdHandler;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdQuery;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsHandler;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsQuery;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommand;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommandHandler;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriberRefFactory;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriberRefFactoryInterface;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactory;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactoryInterface;
use App\Subscription\Subscriptions\Infrastructure\Http\SubscriptionController;
use App\Subscription\Subscriptions\Infrastructure\Persistence\PdoSubscriptionRepository;
use App\Subscription\Subscriptions\Application\Validation\SubscriptionValidator;
use App\Validation\EmailValidator;
use App\Validation\RepositoryNameValidator;
use DI\Container;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
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
        GitHubCacheInterface::class => static fn($c) => new SafeGitHubCacheDecorator(
            new RedisGitHubCache($c->get(RedisClient::class)),
            $c->get(LoggerInterface::class)
        ),

        // Domain factories — injected for testability.
        ReleaseFactoryInterface::class => static fn() => new ReleaseFactory(),
        SubscriptionFactoryInterface::class => static fn() => new SubscriptionFactory(),
        SubscriberRefFactoryInterface::class => static fn() => new SubscriberRefFactory(),
        RepositoryStatusFactoryInterface::class => static fn() => new RepositoryStatusFactory(),

        // Config factories
        SmtpConfigFactoryInterface::class => static fn() => new SmtpConfigFactory(),
        PaginationFactoryInterface::class => static fn() => new PaginationFactory(),

        // Validation
        EmailValidator::class => static fn() => new EmailValidator(),
        RepositoryNameValidator::class => static fn() => new RepositoryNameValidator(),
        SubscriptionValidator::class => static fn($c) => new SubscriptionValidator(
            $c->get(EmailValidator::class),
            $c->get(RepositoryNameValidator::class)
        ),

        // Repositories
        SubscriptionRepository::class => static fn($c) => new PdoSubscriptionRepository(
            $c->get(PDO::class),
            $c->get(SubscriptionFactoryInterface::class),
            $c->get(SubscriberRefFactoryInterface::class)
        ),
        SubscriberFinder::class => static fn($c) => $c->get(SubscriptionRepository::class),
        RepositoryStatusReader::class => static fn($c) => new TrackedRepositoryReader(
            $c->get(PDO::class),
            $c->get(RepositoryStatusFactoryInterface::class)
        ),
        ScanCandidateSource::class => static fn($c) => $c->get(RepositoryStatusReader::class),
        TrackedRepositoryRegistrar::class => static fn($c) => new TrackedRepositoryWriter(
            $c->get(PDO::class)
        ),
        ScanProgressWriter::class => static fn($c) => $c->get(TrackedRepositoryRegistrar::class),
        NotificationLedgerInterface::class => static fn($c) => new NotificationLedger($c->get(PDO::class)),
        MetricsRepositoryInterface::class => static fn($c) => new MetricsRepository($c->get(PDO::class)),

        // Health + exception mapping
        HealthCheckInterface::class => static fn($c) => new DatabaseHealthCheck($c->get(PDO::class)),
        ExceptionStatusMap::class => static fn() => new ExceptionStatusMap(),

        // In-process PSR-14 event plane (empty listener map until flows wire in P2–P5)
        ListenerProviderInterface::class => static fn() => new ListenerProvider([]),
        EventDispatcherInterface::class => static fn($c) => new InMemoryEventDispatcher(
            $c->get(ListenerProviderInterface::class)
        ),

        // Subscription context — CQRS handlers (B1)
        SubscribeCommandHandler::class => static fn($c) => new SubscribeCommandHandler(
            $c->get(SubscriptionRepository::class),
            $c->get(GitHubServiceInterface::class),
            $c->get(TrackedRepositoryRegistrar::class),
            $c->get(SubscriptionValidator::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(LoggerInterface::class)
        ),
        UnsubscribeCommandHandler::class => static fn($c) => new UnsubscribeCommandHandler(
            $c->get(SubscriptionRepository::class),
            $c->get(LoggerInterface::class)
        ),
        FindSubscriptionByIdHandler::class => static fn($c) => new FindSubscriptionByIdHandler(
            $c->get(SubscriptionRepository::class)
        ),
        FindSubscriptionByEmailAndRepositoryHandler::class => static fn($c) =>
            new FindSubscriptionByEmailAndRepositoryHandler(
                $c->get(SubscriptionRepository::class)
            ),
        ListSubscriptionsHandler::class => static fn($c) => new ListSubscriptionsHandler(
            $c->get(SubscriptionRepository::class)
        ),

        // In-house CQRS buses (handler maps filled per context across Epic B)
        CommandBus::class => static fn($c) => new InMemoryCommandBus([
            SubscribeCommand::class => $c->get(SubscribeCommandHandler::class),
            UnsubscribeCommand::class => $c->get(UnsubscribeCommandHandler::class),
        ]),
        QueryBus::class => static fn($c) => new InMemoryQueryBus([
            FindSubscriptionByIdQuery::class => $c->get(FindSubscriptionByIdHandler::class),
            FindSubscriptionByEmailAndRepositoryQuery::class =>
                $c->get(FindSubscriptionByEmailAndRepositoryHandler::class),
            ListSubscriptionsQuery::class => $c->get(ListSubscriptionsHandler::class),
        ]),

        // Notifier
        SmtpConfig::class => static fn($c) => $c->get(SmtpConfigFactoryInterface::class)->fromArray($settings['smtp']),
        ReleaseEmailRenderer::class => static fn() => new ReleaseEmailRenderer(),
        MailerInterface::class => static fn($c) => new SmtpMailer(
            $c->get(SmtpConfig::class),
            $c->get(MailerFactoryInterface::class)
        ),
        NotifierInterface::class => static fn($c) => new NotifierService(
            $c->get(MailerInterface::class),
            $c->get(ReleaseEmailRenderer::class),
            $c->get(LoggerInterface::class)
        ),

        // GitHub
        GitHubApiClientInterface::class => static fn($c) => new GitHubApiClient(
            $c->get(GuzzleClient::class),
            $settings['github']['token']
        ),
        RepositoryExistenceCacheInterface::class => static fn($c) => new GitHubRepositoryCache(
            $c->get(GitHubCacheInterface::class),
            $settings['redis']['cache_ttl']
        ),
        LatestReleaseCacheInterface::class => static fn($c) => new GitHubReleaseCache(
            $c->get(GitHubCacheInterface::class),
            $c->get(ReleaseFactoryInterface::class),
            $settings['redis']['cache_ttl']
        ),
        GitHubServiceInterface::class => static function ($c) use ($settings) {
            if ($settings['github']['stub']) {
                return new FakeGitHubService();
            }

            return new GitHubService(
                $c->get(GitHubApiClientInterface::class),
                $c->get(RepositoryExistenceCacheInterface::class),
                $c->get(LatestReleaseCacheInterface::class),
                $c->get(ReleaseFactoryInterface::class),
                $c->get(LoggerInterface::class)
            );
        },

        // Metrics
        PrometheusFormatter::class => static fn() => new PrometheusFormatter(),
        MetricsServiceInterface::class => static fn($c) => new MetricsService(
            $c->get(MetricsRepositoryInterface::class),
            $c->get(PrometheusFormatter::class)
        ),

        // Application services
        ReleaseDetector::class => static fn($c) => new ReleaseDetector(
            $c->get(GitHubServiceInterface::class),
            $c->get(RepositoryStatusReader::class),
            $c->get(LoggerInterface::class)
        ),
        NotificationDispatcherInterface::class => static fn($c) => new NotificationDispatcher(
            $c->get(SubscriberFinder::class),
            $c->get(NotificationLedgerInterface::class),
            $c->get(NotifierInterface::class)
        ),
        ScannerService::class => static fn($c) => new ScannerService(
            $c->get(ScanCandidateSource::class),
            $c->get(ScanProgressWriter::class),
            $c->get(ReleaseDetector::class),
            $c->get(NotificationDispatcherInterface::class),
            $c->get(LoggerInterface::class),
            $settings['github']['scan_batch_size']
        ),

        // Boundaries
        SubscriptionController::class => static fn($c) => new SubscriptionController(
            $c->get(CommandBus::class),
            $c->get(QueryBus::class),
            $c->get(PaginationFactoryInterface::class)
        ),
        ReleaseNotifierService::class => static fn($c) => new ReleaseNotifierService(
            $c->get(CommandBus::class),
            $c->get(QueryBus::class),
            $c->get(HealthCheckInterface::class),
            $c->get(ExceptionStatusMap::class),
            $c->get(PaginationFactoryInterface::class),
            $c->get(LoggerInterface::class)
        ),
        MetricsController::class => static fn($c) => new MetricsController(
            $c->get(MetricsServiceInterface::class)
        ),
        HealthController::class => static fn($c) => new HealthController(
            $c->get(HealthCheckInterface::class),
            $c->get(LoggerInterface::class)
        ),
        ApiKeyMiddleware::class => static fn($c) => new ApiKeyMiddleware(
            $settings['api_key'],
            $c->get(ResponseFactoryInterface::class)
        ),
        ErrorHandlerMiddleware::class => static fn($c) => new ErrorHandlerMiddleware(
            $c->get(LoggerInterface::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get(ExceptionStatusMap::class)
        ),

        Migrator::class => static fn($c) => new Migrator(
            $c->get(PDO::class),
            dirname(__DIR__) . '/migrations',
            $c->get(LoggerInterface::class)
        ),
    ]);

    return $containerBuilder->build();
};
