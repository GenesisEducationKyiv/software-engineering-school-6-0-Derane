<?php

declare(strict_types=1);

use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Application\DeliveryOutcomeRecorder;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Application\MessageProcessingStatsRecorder;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Application\NotificationMetricsReader;
use App\Sending\Infrastructure\Error\ExceptionStatusMap;
use App\Sending\Infrastructure\Health\CompositeHealthCheck;
use App\Sending\Infrastructure\Health\DatabaseHealthCheck;
use App\Sending\Infrastructure\Health\HealthCheckInterface;
use App\Sending\Infrastructure\Health\RabbitMqHealthCheck;
use App\Sending\Infrastructure\Http\ErrorHandlerMiddleware;
use App\Sending\Infrastructure\Http\HealthController;
use App\Sending\Infrastructure\Http\MetricsController;
use App\Sending\Infrastructure\Logging\StderrLogger;
use App\Sending\Infrastructure\Mail\MailerFactoryInterface;
use App\Sending\Infrastructure\Mail\PhpMailerMailer;
use App\Sending\Infrastructure\Mail\PHPMailerFactory;
use App\Sending\Infrastructure\Mail\ReleaseEmailRenderer;
use App\Sending\Infrastructure\Mail\SmtpConfig;
use App\Sending\Infrastructure\Metrics\MetricsService;
use App\Sending\Infrastructure\Metrics\MetricsServiceInterface;
use App\Sending\Infrastructure\Metrics\PrometheusFormatter;
use App\Sending\Infrastructure\Persistence\PdoNotificationLedger;
use App\Sending\Infrastructure\Persistence\PdoNotificationMetricsStore;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailMessageMapper;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use DI\Container;
use DI\ContainerBuilder;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return static function (array $settings): Container {
    $containerBuilder = new ContainerBuilder();
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
                $settings['rabbitmq']['vhost']
            );
            return new RabbitConnection($connection->channel());
        },
        RabbitConsumer::class => static fn($c) => new RabbitConsumer(
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
        PdoNotificationMetricsStore::class => static fn($c) => new PdoNotificationMetricsStore($c->get(PDO::class)),
        DeliveryOutcomeRecorder::class => static fn($c) => $c->get(PdoNotificationMetricsStore::class),
        MessageProcessingStatsRecorder::class => static fn($c) => $c->get(PdoNotificationMetricsStore::class),
        NotificationMetricsReader::class => static fn($c) => $c->get(PdoNotificationMetricsStore::class),
        EmailRenderer::class => static fn() => new ReleaseEmailRenderer(),
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
            $c->get(RabbitConsumer::class),
            $c->get(SendReleaseEmailHandler::class),
            $c->get(SendReleaseEmailMessageMapper::class),
            $c->get(MessageProcessingStatsRecorder::class),
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
    ]);

    return $containerBuilder->build();
};
