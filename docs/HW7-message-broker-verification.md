# HW7 — Верифікація: Message Broker + Notification Service

**Дата:** 2026-06-13 · **Гілка:** `hw7-monolithmicroservices`

## Підсумок

| # | Вимога | Статус |
|---|--------|--------|
| 1 | Додати message broker | ✅ Виконано |
| 2 | Почати паблішити івенти / команди | ✅ Виконано |
| 3 | Notification service/module + консьюмер цих повідомлень | ✅ Виконано |
| + | **Додатково:** покрити тестами логіку консьюмерів | ✅ Виконано (з запасом) |

**Вердикт: усі 3 основні вимоги + додаткова виконані на 100% і суттєво перевершені.**

---

## 1. Message broker — ✅

- **RabbitMQ 4** як сервіс `rabbitmq` у `docker-compose.yml` (`rabbitmq:4-management-alpine`), з
  healthcheck (`rabbitmq-diagnostics ping`) і durable-волюмом `rabbitmq_data`.
- Клієнт `php-amqplib/php-amqplib: ^3.7` підключений **в обох** деплоях:
  моноліт (`composer.json`) і сервіс (`apps/notification/composer.json`).
- Повна топологія декларується ідемпотентно в `RabbitConnection`:
  - exchange `notifications` (topic, durable) + `notifications.dlx` (fanout, durable);
  - черга `notifications.send-email` (durable, `x-dead-letter-exchange → notifications.dlx`);
  - retry-черга `notifications.send-email.retry` (TTL-backoff, dead-letter назад у робочу чергу);
  - `notifications.send-email.dlq`;
  - binding `notifications → send-email` по ключу `release.email`.

## 2. Публікація команд — ✅

Моноліт публікує інтеграційну **команду** `SendReleaseEmail/v1` (саме команду, а не подію —
бо моноліт спершу резолвить отримувачів; відповідає архітектурному рішенню в `CLAUDE.md`).

Ланцюжок (підтверджено в `config/container.php`):

```
NewReleaseDetected (PSR-14, in-process)
  → PublishReleaseEmailsOnNewReleaseDetectedListener   (listener, зареєстрований у ListenerProvider)
  → PublishReleaseEmailsForRelease                   (use-case: резолвить підписників)
  → RabbitReleaseNotificationPublisher               (порт → adapter)
  → RabbitPublisher.publishBatch(...)                (publisher confirms, batched, exchange=notifications, key=release.email)
```

Надійність: publisher використовує publisher-confirms (`confirm_select` + ack/nack handlers),
а `PublishReleaseEmailsForRelease` навмисно **не** ловить помилку публікації — вона спливає до
сканера, маркер не зсувається, реліз повторюється наступного циклу (outbox-free).

## 3. Notification service + консьюмер — ✅

`apps/notification/` — **повноцінний окремий мікросервіс** (не модуль):
власні `composer.json`, `vendor/`, БД `notification-db`, контейнер `notification-svc`
у docker-compose, окрема точка входу `bin/consumer.php`.

- **Точка входу** `bin/consumer.php`: довгоживучий цикл `basic_consume` + `wait`,
  graceful shutdown по SIGTERM/SIGINT (доводить in-flight delivery до ack/nack).
- **`SendReleaseEmailConsumer`** (ACL над чергою `notifications.send-email`) — три розділені
  шляхи помилок:
  1. malformed payload → одразу DLQ (без витрати retry-бюджету);
  2. claim contention (`NotificationInFlightException`) → паркування на lease-вікно
     **без** інкременту retry-лічильника;
  3. інша (напр. SMTP) помилка → bounded retry через TTL-чергу, після `MAX_REDELIVERIES` → DLQ.
  Ловиться `Throwable`, тож жодна помилка не вбиває consume-loop.
- **`RabbitConsumer`** — generic-скаффолдинг: ack/nack, retry через `x-retry-count` header +
  експоненційний backoff (5s, 10s, 20s…), маршрутизація в DLQ.
- **Ідемпотентність**: `NotificationLedger` claim/markSent — дублікати релізу даютьрівно один лист.

## Додаткове завдання — тести логіки консьюмерів — ✅

Покриття значно перевищує мінімум.

**Unit (сервіс, `apps/notification`)** — повний прогін: `OK (78 tests, 261 assertions)`.
Серед них логіка консьюмера:
- `SendReleaseEmailConsumerTest` — 8 сценаріїв: ack на успіх; ack на ідемпотентний skip;
  malformed JSON → DLQ; відсутнє поле → DLQ; transient → requeue; перевірка властивостей
  retry-копії (TTL, header, content_type); паркування при contention; DLQ при перевищенні межі.
  Реальні `RabbitConsumer`/`RabbitConnection` проти мок-каналу `AMQPChannel`.
- `SendReleaseEmailMessageMapperTest`, `SendReleaseEmailWireContractTest`.

**Integration (реальні broker/Postgres/MailHog)** — справжній `handleDelivery`:
- `IdempotencyProofTest` — той самий payload двічі → 1 лист + 1 рядок у БД;
- `DlqRoutingProofTest` — malformed → DLQ без дотику до ledger;
- `NotificationThroughputSmokeTest` — batch end-to-end;
- `PdoNotificationLedgerClaimTest` — атомарність claim.

**Крос-сервісний контракт**: спільний golden-файл `contracts/send-release-email.v1.json`,
до якого прив'язані тести **і продюсера (моноліт), і консьюмера (сервіс)** — захист від дрейфу
wire-формату між двома незалежними кодовими базами.

**Сторона продюсера (моноліт)**: `--filter 'Publishing|Rabbit'` → `OK (53 tests, 195 assertions)`.

---

## Як перевірено

```bash
# сервіс
cd apps/notification && php vendor/bin/phpunit --no-coverage --testsuite Unit   # 78/78 OK
# моноліт (сторона публікації)
./vendor/bin/phpunit --no-coverage --testsuite Unit --filter 'Publishing|Rabbit'  # 53/53 OK
```

> Integration/Behat потребують підняття docker-стеку (Postgres/Redis/RabbitMQ/MailHog) —
> код тестів перевірено статично, прогін потребує запущеного стеку.
