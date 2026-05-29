# github-release-notifier

Slim 4 + PHP-DI app that watches GitHub repos for new releases and emails subscribers. Postgres for persistence, Redis for GitHub-API cache. PHP 8.2+.

## Stack

- HTTP: Slim 4 (REST) + Spiral RoadRunner gRPC (`proto/release_notifier.proto`)
- DB: PostgreSQL via PDO; migrations in `migrations/*.sql`
- Cache: Redis via Predis — GitHub-API responses only
- Mail: PHPMailer SMTP
- Tests: PHPUnit 10, Psalm, PHPCS PSR-12, Behat (acceptance)

## Layout

- `src/Domain/` — anemic readonly DTOs
- `src/Domain/Factory/`, `src/Config/Factory/` — `*FactoryInterface` + concrete impls for parsing API/DB payloads
- `src/Validation/` — injectable `*Validator` classes
- `src/Repository/` — per-consumer interfaces (`*Reader`, `*Writer`, `*Registrar`, `*Source`, `*Finder`) + concrete impls
- `src/Service/` — application services behind `*ServiceInterface`
- `src/Controller/`, `src/Middleware/` — HTTP boundary
- `src/Grpc/` — gRPC boundary
- `src/GitHub/`, `src/Notifier/`, `src/Cache/` — Pure Fabrications (api client, mailer, caches)
- `src/Exception/` — domain exceptions + `ExceptionStatusMap`
- `config/container.php` — single DI definitions file
- `tests/` — mirrors `src/`

## Conventions

- **`final readonly class`** for stateless services, repositories, controllers, middleware. Skip only when mutable state is required (see `SafeGitHubCacheDecorator`).
- **DTOs are anemic.** No `from*` static methods. Construction goes through `*FactoryInterface`.
- **Validators are injected classes**, never inline `filter_var` / regex inside services.
- **Per-consumer ISP** for repositories. Read/write may be split into separate classes (see `TrackedRepositoryReader` / `TrackedRepositoryWriter`), or one class may implement several narrow interfaces (see `SubscriptionRepository`).
- **`#[\Override]`** on every interface implementation method.
- **DI: bind interfaces only.** For shared instances across two interfaces, alias the second to the first. Never use a concrete class as a DI key just to share an instance.
- **One exception → status mapping** in `ExceptionStatusMap`, used by both `ErrorHandlerMiddleware` and `Grpc\ReleaseNotifierService`.
- **Rate limiting and control-flow exceptions** are caught at the orchestration layer (`ScannerService::scan`), not inside the unit method.
- **Wire-format protection.** JSON shape, gRPC reply, Behat assertions are public contract. Internal refactors must preserve them unless the user opts in.

## Quality gates

All three must pass before claiming a task done:

```bash
composer lint                       # PHPCS PSR-12
./vendor/bin/phpunit --no-coverage  # 81+ tests
composer psalm                      # 100% type coverage
```

Behat acceptance tests need the docker stack — ask before booting it.

## Refactor / review workflow

Triggered via the `php-refactor-workflow` skill — it owns the audit-first procedure, review checklist, and dependency-ordered execution pattern.
