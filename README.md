# GitHub Release Notifier

API-сервіс для підписки на email-сповіщення про нові релізи GitHub-репозиторіїв.

Проєкт має:

- HTTP API на Slim 4
- gRPC API на RoadRunner
- scanner для періодичної перевірки нових релізів
- notifier для email-доставки через SMTP

## Вимоги

Для роботи з проєктом потрібні тільки:

- Docker
- Docker Compose plugin
- GNU Make

Локально не потрібні:

- PHP
- Composer
- PostgreSQL
- Redis
- RoadRunner binary `rr`

Усе піднімається та перевіряється через `make`.

## Швидкий Старт

Підготувати і підняти весь стек:

```bash
make up
make migrate
```

Сервіси:

- HTTP API: `http://localhost:8080`
- gRPC: `localhost:9001`
- HTML UI: `http://localhost:8080/`
- MailHog: `http://localhost:8025`
- Metrics: `http://localhost:8080/metrics`

Корисні команди:

```bash
make logs
make restart
make down
```

## Основні Make Команди

```bash
make install   # зібрати Docker images для всіх workflow
make up        # підняти весь application stack
make migrate   # прогнати міграції в контейнері
make lint      # phpcs у Docker
make stan      # phpstan у Docker
make test      # phpunit у Docker
make check     # lint + stan + unit tests
make proto     # згенерувати protobuf/gRPC класи
make behat     # acceptance у Docker
make ci        # повна перевірка: check + acceptance
make down      # зупинити стек
```

`make up` автоматично створює `.env` із `.env.example`, якщо його ще немає.

## Архітектура

Ключові entrypoints:

- `public/index.php` — HTTP entrypoint
- `bin/grpc.php` — gRPC worker entrypoint
- `bin/scanner.php` — scanner process

Ключові модулі:

- `src/Service/SubscriptionService.php` — бізнес-логіка підписок
- `src/Grpc/ReleaseNotifierService.php` — gRPC adapter над тією самою логікою
- `src/Repository/SubscriptionRepository.php` — PostgreSQL repository
- `src/Service/GitHubService.php` — інтеграція з GitHub API
- `src/Service/NotifierService.php` — SMTP-відправка повідомлень

Нормальний флоу:

1. Клієнт створює підписку через HTTP або gRPC.
2. Сервіс валідовує `email` і `owner/repo`.
3. Репозиторій перевіряється через GitHub API.
4. Підписка зберігається в PostgreSQL.
5. Scanner перевіряє релізи пачками.
6. Якщо з’явився новий реліз, notifier відправляє лист.
7. Стан доставки зберігається в БД, щоб уникнути дублювання.

## HTTP API

### Створити підписку

```bash
curl -X POST http://localhost:8080/api/subscriptions \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","repository":"golang/go"}'
```

### Список підписок

```bash
curl "http://localhost:8080/api/subscriptions?email=user@example.com&limit=20&offset=0"
```

### Отримати підписку

```bash
curl http://localhost:8080/api/subscriptions/1
```

### Видалити підписку

```bash
curl -X DELETE http://localhost:8080/api/subscriptions/1
```

### API key

Якщо в `.env` задано `API_KEY`, усі запити до `/api/*` повинні передавати:

```text
X-API-Key: your-api-key
```

Маршрути `/`, `/health`, `/metrics` залишаються без авторизації.

HTML-форма на `/` теж підтримує API key, але бере його з поля форми, а не з query string.

## gRPC

Proto-контракт лежить у `proto/release_notifier.proto`.

Generated PHP-класи лежать у `generated/`.

Регенерація:

```bash
make proto
```

Сервіс `release_notifier.v1.ReleaseNotifierService` підтримує:

- `Health`
- `CreateSubscription`
- `ListSubscriptions`
- `GetSubscription`
- `DeleteSubscription`

Важливий runtime-нюанс:

- логер пише в `stderr`, а не в `stdout`
- це потрібно для сумісності з RoadRunner worker protocol
- інакше gRPC-відповіді ламаються на transport layer

### gRPC smoke

Якщо `grpcurl` встановлений локально:

```bash
grpcurl -plaintext -import-path proto -proto release_notifier.proto localhost:9001 list
grpcurl -plaintext -import-path proto -proto release_notifier.proto \
  -d '{}' \
  localhost:9001 release_notifier.v1.ReleaseNotifierService/Health
```

Якщо локально `grpcurl` немає, можна використати Docker:

```bash
docker run --rm \
  --network github-release-notifier_default \
  -v "$PWD/proto:/proto" \
  fullstorydev/grpcurl \
  -plaintext \
  -import-path /proto \
  -proto release_notifier.proto \
  grpc:9001 list
```

## Міграції

```bash
make migrate
```

Міграції запускаються з advisory lock, тому одночасний старт кількох процесів не повинен призводити до гонок schema changes.

## Тести І Перевірки

### Unit та статичні перевірки

Усі ці команди виконуються всередині Docker:

```bash
make lint
make stan
make test
make check
```

Покриття:

- `make lint` — `src/`, `config/`, `bin/`, `tests/`
- `make stan` — статичний аналіз
- `make test` — PHPUnit

### Acceptance

Acceptance-набір запускається так:

```bash
make behat
```

Або поетапно:

```bash
make behat-up
docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T app composer acceptance
make behat-down
```

Що використовується:

- `features/*.feature` — бізнес-сценарії
- `tests/Acceptance/FeatureContext.php` — step helpers і cleanup logic
- `behat.yml` — конфіг Mink та OpenAPI validator
- `docker-compose.test.yml` — test override для acceptance

Що покривають acceptance:

- `features/health.feature` — `/health`
- `features/metrics.feature` — `/metrics`
- `features/subscription.feature` — create/list/get/delete підписок
- негативні кейси: невалідний email, невалідний repository format, відсутні поля, `404`
- контракт HTTP API проти `swagger.yaml`

Як працює cleanup:

- сценарії з тегом `@cleanup` перед стартом чистять підписки через HTTP API
- cleanup дозволений лише для `localhost` або `127.0.0.1`
- якщо задано `API_KEY`, cleanup автоматично передає той самий `X-API-Key`

Чому є `docker-compose.test.yml`:

- для acceptance примусово ставиться порожній `API_KEY`
- scanner interval зсувається далеко вперед, щоб background job не шумів під час тестів

## Повна Перевірка

```bash
make ci
```

`make ci` запускає:

1. `lint`
2. `stan`
3. `phpunit`
4. `behat`

Усе це відбувається всередині Docker.

## OpenAPI / Swagger

HTTP OpenAPI схема лежить у `swagger.yaml`.

Вона використовується для:

- acceptance contract validation
- ручного перегляду в `https://editor.swagger.io/`

Top-level `security: []` у схемі залишено навмисно:

- це прибирає warnings у Behat OpenAPI validator
- і фіксує очікувану структуру документа на рівні acceptance/regression checks

## Extra

- [x] HTML-сторінка для підписки
- [x] Redis-кешування GitHub API
- [x] API key через `X-API-Key`
- [x] Prometheus metrics
- [x] gRPC transport
- [x] Acceptance suite з OpenAPI-перевіркою
- [ ] Production deploy
