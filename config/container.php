<?php

declare(strict_types=1);

use App\Cache\GitHubCacheInterface;
use App\Cache\RedisGitHubCache;
use App\Cache\SafeGitHubCacheDecorator;
use App\Config\Factory\PaginationFactory;
use App\Config\Factory\PaginationFactoryInterface;
use App\Config\Factory\SmtpConfigFactory;
use App\Config\Factory\SmtpConfigFactoryInterface;
use App\Config\SmtpConfig;
use App\Controller\HealthController;
use App\Controller\MetricsController;
use App\Controller\SubscriptionController;
use App\Domain\Factory\ReleaseFactory;
use App\Domain\Factory\ReleaseFactoryInterface;
use App\Domain\Factory\RepositoryStatusFactory;
use App\Domain\Factory\RepositoryStatusFactoryInterface;
use App\Domain\Factory\SubscriberRefFactory;
use App\Domain\Factory\SubscriberRefFactoryInterface;
use App\Domain\Factory\SubscriptionFactory;
use App\Domain\Factory\SubscriptionFactoryInterface;
use App\Domain\MetricsSnapshot;
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
use App\Observability\Metrics\GrpcMetrics;
use App\Observability\Metrics\GrpcStatusName;
use App\Observability\Metrics\HttpMetrics;
use App\Observability\Metrics\MeasuredInvoker;
use App\Observability\Metrics\PrometheusGrpcMetrics;
use App\Observability\Metrics\PrometheusHttpMetrics;
use App\Observability\Metrics\PrometheusScanMetrics;
use App\Observability\Metrics\SafeMetricsStorage;
use App\Observability\Metrics\ScanMetrics;
use App\Application\Event\Factory\ApplicationEventFactory;
use App\Application\Event\Factory\NotificationEventFactoryInterface;
use App\Application\Event\Factory\ReleaseEventFactoryInterface;
use App\Application\Event\Factory\ScanEventFactoryInterface;
use App\Application\Event\Factory\SubscriptionEventFactoryInterface;
use App\Observability\Event\ApplicationEventLogger;
use App\Observability\Event\EventDispatcher;
use App\Observability\Event\ObservabilityListenerProvider;
use App\Observability\Event\ScanMetricsListener;
use App\Observability\Logging\EmailMasker;
use App\Observability\Logging\EmailRedactingProcessor;
use App\Observability\Logging\FallbackLogger;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\RequestMetricsMiddleware;
use App\Middleware\RouteTagMiddleware;
use App\Migration\Migrator;
use App\Notifier\MailerInterface;
use App\Notifier\ReleaseEmailRenderer;
use App\Notifier\SmtpMailer;
use App\Observability\CorrelationContext;
use App\Observability\CorrelationContextInterface;
use App\Observability\CorrelationIdGeneratorInterface;
use App\Observability\Logging\ContextProcessor;
use App\Observability\RandomCorrelationIdGenerator;
use App\Observability\RouteContextInterface;
use App\Repository\MetricsRepository;
use App\Repository\MetricsRepositoryInterface;
use App\Repository\NotificationLedger;
use App\Repository\NotificationLedgerInterface;
use App\Repository\SubscriberFinderInterface;
use App\Repository\RepositoryStatusReader;
use App\Repository\ScanCandidateSource;
use App\Repository\ScanProgressWriter;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionRepositoryInterface;
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
use App\Service\SubscriptionService;
use App\Service\SubscriptionServiceInterface;
use App\Validation\EmailValidator;
use App\Validation\RepositoryNameValidator;
use App\Validation\SubscriptionValidator;
use DI\Container;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Predis\Client as RedisClient;
use Prometheus\CollectorRegistry;
use Prometheus\RegistryInterface;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Predis as PrometheusPredisStorage;
use Psr\Http\Message\ResponseFactoryInterface;
use App\Application\Event\EventPublisherInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\InvokerInterface;

return static function (array $settings): Container {
    $containerBuilder = new ContainerBuilder();

    $containerBuilder->addDefinitions([
        'settings' => $settings,

        // One mutable holder, shared via two narrow interfaces (id vs route).
        CorrelationContextInterface::class => static fn() => new CorrelationContext(),
        RouteContextInterface::class => static fn($c) => $c->get(CorrelationContextInterface::class),
        CorrelationIdGeneratorInterface::class => static fn() => new RandomCorrelationIdGenerator(),

        ContextProcessor::class => static fn($c) => new ContextProcessor(
            $c->get(CorrelationContextInterface::class),
            $settings['app']['component'],
            $settings['app']['env']
        ),

        LoggerInterface::class => static function ($c) use ($settings) {
            $handler = new StreamHandler('php://stderr', Level::fromName(strtolower($settings['log']['level'])));
            if ($settings['log']['format'] === 'json') {
                $handler->setFormatter(new JsonFormatter());
            }

            $logger = new Logger($settings['app']['component'], [$handler]);
            // Pushed first so it runs last: redacts PII once the message is
            // interpolated and context/extra are populated by the others.
            $logger->pushProcessor(new EmailRedactingProcessor(new EmailMasker()));
            $logger->pushProcessor(new PsrLogMessageProcessor());
            $logger->pushProcessor($c->get(ContextProcessor::class));

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
        SubscriptionRepositoryInterface::class => static fn($c) => new SubscriptionRepository(
            $c->get(PDO::class),
            $c->get(SubscriptionFactoryInterface::class),
            $c->get(SubscriberRefFactoryInterface::class)
        ),
        SubscriberFinderInterface::class => static fn($c) => $c->get(SubscriptionRepositoryInterface::class),
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
            $c->get(EventPublisherInterface::class),
            $c->get(NotificationEventFactoryInterface::class)
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

        // Metrics — shared Prometheus registry (Redis-backed in prod so HTTP, gRPC
        // and scanner processes all feed the single /metrics endpoint).
        RegistryInterface::class => static function ($c) use ($settings) {
            // Wrap the Redis-backed store so a Redis outage can't make a metric write
            // throw out of the finally blocks that record RED metrics (which would
            // replace a successful response or mask the original exception).
            $storage = $settings['metrics']['storage'] === 'redis'
                ? new SafeMetricsStorage(
                    PrometheusPredisStorage::fromExistingConnection($c->get(RedisClient::class)),
                    $c->get(LoggerInterface::class)
                )
                : new InMemory();

            return new CollectorRegistry($storage, false);
        },
        HttpMetrics::class => static fn($c) => new PrometheusHttpMetrics($c->get(RegistryInterface::class)),
        GrpcStatusName::class => static fn() => new GrpcStatusName(),
        GrpcMetrics::class => static fn($c) => new PrometheusGrpcMetrics(
            $c->get(RegistryInterface::class),
            $c->get(GrpcStatusName::class)
        ),
        ScanMetrics::class => static fn($c) => new PrometheusScanMetrics($c->get(RegistryInterface::class)),

        // Application-event plane — services emit anemic events; these listeners are
        // the only place a fact becomes a metric (existing ScanMetrics) or a
        // structured log. The HTTP/gRPC access plane stays in the middleware/invoker.
        EventPublisherInterface::class => static fn($c) => new EventDispatcher(
            new ObservabilityListenerProvider(
                new ScanMetricsListener($c->get(ScanMetrics::class)),
                new ApplicationEventLogger($c->get(LoggerInterface::class))
            ),
            new FallbackLogger(
                $settings['app']['component'],
                $settings['app']['env'],
                new EmailMasker()
            )
        ),

        // One stateless event factory shared via the narrow per-consumer interfaces
        // (services construct events through these instead of `new`).
        ScanEventFactoryInterface::class => static fn() => new ApplicationEventFactory(),
        ReleaseEventFactoryInterface::class => static fn($c) => $c->get(ScanEventFactoryInterface::class),
        NotificationEventFactoryInterface::class => static fn($c) => $c->get(ScanEventFactoryInterface::class),
        SubscriptionEventFactoryInterface::class => static fn($c) => $c->get(ScanEventFactoryInterface::class),
        MetricsServiceInterface::class => static fn($c) => new MetricsService(
            // Lazy: resolve the DB-backed repository only when collect() runs, inside its
            // try/catch — a DB/PDO failure must not block export of the RED metrics.
            static fn(): MetricsSnapshot => $c->get(MetricsRepositoryInterface::class)->snapshot(),
            $c->get(RegistryInterface::class),
            $c->get(LoggerInterface::class),
            $settings['app']['version']
        ),

        // Application services
        SubscriptionServiceInterface::class => static fn($c) => new SubscriptionService(
            $c->get(SubscriptionRepositoryInterface::class),
            $c->get(TrackedRepositoryRegistrar::class),
            $c->get(GitHubServiceInterface::class),
            $c->get(SubscriptionValidator::class),
            $c->get(EventPublisherInterface::class),
            $c->get(SubscriptionEventFactoryInterface::class)
        ),
        ReleaseDetector::class => static fn($c) => new ReleaseDetector(
            $c->get(GitHubServiceInterface::class),
            $c->get(RepositoryStatusReader::class),
            $c->get(EventPublisherInterface::class),
            $c->get(ReleaseEventFactoryInterface::class)
        ),
        NotificationDispatcherInterface::class => static fn($c) => new NotificationDispatcher(
            $c->get(SubscriberFinderInterface::class),
            $c->get(NotificationLedgerInterface::class),
            $c->get(NotifierInterface::class)
        ),
        ScannerService::class => static fn($c) => new ScannerService(
            $c->get(ScanCandidateSource::class),
            $c->get(ScanProgressWriter::class),
            $c->get(ReleaseDetector::class),
            $c->get(NotificationDispatcherInterface::class),
            $c->get(EventPublisherInterface::class),
            $c->get(ScanEventFactoryInterface::class),
            $settings['github']['scan_batch_size']
        ),

        // Boundaries
        SubscriptionController::class => static fn($c) => new SubscriptionController(
            $c->get(SubscriptionServiceInterface::class),
            $c->get(PaginationFactoryInterface::class)
        ),
        ReleaseNotifierService::class => static fn($c) => new ReleaseNotifierService(
            $c->get(SubscriptionServiceInterface::class),
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
        CorrelationIdMiddleware::class => static fn($c) => new CorrelationIdMiddleware(
            $c->get(CorrelationContextInterface::class),
            $c->get(CorrelationIdGeneratorInterface::class)
        ),
        RequestMetricsMiddleware::class => static fn($c) => new RequestMetricsMiddleware(
            $c->get(HttpMetrics::class),
            $c->get(RouteContextInterface::class),
            $c->get(LoggerInterface::class)
        ),
        RouteTagMiddleware::class => static fn($c) => new RouteTagMiddleware(
            $c->get(RouteContextInterface::class)
        ),
        InvokerInterface::class => static fn($c) => new MeasuredInvoker(
            new Invoker(),
            $c->get(GrpcMetrics::class),
            $c->get(GrpcStatusName::class),
            $c->get(CorrelationContextInterface::class),
            $c->get(CorrelationIdGeneratorInterface::class),
            $c->get(LoggerInterface::class)
        ),

        Migrator::class => static fn($c) => new Migrator(
            $c->get(PDO::class),
            dirname(__DIR__) . '/migrations',
            $c->get(LoggerInterface::class)
        ),
    ]);

    return $containerBuilder->build();
};
