<?php

declare(strict_types=1);

use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\DeliveryOutcomeRecorder;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Application\MessageProcessingStatsRecorder;
use App\Sending\Application\WelcomeProcessingStatsRecorder;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Domain\WelcomeNotificationLedger;
use App\Sending\Domain\WelcomeOutcomePublisher;
use App\Sending\Application\NotificationMetricsReader;
use App\Sending\Infrastructure\Error\ExceptionStatusMap;
use App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService;
use App\Sending\Infrastructure\Health\CompositeHealthCheck;
use App\Sending\Infrastructure\Health\DatabaseHealthCheck;
use App\Sending\Infrastructure\Health\HealthCheckInterface;
use App\Sending\Infrastructure\Health\RabbitMqHealthCheck;
use App\Sending\Infrastructure\Http\ErrorHandlerMiddleware;
use App\Sending\Infrastructure\Http\HealthController;
use App\Sending\Infrastructure\Http\MetricsController;
use App\Sending\Infrastructure\Http\WelcomeEmailController;
use App\Sending\Infrastructure\Logging\StderrLogger;
use App\Sending\Infrastructure\Mail\MailerFactoryInterface;
use App\Sending\Infrastructure\Mail\PhpMailerMailer;
use App\Sending\Infrastructure\Mail\PHPMailerFactory;
use App\Sending\Infrastructure\Mail\ReleaseEmailRenderer;
use App\Sending\Infrastructure\Mail\WelcomeEmailRenderer;
use App\Sending\Infrastructure\Mail\SmtpConfig;
use App\Sending\Infrastructure\Metrics\MetricsService;
use App\Sending\Infrastructure\Metrics\MetricsServiceInterface;
use App\Sending\Infrastructure\Metrics\PrometheusFormatter;
use App\Sending\Infrastructure\Persistence\PdoNotificationLedger;
use App\Sending\Infrastructure\Persistence\PdoNotificationMetricsStore;
use App\Sending\Infrastructure\Persistence\PdoWelcomeNotificationLedger;
use App\Sending\Infrastructure\Rabbit\RabbitWelcomeOutcomePublisher;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailMessageMapper;
use App\Sending\Infrastructure\Rabbit\SendWelcomeEmailConsumer;
use App\Sending\Infrastructure\Rabbit\SendWelcomeEmailMessageMapper;
use App\Sending\Infrastructure\Sync\NoOpWelcomeOutcomePublisher;
use App\Sending\Infrastructure\Sync\WelcomeEmailFactory;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use DI\Container;
use DI\ContainerBuilder;
use Notification\Welcome\V1\WelcomeEmailServiceInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return static function (array $settings): Container {
    $containerBuilder = new ContainerBuilder();

    // The sync welcome surfaces (REST + gRPC) build the handler with a NO-OP reply publisher:
    // the monolith relay applies the outcome in-thread, so the fail-closed Rabbit reply must
    // stay off the sync send's critical path (see NoOpWelcomeOutcomePublisher). The async
    // consumer below keeps the real Rabbit publisher.
    $syncWelcomeHandler = static fn($c): SendWelcomeEmailHandler => new SendWelcomeEmailHandler(
        $c->get(WelcomeNotificationLedger::class),
        $c->get(WelcomeEmailRenderer::class),
        $c->get(Mailer::class),
        new NoOpWelcomeOutcomePublisher(),
        $c->get(WelcomeProcessingStatsRecorder::class),
    );

    $containerBuilder->addDefinitions([
        PDO::class => static function () use ($settings) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $settings['notification_db']['host'],
                $settings['notification_db']['port'],
                $settings['notification_db']['name']
            );
            $pdo = new PDO($dsn, $settings['notification_db']['user'], $settings['notification_db']['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        },

        RabbitConnection::class => static function () use ($settings) {
            $connection = new AMQPStreamConnection(
                $settings['rabbitmq']['host'],
                $settings['rabbitmq']['port'],
                $settings['rabbitmq']['user'],
                $settings['rabbitmq']['password'],
                $settings['rabbitmq']['vhost'],
                // The long-lived consumer pairs this heartbeat with a
                // PCNTLHeartbeatSender (see bin/consumer.php) so the broker never
                // drops the connection for missed heartbeats while a delivery is
                // being processed, and a dead connection surfaces within the
                // heartbeat window instead of hanging. read_write_timeout must
                // stay above both the heartbeat and the retry confirm-wait (5s);
                // php-amqplib defaults it to 3s.
                heartbeat: 60,
                read_write_timeout: 130,
                keepalive: true,
            );
            return new RabbitConnection($connection->channel());
        },
        MessageConsumer::class => static fn($c) => new RabbitConsumer(
            $c->get(RabbitConnection::class),
        ),

        SmtpConfig::class => static fn() => new SmtpConfig(
            host: $settings['smtp']['host'],
            port: $settings['smtp']['port'],
            from: $settings['smtp']['from'],
            user: $settings['smtp']['user'],
            password: $settings['smtp']['password'],
            encryption: $settings['smtp']['encryption'],
        ),
        MailerFactoryInterface::class => static fn() => new PHPMailerFactory(),

        NotificationLedger::class => static fn($c) => new PdoNotificationLedger($c->get(PDO::class)),
        WelcomeNotificationLedger::class => static fn($c) => new PdoWelcomeNotificationLedger($c->get(PDO::class)),
        DeliveryOutcomeRecorder::class => static fn($c) => new PdoNotificationMetricsStore($c->get(PDO::class)),
        MessageProcessingStatsRecorder::class => static fn($c) => $c->get(DeliveryOutcomeRecorder::class),
        NotificationMetricsReader::class => static fn($c) => $c->get(DeliveryOutcomeRecorder::class),
        EmailRenderer::class => static fn() => new ReleaseEmailRenderer(),
        // Two EmailRenderer implementations exist (release + welcome); only the
        // release one can hold the shared EmailRenderer interface binding, so the
        // welcome renderer is registered under its concrete key and injected
        // explicitly into the welcome handler. Keeps construction uniform with the
        // rest of the composition root (no inline `new` in the handler wiring).
        WelcomeEmailRenderer::class => static fn() => new WelcomeEmailRenderer(),
        Mailer::class => static fn($c) => new PhpMailerMailer(
            $c->get(SmtpConfig::class),
            $c->get(MailerFactoryInterface::class),
        ),

        SendReleaseEmailMessageMapper::class => static fn() => new SendReleaseEmailMessageMapper(),

        SendReleaseEmailHandler::class => static fn($c) => new SendReleaseEmailHandler(
            $c->get(NotificationLedger::class),
            $c->get(EmailRenderer::class),
            $c->get(Mailer::class),
            $c->get(DeliveryOutcomeRecorder::class),
        ),

        SendReleaseEmailConsumer::class => static fn($c) => new SendReleaseEmailConsumer(
            $c->get(MessageConsumer::class),
            $c->get(SendReleaseEmailHandler::class),
            $c->get(SendReleaseEmailMessageMapper::class),
            $c->get(MessageProcessingStatsRecorder::class),
            $c->get(LoggerInterface::class),
        ),

        // Welcome path (HW9 saga). WelcomeProcessingStatsRecorder and the
        // WelcomeOutcomePublisher reply publisher are aliased to their concrete
        // implementations registered below.
        WelcomeProcessingStatsRecorder::class => static fn($c) => $c->get(DeliveryOutcomeRecorder::class),
        WelcomeOutcomePublisher::class => static fn($c) => new RabbitWelcomeOutcomePublisher(
            $c->get(RabbitConnection::class),
            $c->get(LoggerInterface::class),
        ),
        SendWelcomeEmailMessageMapper::class => static fn() => new SendWelcomeEmailMessageMapper(),

        // The ASYNC-path handler: the rabbit consumer resolves this binding, so its reply
        // travels back over RabbitMQ (the real fail-closed WelcomeOutcomePublisher). The
        // SYNC surfaces use $syncWelcomeHandler (no-op publisher) instead — see above.
        SendWelcomeEmailHandler::class => static fn($c) => new SendWelcomeEmailHandler(
            $c->get(WelcomeNotificationLedger::class),
            // The welcome handler renders from the welcome template specifically,
            // not the shared EmailRenderer binding (which is the release renderer).
            $c->get(WelcomeEmailRenderer::class),
            $c->get(Mailer::class),
            $c->get(WelcomeOutcomePublisher::class),
            $c->get(WelcomeProcessingStatsRecorder::class),
        ),

        SendWelcomeEmailConsumer::class => static fn($c) => new SendWelcomeEmailConsumer(
            $c->get(MessageConsumer::class),
            $c->get(SendWelcomeEmailHandler::class),
            $c->get(SendWelcomeEmailMessageMapper::class),
            $c->get(WelcomeProcessingStatsRecorder::class),
            $c->get(LoggerInterface::class),
        ),

        LoggerInterface::class => static fn() => new StderrLogger(),
        ResponseFactoryInterface::class => static fn() => new ResponseFactory(),
        ExceptionStatusMap::class => static fn() => new ExceptionStatusMap(),
        PrometheusFormatter::class => static fn() => new PrometheusFormatter(),
        MetricsServiceInterface::class => static fn($c) => new MetricsService(
            $c->get(NotificationMetricsReader::class),
            $c->get(PrometheusFormatter::class),
        ),
        HealthCheckInterface::class => static fn($c) => new CompositeHealthCheck([
            new DatabaseHealthCheck(static fn(): PDO => $c->get(PDO::class)),
            new RabbitMqHealthCheck(
                $settings['rabbitmq']['host'],
                $settings['rabbitmq']['port'],
                $settings['rabbitmq']['user'],
                $settings['rabbitmq']['password'],
                $settings['rabbitmq']['vhost'],
            ),
        ]),
        HealthController::class => static fn($c) => new HealthController(
            $c->get(HealthCheckInterface::class),
            $c->get(LoggerInterface::class),
        ),
        MetricsController::class => static fn($c) => new MetricsController(
            $c->get(MetricsServiceInterface::class),
        ),
        ErrorHandlerMiddleware::class => static fn($c) => new ErrorHandlerMiddleware(
            $c->get(LoggerInterface::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get(ExceptionStatusMap::class),
        ),

        // Synchronous welcome-email transport surfaces (REST→gRPC migration). Both
        // wrap the UNCHANGED SendWelcomeEmailHandler via the shared validating
        // WelcomeEmailFactory; the async RabbitMQ path above is untouched.
        WelcomeEmailFactory::class => static fn() => new WelcomeEmailFactory(),
        WelcomeEmailController::class => static fn($c) => new WelcomeEmailController(
            $syncWelcomeHandler($c),
            $c->get(WelcomeEmailFactory::class),
            $c->get(LoggerInterface::class),
        ),
        WelcomeEmailServiceInterface::class => static fn($c) => new WelcomeEmailGrpcService(
            $syncWelcomeHandler($c),
            $c->get(WelcomeEmailFactory::class),
            $c->get(ExceptionStatusMap::class),
            $c->get(LoggerInterface::class),
        ),
    ]);

    return $containerBuilder->build();
};
