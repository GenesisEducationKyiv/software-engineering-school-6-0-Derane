<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\MetricsController;
use App\Grpc\ReleaseNotifierService;
use App\Releases\Sourcing\Domain\NewReleaseDetected;
use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;
use App\Notification\Publishing\Domain\EventIdGenerator;
use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Domain\SendReleaseEmailFactoryInterface;
use App\Notification\Publishing\Infrastructure\Factory\SendReleaseEmailFactory;
use App\Notification\Publishing\Infrastructure\Factory\UuidV4EventIdGenerator;
use App\Notification\Publishing\Infrastructure\Listener\WhenNewReleaseDetectedThenPublishReleaseEmails;
use App\Notification\Publishing\Infrastructure\RabbitReleaseNotificationPublisher;
use App\Notification\Publishing\Infrastructure\Serialization\SendReleaseEmailSerializer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublisher;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseHandler;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseQuery;
use App\Releases\Sourcing\Application\RepositoryExists\RepositoryExistsHandler;
use App\Releases\Sourcing\Application\RepositoryExists\RepositoryExistsQuery;
use App\Releases\Sourcing\Domain\Release;
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
use App\Releases\Sourcing\Infrastructure\StubReleaseSource;
use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\RepositoryTracking\Repositories\Infrastructure\Factory\RepositoryStatusFactory;
use App\RepositoryTracking\Repositories\Infrastructure\Factory\RepositoryStatusFactoryInterface;
use App\RepositoryTracking\Repositories\Infrastructure\Persistence\PdoTrackedRepositoryReader;
use App\RepositoryTracking\Repositories\Infrastructure\Persistence\PdoTrackedRepositoryWriter;
use App\Scanning\Scanner\Application\ReleaseDetector;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesCommand;
use App\Scanning\Scanner\Application\ScanReleases\ScanReleasesHandler;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Saga\Enrollment\Domain\WelcomeEmailMessageFactory;
use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeHandler;
use App\Saga\Enrollment\Application\Relay\RelayPendingWelcomeEmails;
use App\Saga\Enrollment\Application\Sweep\SweepTimedOutSagas;
use App\Saga\Enrollment\Domain\EnrollmentSagaCountPort;
use App\Saga\Enrollment\Infrastructure\Persistence\PdoEnrollmentSagaRepository;
use App\Saga\Enrollment\Infrastructure\Persistence\PdoWelcomeEmailMessageFactory;
use App\Saga\Enrollment\Infrastructure\Rabbit\RabbitWelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\Rabbit\SendWelcomeEmailSerializer;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeConsumer;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeMessageMapper;
use App\Saga\Enrollment\Application\SagaMetricsReader;
use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Infrastructure\Persistence\PdoSagaMetricsStore;
use App\Saga\Enrollment\Infrastructure\Worker\SagaWorker;
use App\Scanning\Scanner\Infrastructure\Cli\ScannerCliRunner;
use App\Shared\Application\Pagination\PaginationFactory;
use App\Shared\Application\Pagination\PaginationFactoryInterface;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Shared\Domain\Clock;
use App\Shared\Domain\TransactionManager;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Shared\Infrastructure\Bus\InMemoryCommandBus;
use App\Shared\Infrastructure\Bus\InMemoryQueryBus;
use App\Shared\Infrastructure\Clock\SystemClock;
use App\Shared\Infrastructure\Error\ExceptionStatusMap;
use App\Shared\Infrastructure\Event\InMemoryEventDispatcher;
use App\Shared\Infrastructure\Event\ListenerProvider;
use App\Shared\Infrastructure\Persistence\PdoTransactionManager;
use App\Shared\Infrastructure\Health\DatabaseHealthCheck;
use App\Shared\Infrastructure\Health\HealthCheckInterface;
use App\Shared\Infrastructure\Http\ApiKeyMiddleware;
use App\Shared\Infrastructure\Http\ErrorHandlerMiddleware;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use App\Shared\Infrastructure\Metrics\MetricsService;
use App\Shared\Infrastructure\Metrics\MetricsServiceInterface;
use App\Shared\Infrastructure\Metrics\PrometheusFormatter;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryHandler;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryQuery;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdHandler;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdQuery;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsHandler;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsQuery;
use App\Subscription\Subscriptions\Application\SubscriptionResponseFactory;
use App\Subscription\Subscriptions\Application\SubscriptionResponseFactoryInterface;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommand;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommandHandler;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;
use App\Subscription\Subscriptions\Domain\SubscriptionCreated;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriberRefFactory;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriberRefFactoryInterface;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactory;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactoryInterface;
use App\Subscription\Subscriptions\Infrastructure\Http\SubscriptionController;
use App\Subscription\Subscriptions\Infrastructure\Listener\WhenSubscriptionCreatedThenLog;
use App\Subscription\Subscriptions\Infrastructure\Persistence\PdoSubscriptionConfirmationWriter;
use App\Subscription\Subscriptions\Infrastructure\Persistence\PdoSubscriptionRepository;
use App\Migration\Migrator;
use DI\Container;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PhpAmqpLib\Connection\AMQPStreamConnection;
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
            $handler = new StreamHandler('php://stderr');
            $handler->setFormatter(new JsonFormatter());
            $logger->pushHandler($handler);
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

        RabbitConnection::class => static function () use ($settings) {
            $connection = new AMQPStreamConnection(
                $settings['rabbitmq']['host'],
                $settings['rabbitmq']['port'],
                $settings['rabbitmq']['user'],
                $settings['rabbitmq']['password'],
                $settings['rabbitmq']['vhost'],
                // read_write_timeout must exceed the publisher's confirm-wait (5s)
                // so a healthy-but-slow confirm is not killed by the socket
                // timeout — php-amqplib defaults read_write_timeout to 3s, which
                // is *below* that wait. keepalive lets the OS detect a dead peer
                // across the scanner's idle gaps. No app-level heartbeat here:
                // the scanner publishes lazily then sleeps between cycles with no
                // heartbeat sender, so a negotiated heartbeat would make the
                // broker drop the idle connection. Connection-loss recovery is by
                // supervised restart (restart: unless-stopped); a reconnecting
                // connection factory for the publisher is tracked as follow-up.
                read_write_timeout: 60,
                keepalive: true,
            );
            return new RabbitConnection($connection->channel());
        },
        RabbitPublisher::class => static fn($c) => new RabbitPublisher(
            $c->get(RabbitConnection::class),
        ),
        SendReleaseEmailSerializer::class => static fn() => new SendReleaseEmailSerializer(),

        ResponseFactoryInterface::class => static fn() => new ResponseFactory(),
        GuzzleClient::class => static fn() => new GuzzleClient(),
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
                return new SmokeGitHubReleaseSource(
                    new RepositoryName($settings['github']['smoke_repository']),
                    new Release(
                        $settings['github']['smoke_tag_name'],
                        $settings['github']['smoke_name'],
                        $settings['github']['smoke_html_url'],
                        $settings['github']['smoke_published_at'],
                        $settings['github']['smoke_body'],
                    ),
                );
            }

            if ($settings['github']['stub']) {
                return new StubReleaseSource();
            }
            return new GitHubApiReleaseSource(
                $c->get(GitHubApiClientInterface::class),
                $c->get(RepositoryExistenceCacheInterface::class),
                $c->get(LatestReleaseCacheInterface::class),
                $c->get(ReleaseFactoryInterface::class),
                $c->get(LoggerInterface::class)
            );
        },

        FetchLatestReleaseHandler::class => static fn($c) => new FetchLatestReleaseHandler(
            $c->get(ReleaseSource::class)
        ),
        RepositoryExistsHandler::class => static fn($c) => new RepositoryExistsHandler(
            $c->get(ReleaseSource::class)
        ),

        SubscriptionFactoryInterface::class => static fn() => new SubscriptionFactory(),
        SubscriberRefFactoryInterface::class => static fn() => new SubscriberRefFactory(),
        RepositoryStatusFactoryInterface::class => static fn() => new RepositoryStatusFactory(),

        PaginationFactoryInterface::class => static fn() => new PaginationFactory(),

        SubscriptionRepository::class => static fn($c) => new PdoSubscriptionRepository(
            $c->get(PDO::class),
            $c->get(SubscriptionFactoryInterface::class),
            $c->get(SubscriberRefFactoryInterface::class)
        ),
        SubscriberFinder::class => static fn($c) => $c->get(SubscriptionRepository::class),
        SubscriptionCountPort::class => static fn($c) => $c->get(SubscriptionRepository::class),

        // HW9 B3: the conditional confirm/cancel writer (Saga.Application ->
        // Subscription.Domain edge), on the same shared PDO::class.
        SubscriptionConfirmationWriter::class => static fn($c) => new PdoSubscriptionConfirmationWriter(
            $c->get(PDO::class)
        ),

        // HW9 B2: the single explicit DB-transaction boundary over the SAME shared
        // PDO::class the saga repo and subscription repo receive, so they enlist in
        // the one transaction it opens (atomic start, FR2/FR3).
        TransactionManager::class => static fn($c) => new PdoTransactionManager(
            $c->get(PDO::class)
        ),

        // HW9 B1: the durable saga state store. ISP-alias the Reader/Writer narrow
        // ports to the one Starter instance (per-consumer ISP).
        EnrollmentSagaStarter::class => static fn($c) => new PdoEnrollmentSagaRepository(
            $c->get(PDO::class),
            $c->get(Clock::class)
        ),
        EnrollmentSagaReader::class => static fn($c) => $c->get(EnrollmentSagaStarter::class),
        EnrollmentSagaWriter::class => static fn($c) => $c->get(EnrollmentSagaStarter::class),
        EnrollmentSagaCountPort::class => static fn($c) => $c->get(EnrollmentSagaStarter::class),

        // HW9 D5: the monolith saga-metrics counter table (006 saga_metrics),
        // incremented at the D1 relay / D3 reply consumer / D4 sweeper call-sites
        // and read back as Gauges by MetricsService.
        SagaMetricsRecorder::class => static fn($c) => new PdoSagaMetricsStore(
            $c->get(PDO::class)
        ),
        SagaMetricsReader::class => static fn($c) => $c->get(SagaMetricsRecorder::class),
        PdoSagaMetricsStore::class => static fn($c) => $c->get(SagaMetricsRecorder::class),

        // HW9 D1: the outbox relay's publish adapter + serializer + the message
        // factory that resolves (email, repository) for a due saga's subscription.
        SendWelcomeEmailSerializer::class => static fn() => new SendWelcomeEmailSerializer(),
        // H1: the relay opens its OWN dedicated confirm-mode channel on the shared
        // RabbitConnection — it must NOT reuse RabbitPublisher (whose channel is the
        // worker's long-lived consume channel; confirm_select on it corrupts wait()).
        WelcomeEmailRelay::class => static fn($c) => new RabbitWelcomeEmailRelay(
            $c->get(RabbitConnection::class),
            $c->get(SendWelcomeEmailSerializer::class),
            $c->get(LoggerInterface::class),
        ),
        WelcomeEmailMessageFactory::class => static fn($c) => new PdoWelcomeEmailMessageFactory(
            $c->get(PDO::class),
            $c->get(Clock::class),
        ),

        // HW9 D2: the monolith's first runtime consumer port -> the verbatim live
        // RabbitConsumer (M4 fence). The saga worker consumes the reply queue here.
        MessageConsumer::class => static fn($c) => new RabbitConsumer(
            $c->get(RabbitConnection::class)
        ),

        // HW9 D2/D3/D4: the relay/reply/sweep use-cases the worker drives per tick.
        RelayPendingWelcomeEmails::class => static fn($c) => new RelayPendingWelcomeEmails(
            $c->get(EnrollmentSagaReader::class),
            $c->get(WelcomeEmailMessageFactory::class),
            $c->get(WelcomeEmailRelay::class),
            $c->get(EnrollmentSagaWriter::class),
            $c->get(SagaMetricsRecorder::class),
            $c->get(LoggerInterface::class),
        ),
        SweepTimedOutSagas::class => static fn($c) => new SweepTimedOutSagas(
            $c->get(EnrollmentSagaReader::class),
            $c->get(EnrollmentSagaWriter::class),
            $c->get(SubscriptionConfirmationWriter::class),
            $c->get(TransactionManager::class),
            $c->get(Clock::class),
            $c->get(SagaMetricsRecorder::class),
        ),
        HandleWelcomeEmailOutcomeHandler::class => static fn($c) => new HandleWelcomeEmailOutcomeHandler(
            $c->get(TransactionManager::class),
            $c->get(EnrollmentSagaWriter::class),
            $c->get(SubscriptionConfirmationWriter::class),
            $c->get(SagaMetricsRecorder::class),
        ),
        WelcomeEmailOutcomeMessageMapper::class => static fn() => new WelcomeEmailOutcomeMessageMapper(),
        WelcomeEmailOutcomeConsumer::class => static fn($c) => new WelcomeEmailOutcomeConsumer(
            $c->get(MessageConsumer::class),
            $c->get(WelcomeEmailOutcomeMessageMapper::class),
            $c->get(CommandBus::class),
            $c->get(SagaMetricsRecorder::class),
            $c->get(LoggerInterface::class),
        ),
        SagaWorker::class => static fn($c) => new SagaWorker(
            $c->get(RelayPendingWelcomeEmails::class),
            $c->get(WelcomeEmailOutcomeConsumer::class),
            $c->get(SweepTimedOutSagas::class),
            $c->get(RabbitConnection::class),
            $c->get(LoggerInterface::class),
            $settings['saga']['relay_batch_size'],
            $settings['saga']['timeout_seconds'],
            $settings['saga']['start_timeout_seconds'],
            $settings['saga']['worker_wait_seconds'],
            $settings['saga']['sweep_every_ticks'],
        ),

        RepositoryStatusReader::class => static fn($c) => new PdoTrackedRepositoryReader(
            $c->get(PDO::class),
            $c->get(RepositoryStatusFactoryInterface::class)
        ),
        ScanCandidateSource::class => static fn($c) => $c->get(RepositoryStatusReader::class),
        RepositoryCountPort::class => static fn($c) => $c->get(RepositoryStatusReader::class),

        TrackedRepositoryRegistrar::class => static fn($c) => new PdoTrackedRepositoryWriter(
            $c->get(PDO::class)
        ),
        ScanProgressWriter::class => static fn($c) => $c->get(TrackedRepositoryRegistrar::class),
        HealthCheckInterface::class => static fn($c) => new DatabaseHealthCheck($c->get(PDO::class)),
        ExceptionStatusMap::class => static fn() => new ExceptionStatusMap(),
        Clock::class => static fn() => new SystemClock(),

        // Listener is intentionally lazy — the Rabbit publisher must not be resolved
        // while wiring HTTP/gRPC paths that don't need it; it is only touched during
        // scan cycles when NewReleaseDetected is actually dispatched.
        ListenerProviderInterface::class => static fn($c) => new ListenerProvider([
            NewReleaseDetected::class => [
                static function (object $event) use ($c): void {
                    $listener = $c->get(WhenNewReleaseDetectedThenPublishReleaseEmails::class);
                    $listener($event);
                },
            ],
            SubscriptionCreated::class => [
                static function (object $event) use ($c): void {
                    $listener = $c->get(WhenSubscriptionCreatedThenLog::class);
                    $listener($event);
                },
            ],
        ]),
        EventDispatcherInterface::class => static fn($c) => new InMemoryEventDispatcher(
            $c->get(ListenerProviderInterface::class)
        ),

        WhenSubscriptionCreatedThenLog::class => static fn($c) => new WhenSubscriptionCreatedThenLog(
            $c->get(LoggerInterface::class)
        ),
        SubscribeCommandHandler::class => static fn($c) => new SubscribeCommandHandler(
            $c->get(SubscriptionRepository::class),
            $c->get(ReleaseSource::class),
            $c->get(TrackedRepositoryRegistrar::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(Clock::class),
            $c->get(EnrollmentSagaStarter::class),
            $c->get(TransactionManager::class)
        ),
        UnsubscribeCommandHandler::class => static fn($c) => new UnsubscribeCommandHandler(
            $c->get(SubscriptionRepository::class),
            $c->get(LoggerInterface::class)
        ),
        SubscriptionResponseFactoryInterface::class => static fn() => new SubscriptionResponseFactory(),
        FindSubscriptionByIdHandler::class => static fn($c) => new FindSubscriptionByIdHandler(
            $c->get(SubscriptionRepository::class),
            $c->get(SubscriptionResponseFactoryInterface::class),
        ),
        FindSubscriptionByEmailAndRepositoryHandler::class => static fn($c) =>
            new FindSubscriptionByEmailAndRepositoryHandler(
                $c->get(SubscriptionRepository::class),
                $c->get(SubscriptionResponseFactoryInterface::class),
            ),
        ListSubscriptionsHandler::class => static fn($c) => new ListSubscriptionsHandler(
            $c->get(SubscriptionRepository::class),
            $c->get(SubscriptionResponseFactoryInterface::class),
        ),

        CommandBus::class => static fn($c) => new InMemoryCommandBus([
            SubscribeCommand::class => $c->get(SubscribeCommandHandler::class),
            UnsubscribeCommand::class => $c->get(UnsubscribeCommandHandler::class),
            ScanReleasesCommand::class => $c->get(ScanReleasesHandler::class),
            // HW9 D3: the reply-path orchestrator (T3 confirm / C1 compensate).
            HandleWelcomeEmailOutcomeCommand::class => $c->get(HandleWelcomeEmailOutcomeHandler::class),
        ]),
        QueryBus::class => static fn($c) => new InMemoryQueryBus([
            FindSubscriptionByIdQuery::class => $c->get(FindSubscriptionByIdHandler::class),
            FindSubscriptionByEmailAndRepositoryQuery::class =>
                $c->get(FindSubscriptionByEmailAndRepositoryHandler::class),
            ListSubscriptionsQuery::class => $c->get(ListSubscriptionsHandler::class),
            FetchLatestReleaseQuery::class => $c->get(FetchLatestReleaseHandler::class),
            RepositoryExistsQuery::class => $c->get(RepositoryExistsHandler::class),
        ]),

        PrometheusFormatter::class => static fn() => new PrometheusFormatter(),
        MetricsServiceInterface::class => static fn($c) => new MetricsService(
            $c->get(SubscriptionCountPort::class),
            $c->get(RepositoryCountPort::class),
            $c->get(EnrollmentSagaCountPort::class),
            $c->get(SagaMetricsReader::class),
            $c->get(PrometheusFormatter::class)
        ),

        EventIdGenerator::class => static fn() => new UuidV4EventIdGenerator(),
        SendReleaseEmailFactoryInterface::class => static fn($c) => new SendReleaseEmailFactory(
            $c->get(Clock::class),
            $c->get(EventIdGenerator::class)
        ),
        ReleaseNotificationPublisher::class => static fn($c) => new RabbitReleaseNotificationPublisher(
            $c->get(RabbitPublisher::class),
            $c->get(SendReleaseEmailSerializer::class),
            $c->get(LoggerInterface::class),
        ),
        PublishReleaseEmailsForRelease::class => static fn($c) => new PublishReleaseEmailsForRelease(
            $c->get(SubscriberFinder::class),
            $c->get(SendReleaseEmailFactoryInterface::class),
            $c->get(ReleaseNotificationPublisher::class)
        ),
        WhenNewReleaseDetectedThenPublishReleaseEmails::class =>
            static fn($c) => new WhenNewReleaseDetectedThenPublishReleaseEmails(
                $c->get(PublishReleaseEmailsForRelease::class)
            ),

        ReleaseDetector::class => static fn($c) => new ReleaseDetector(
            $c->get(ReleaseSource::class),
            $c->get(RepositoryStatusReader::class),
            $c->get(LoggerInterface::class)
        ),

        ScanReleasesHandler::class => static fn($c) => new ScanReleasesHandler(
            $c->get(ScanCandidateSource::class),
            $c->get(ScanProgressWriter::class),
            $c->get(ReleaseDetector::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(Clock::class),
            $c->get(LoggerInterface::class),
            $settings['github']['scan_batch_size']
        ),
        ScannerCliRunner::class => static fn($c) => new ScannerCliRunner(
            $c->get(CommandBus::class),
            $c->get(LoggerInterface::class),
            $settings['github']['scan_interval']
        ),

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
