<?php

declare(strict_types=1);

use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Domain\EmailRenderer;
use App\Sending\Domain\Mailer;
use App\Sending\Domain\NotificationLedger;
use App\Sending\Infrastructure\Mail\MailerFactoryInterface;
use App\Sending\Infrastructure\Mail\PhpMailerMailer;
use App\Sending\Infrastructure\Mail\PHPMailerFactory;
use App\Sending\Infrastructure\Mail\ReleaseEmailRenderer;
use App\Sending\Infrastructure\Mail\SmtpConfig;
use App\Sending\Infrastructure\Persistence\PdoNotificationLedger;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailMessageMapper;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use DI\Container;
use DI\ContainerBuilder;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Replaces D1's bare `$builder->build()` stub with the full DI wiring this
 * runnable worker needs (D4) — `static function(array $settings): Container`,
 * exactly mirroring the monolith's `config/container.php` shape (decision §5):
 * `bin/consumer.php` does `settings → container($settings) → resolve consumer
 * → consume`.
 *
 * Bindings, innermost-first:
 * - `\PDO::class` — factory resolving `notification_db` settings (this
 *   service's OWN Postgres instance, separate from the monolith's; reads
 *   `NOTIFICATION_DB_*`, never `DB_*` — see D2/C3 "own DB, no FK").
 * - `RabbitConnection::class`/`RabbitConsumer::class` — concrete
 *   infra-connection DI keys (the documented "lowest-level adapter" exception
 *   to "DI binds interfaces only" — mirrors the monolith's `PDO::class`/
 *   `RabbitConnection::class` precedent). `AMQPStreamConnection` construction
 *   from the `rabbitmq` settings group mirrors the monolith's factory verbatim
 *   (dedicated host/port/user/password/vhost arguments — never an assembled
 *   connection-URI string, sidestepping the RABBITMQ_VHOST=/ encoding pitfall).
 * - `SmtpConfig::class`/`MailerFactoryInterface::class` — this service's own
 *   recreated SMTP-config/factory seam (§4 — cannot import the monolith's
 *   `App\Config\SmtpConfig`/`App\Factory\{MailerFactoryInterface,PHPMailerFactory}`,
 *   cross-deployable boundary).
 * - The three `Notification\Sending` Domain ports → their D4 adapters:
 *   `NotificationLedger::class → PdoNotificationLedger` (D2, already shipped —
 *   D4 only wires it), `EmailRenderer::class → ReleaseEmailRenderer`,
 *   `Mailer::class → PhpMailerMailer`.
 * - `SendReleaseEmailMessageMapper::class` — the JSON→`ReleaseEmail`
 *   deserializer (§2/§6); concrete DI key (no interface — it is the
 *   anti-corruption layer's private concern, used by exactly one caller).
 * - `SendReleaseEmailHandler::class` — D3's Application use-case; auto-wired
 *   by PHP-DI from the three port bindings above.
 * - `SendReleaseEmailConsumer::class` — the anti-corruption Rabbit consumer
 *   (D4); auto-wired from `RabbitConsumer`/`SendReleaseEmailHandler`/the mapper.
 *
 * Interfaces only as DI keys (CLAUDE.md "DI binds interfaces only") — the
 * concrete infra-connection classes (`\PDO`/`RabbitConnection`/`RabbitConsumer`)
 * are the documented exception, mirroring the monolith's `PDO::class`/
 * `RedisClient::class`/`RabbitConnection::class` precedent: there is no
 * `*Interface` to bind to for "the lowest-level adapter".
 */
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

        // === Messaging: RabbitMQ — concrete-class DI keys (Decision/AC7):
        // RabbitConnection/RabbitConsumer ARE the lowest-level adapters,
        // mirroring the PDO::class precedent above. The AMQPStreamConnection
        // is built here (the only place that opens a real socket) from the
        // 'rabbitmq' settings group via its dedicated host/port/user/password/
        // vhost constructor arguments. Its channel is then handed to
        // RabbitConnection, which idempotently asserts the full AR-MQ1 topology.
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

        // === Mail: SMTP / PHPMailer — this service's own recreated seam (§4) ===
        SmtpConfig::class => static fn() => new SmtpConfig(
            host: $settings['smtp']['host'],
            port: $settings['smtp']['port'],
            from: $settings['smtp']['from'],
            user: $settings['smtp']['user'],
            password: $settings['smtp']['password'],
            encryption: $settings['smtp']['encryption'],
        ),
        MailerFactoryInterface::class => static fn() => new PHPMailerFactory(),

        // === Notification\Sending — Domain ports → D4 adapters ===
        NotificationLedger::class => static fn($c) => new PdoNotificationLedger($c->get(PDO::class)),
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
        ),

        SendReleaseEmailConsumer::class => static fn($c) => new SendReleaseEmailConsumer(
            $c->get(RabbitConsumer::class),
            $c->get(SendReleaseEmailHandler::class),
            $c->get(SendReleaseEmailMessageMapper::class),
        ),
    ]);

    return $containerBuilder->build();
};
