<?php

declare(strict_types=1);

use App\Config\Factory\SmtpConfigFactory;
use App\Config\Factory\SmtpConfigFactoryInterface;
use App\Config\SmtpConfig;
use App\Controller\HealthController;
use App\Controller\MetricsController;
use App\Factory\MailerFactoryInterface;
use App\Factory\PHPMailerFactory;
use App\Grpc\ReleaseNotifierService;
use App\Notification\Publishing\Domain\NewReleaseDetected;
use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactory;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactoryInterface;
use App\Notification\Publishing\Infrastructure\Listener\WhenNewReleaseDetectedThenPublishReleaseEmails;
use App\Notification\Publishing\Infrastructure\RabbitReleaseNotificationPublisher;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublisher;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseHandler;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseQuery;
use App\Releases\Sourcing\Application\RepositoryExists\RepositoryExistsHandler;
use App\Releases\Sourcing\Application\RepositoryExists\RepositoryExistsQuery;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Releases\Sourcing\Infrastructure\Cache\GitHubCacheInterface;
use App\Releases\Sourcing\Infrastructure\Cache\GitHubReleaseCache;
use App\Releases\Sourcing\Infrastructure\Cache\GitHubRepositoryCache;
use App\Releases\Sourcing\Infrastructure\Cache\LatestReleaseCacheInterface;
use App\Releases\Sourcing\Infrastructure\Cache\RedisGitHubCache;
use App\Releases\Sourcing\Infrastructure\Cache\RepositoryExistenceCacheInterface;
use App\Releases\Sourcing\Infrastructure\Cache\SafeGitHubCacheDecorator;
use App\Releases\Sourcing\Infrastructure\Factory\ReleaseFactory;
use App\Releases\Sourcing\Infrastructure\Factory\ReleaseFactoryInterface;
use App\Releases\Sourcing\Infrastructure\GitHubApiClient;
use App\Releases\Sourcing\Infrastructure\GitHubApiClientInterface;
use App\Releases\Sourcing\Infrastructure\GitHubApiReleaseSource;
use App\Releases\Sourcing\Infrastructure\SmokeGitHubReleaseSource;
use App\Repository\NotificationLedger;
use App\Repository\NotificationLedgerInterface;
use App\RepositoryTracking\Repositories\Application\GetDueForScan\GetDueForScanHandler;
use App\RepositoryTracking\Repositories\Application\GetDueForScan\GetDueForScanQuery;
use App\RepositoryTracking\Repositories\Application\MarkChecked\MarkCheckedCommand;
use App\RepositoryTracking\Repositories\Application\MarkChecked\MarkCheckedCommandHandler;
use App\RepositoryTracking\Repositories\Application\MarkReleaseSeen\MarkReleaseSeenCommand;
use App\RepositoryTracking\Repositories\Application\MarkReleaseSeen\MarkReleaseSeenCommandHandler;
use App\RepositoryTracking\Repositories\Application\Register\RegisterRepositoryCommand;
use App\RepositoryTracking\Repositories\Application\Register\RegisterRepositoryCommandHandler;
use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\RepositoryTracking\Repositories\Infrastructure\Factory\RepositoryStatusFactory;
use App\RepositoryTracking\Repositories\Infrastructure\Factory\RepositoryStatusFactoryInterface;
use App\RepositoryTracking\Repositories\Infrastructure\Persistence\PdoTrackedRepositoryReader;
use App\RepositoryTracking\Repositories\Infrastructure\Persistence\PdoTrackedRepositoryWriter;
use App\Scanning\Scanner\Application\NotifierInterface;
use App\Scanning\Scanner\Application\ReleaseDetector;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesHandler;
use App\Scanning\Scanner\Infrastructure\Cli\ScannerCliRunner;
use App\Scanning\Scanner\Infrastructure\Mail\MailerInterface;
use App\Scanning\Scanner\Infrastructure\Mail\ReleaseEmailRenderer;
use App\Scanning\Scanner\Infrastructure\Mail\SmtpMailer;
use App\Scanning\Scanner\Infrastructure\Mail\NotifierService;
use App\Shared\Application\Pagination\PaginationFactory;
use App\Shared\Application\Pagination\PaginationFactoryInterface;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Shared\Infrastructure\Bus\InMemoryCommandBus;
use App\Shared\Infrastructure\Bus\InMemoryQueryBus;
use App\Shared\Infrastructure\Error\ExceptionStatusMap;
use App\Shared\Infrastructure\Event\InMemoryEventDispatcher;
use App\Shared\Infrastructure\Event\ListenerProvider;
use App\Shared\Infrastructure\Health\DatabaseHealthCheck;
use App\Shared\Infrastructure\Health\HealthCheckInterface;
use App\Shared\Infrastructure\Http\ApiKeyMiddleware;
use App\Shared\Infrastructure\Http\ErrorHandlerMiddleware;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Metrics\MetricsService;
use App\Shared\Infrastructure\Metrics\MetricsServiceInterface;
use App\Shared\Infrastructure\Metrics\PrometheusFormatter;
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
use App\Subscription\Subscriptions\Application\Validation\SubscriptionValidator;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriberRefFactory;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriberRefFactoryInterface;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactory;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactoryInterface;
use App\Subscription\Subscriptions\Infrastructure\Http\SubscriptionController;
use App\Subscription\Subscriptions\Infrastructure\Persistence\PdoSubscriptionRepository;
use App\Migration\Migrator;
use App\Validation\EmailValidator;
use App\Validation\RepositoryNameValidator;
use DI\Container;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Predis\Client as RedisClient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Tests\Support\FakeGitHubService;

return static function (array $settings): Container {
    $containerBuilder = new ContainerBuilder();

    $containerBuilder->addDefinitions([
        'settings' => $settings,

        // === Infrastructure: logging, PDO, Redis, HTTP ===
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

        // === Messaging: RabbitMQ (C4 — shared transport seam, no callers yet) ===
        // Concrete-class DI key (Decision 4): there is no *Interface to bind to —
        // RabbitConnection/RabbitPublisher/RabbitConsumer ARE the lowest-level
        // adapters, mirroring the PDO::class/RedisClient::class precedent for
        // "infrastructure connection objects" above. The AMQPStreamConnection is
        // built here (the only place that opens a real socket) from the
        // 'rabbitmq' settings group, using its dedicated host/port/user/password/
        // vhost constructor arguments — never an assembled connection-URI string,
        // which is what avoids any RABBITMQ_VHOST=/ URL-encoding concern. Its
        // channel is then handed to RabbitConnection, which idempotently asserts
        // the full AR-MQ1 topology exactly once per process.
        RabbitConnection::class => static function () use ($settings) {
            $connection = new AMQPStreamConnection(
                $settings['rabbitmq']['host'],
                $settings['rabbitmq']['port'],
                $settings['rabbitmq']['user'],
                $settings['rabbitmq']['password'],
                $settings['rabbitmq']['vhost']
            );
            return new RabbitConnection($connection->channel());
        },
        RabbitPublisher::class => static fn($c) => new RabbitPublisher(
            $c->get(RabbitConnection::class),
        ),
        SendReleaseEmailSerializer::class => static fn() => new SendReleaseEmailSerializer(),
        RabbitReleaseNotificationPublisher::class => static fn($c) => new RabbitReleaseNotificationPublisher(
            $c->get(RabbitPublisher::class),
            $c->get(SendReleaseEmailSerializer::class),
        ),

        ResponseFactoryInterface::class => static fn() => new ResponseFactory(),
        GuzzleClient::class => static fn() => new GuzzleClient(),
        MailerFactoryInterface::class => static fn() => new PHPMailerFactory(),

        // === Releases context (B3) — cache + factory + client + service ===
        GitHubCacheInterface::class => static fn($c) => new SafeGitHubCacheDecorator(
            new RedisGitHubCache($c->get(RedisClient::class)),
            $c->get(LoggerInterface::class)
        ),
        ReleaseFactoryInterface::class => static fn() => new ReleaseFactory(),
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
        ReleaseSource::class => static function ($c) use ($settings) {
            if ($settings['github']['smoke']) {
                $publishedAt = $_ENV['GITHUB_SMOKE_PUBLISHED_AT']
                    ?? (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);

                return new SmokeGitHubReleaseSource([
                    'repository' => $settings['github']['smoke_repository'],
                    'tag_name' => $settings['github']['smoke_tag_name'],
                    'name' => $settings['github']['smoke_name'],
                    'html_url' => $settings['github']['smoke_html_url'],
                    'published_at' => $publishedAt,
                    'body' => $settings['github']['smoke_body'],
                ]);
            }

            if ($settings['github']['stub']) {
                return new FakeGitHubService();
            }
            return new GitHubApiReleaseSource(
                $c->get(GitHubApiClientInterface::class),
                $c->get(RepositoryExistenceCacheInterface::class),
                $c->get(LatestReleaseCacheInterface::class),
                $c->get(ReleaseFactoryInterface::class),
                $c->get(LoggerInterface::class)
            );
        },

        // Releases context CQRS handlers (B3)
        FetchLatestReleaseHandler::class => static fn($c) => new FetchLatestReleaseHandler(
            $c->get(ReleaseSource::class)
        ),
        RepositoryExistsHandler::class => static fn($c) => new RepositoryExistsHandler(
            $c->get(ReleaseSource::class)
        ),

        // === Domain factories — injected for testability ===
        SubscriptionFactoryInterface::class => static fn() => new SubscriptionFactory(),
        SubscriberRefFactoryInterface::class => static fn() => new SubscriberRefFactory(),
        RepositoryStatusFactoryInterface::class => static fn() => new RepositoryStatusFactory(),

        // Config factories
        SmtpConfigFactoryInterface::class => static fn() => new SmtpConfigFactory(),
        PaginationFactoryInterface::class => static fn() => new PaginationFactory(),

        // === Legacy.Application: Validation ===
        EmailValidator::class => static fn() => new EmailValidator(),
        RepositoryNameValidator::class => static fn() => new RepositoryNameValidator(),
        SubscriptionValidator::class => static fn($c) => new SubscriptionValidator(
            $c->get(EmailValidator::class),
            $c->get(RepositoryNameValidator::class)
        ),

        // === Repositories / persistence ports ===
        SubscriptionRepository::class => static fn($c) => new PdoSubscriptionRepository(
            $c->get(PDO::class),
            $c->get(SubscriptionFactoryInterface::class),
            $c->get(SubscriberRefFactoryInterface::class)
        ),
        SubscriberFinder::class => static fn($c) => $c->get(SubscriptionRepository::class),
        // B5: count port aliased to the same PdoSubscriptionRepository instance
        SubscriptionCountPort::class => static fn($c) => $c->get(SubscriptionRepository::class),

        RepositoryStatusReader::class => static fn($c) => new PdoTrackedRepositoryReader(
            $c->get(PDO::class),
            $c->get(RepositoryStatusFactoryInterface::class)
        ),
        ScanCandidateSource::class => static fn($c) => $c->get(RepositoryStatusReader::class),
        // B5: count port aliased to the same PdoTrackedRepositoryReader instance
        RepositoryCountPort::class => static fn($c) => $c->get(RepositoryStatusReader::class),

        TrackedRepositoryRegistrar::class => static fn($c) => new PdoTrackedRepositoryWriter(
            $c->get(PDO::class)
        ),
        ScanProgressWriter::class => static fn($c) => $c->get(TrackedRepositoryRegistrar::class),
        NotificationLedgerInterface::class => static fn($c) => new NotificationLedger($c->get(PDO::class)),

        // === Shared kernel: health + exception mapping ===
        HealthCheckInterface::class => static fn($c) => new DatabaseHealthCheck($c->get(PDO::class)),
        ExceptionStatusMap::class => static fn() => new ExceptionStatusMap(),

        // === In-process PSR-14 event plane ===
        // NewReleaseDetected (C2/E3): keep the listener callable LAZY. The
        // Rabbit publisher must not be resolved while wiring unrelated
        // HTTP/gRPC command paths such as subscription management; it should be
        // touched only when NewReleaseDetected is actually dispatched during a
        // scan cycle.
        ListenerProviderInterface::class => static fn($c) => new ListenerProvider([
            NewReleaseDetected::class => [
                static function (object $event) use ($c): void {
                    $listener = $c->get(WhenNewReleaseDetectedThenPublishReleaseEmails::class);
                    $listener($event);
                },
            ],
        ]),
        EventDispatcherInterface::class => static fn($c) => new InMemoryEventDispatcher(
            $c->get(ListenerProviderInterface::class)
        ),

        // === Subscription context — CQRS handlers (B1) ===
        SubscribeCommandHandler::class => static fn($c) => new SubscribeCommandHandler(
            $c->get(SubscriptionRepository::class),
            $c->get(ReleaseSource::class),
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

        // === RepositoryTracking context — CQRS handlers (B2) ===
        RegisterRepositoryCommandHandler::class => static fn($c) => new RegisterRepositoryCommandHandler(
            $c->get(TrackedRepositoryRegistrar::class)
        ),
        MarkCheckedCommandHandler::class => static fn($c) => new MarkCheckedCommandHandler(
            $c->get(ScanProgressWriter::class),
            $c->get(EventDispatcherInterface::class)
        ),
        MarkReleaseSeenCommandHandler::class => static fn($c) => new MarkReleaseSeenCommandHandler(
            $c->get(ScanProgressWriter::class),
            $c->get(EventDispatcherInterface::class)
        ),
        GetDueForScanHandler::class => static fn($c) => new GetDueForScanHandler(
            $c->get(ScanCandidateSource::class)
        ),

        // === In-house CQRS buses ===
        CommandBus::class => static fn($c) => new InMemoryCommandBus([
            SubscribeCommand::class => $c->get(SubscribeCommandHandler::class),
            UnsubscribeCommand::class => $c->get(UnsubscribeCommandHandler::class),
            RegisterRepositoryCommand::class => $c->get(RegisterRepositoryCommandHandler::class),
            MarkCheckedCommand::class => $c->get(MarkCheckedCommandHandler::class),
            MarkReleaseSeenCommand::class => $c->get(MarkReleaseSeenCommandHandler::class),
            ScanReleasesCommand::class => $c->get(ScanReleasesHandler::class),
        ]),
        QueryBus::class => static fn($c) => new InMemoryQueryBus([
            FindSubscriptionByIdQuery::class => $c->get(FindSubscriptionByIdHandler::class),
            FindSubscriptionByEmailAndRepositoryQuery::class =>
                $c->get(FindSubscriptionByEmailAndRepositoryHandler::class),
            ListSubscriptionsQuery::class => $c->get(ListSubscriptionsHandler::class),
            GetDueForScanQuery::class => $c->get(GetDueForScanHandler::class),
            FetchLatestReleaseQuery::class => $c->get(FetchLatestReleaseHandler::class),
            RepositoryExistsQuery::class => $c->get(RepositoryExistsHandler::class),
        ]),

        // === Scanning context — mail infrastructure (B5) ===
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

        // === Shared kernel — metrics (B5 FR2 count-port fix) ===
        PrometheusFormatter::class => static fn() => new PrometheusFormatter(),
        MetricsServiceInterface::class => static fn($c) => new MetricsService(
            $c->get(SubscriptionCountPort::class),
            $c->get(RepositoryCountPort::class),
            $c->get(PrometheusFormatter::class)
        ),

        // === Notification\Publishing context — NewReleaseDetected wiring (C2) ===
        // SendReleaseEmailFactoryInterface: C1 deliberately left it unbound
        // ("nothing calls it yet"); C2 is its first caller. No constructor deps
        // — pure UUID-generation + VO assembly — follows the *FactoryInterface
        // -> *Factory aliasing convention used for Subscription/RepositoryTracking.
        SendReleaseEmailFactoryInterface::class => static fn() => new SendReleaseEmailFactory(),
        ReleaseNotificationPublisher::class => static fn($c) => $c->get(RabbitReleaseNotificationPublisher::class),
        WhenNewReleaseDetectedThenPublishReleaseEmails::class =>
            static fn($c) => new WhenNewReleaseDetectedThenPublishReleaseEmails(
                $c->get(SubscriberFinder::class),
                $c->get(SendReleaseEmailFactoryInterface::class),
                $c->get(ReleaseNotificationPublisher::class)
            ),

        // === Scanning context — application services (B5) ===
        ReleaseDetector::class => static fn($c) => new ReleaseDetector(
            $c->get(ReleaseSource::class),
            $c->get(RepositoryStatusReader::class),
            $c->get(LoggerInterface::class)
        ),

        // === Scanning context — CQRS handler + CLI runner (B4) ===
        ScanReleasesHandler::class => static fn($c) => new ScanReleasesHandler(
            $c->get(ScanCandidateSource::class),
            $c->get(ScanProgressWriter::class),
            $c->get(ReleaseDetector::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(LoggerInterface::class),
            $settings['github']['scan_batch_size']
        ),
        ScannerCliRunner::class => static fn($c) => new ScannerCliRunner(
            $c->get(CommandBus::class),
            $c->get(LoggerInterface::class),
            $settings['github']['scan_interval']
        ),

        // === HTTP + gRPC boundaries ===
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
