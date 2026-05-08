# Architecture (LikeC4)

C4-модель проєкту описана у текстових `.c4` файлах і рендериться через [LikeC4](https://likec4.dev).

## Структура

| Файл | Що описує |
|------|-----------|
| `specification.c4` | Типи елементів (actor, system, container, component, database, cache) і їхні стилі |
| `landscape.c4` | System Context — користувач, GitHub, SMTP, сама система |
| `containers.c4` | Container view — HTTP API, gRPC API, Scanner, PostgreSQL, Redis |
| `components.c4` | Component view — controllers, middleware, services, repository всередині контейнерів |
| `views.c4` | Views: landscape, container, components (HTTP API, gRPC API, Scanner), dynamic flows |

## Запуск через Docker (рекомендовано)

Не потребує локального Node — все запускається через `ghcr.io/likec4/likec4:1.56.0`.
Make-таргети викликаються з кореня проєкту:

```bash
make c4-up         # live preview на http://localhost:5173
make c4-down       # зупинити
make c4-logs       # логи контейнера
make c4-validate   # перевірити модель
```

Опційно — `LIKEC4_PORT=5174 make c4-up`, якщо 5173 зайнятий.

### Як отримати картинку діаграми

LikeC4 web-UI має кнопку **Export** на toolbar поточного view — можеш зберегти
PNG/SVG прямо з браузера. Для batch-експорту з командного рядка треба було б
повернути `c4-export-*` таргети + Playwright Chromium у компоуз; зараз це
свідомо не входить у мінімальний набір, бо для типових задач (показати діаграму,
зробити screenshot для звіту) вистачає UI.

## Запуск через npm (альтернатива)

Якщо Node 20+ є локально:

```bash
cd docs/architecture
npm install              # один раз
npm start                # live preview на http://localhost:5173
npm run validate         # перевірити модель
```

`package.json` тримає також скрипти `build`, `export:png`, `export:mermaid`, `export:d2`
для випадків, коли треба статичний сайт або файлові артефакти; вони працюють локально
без Docker і не покриваються Make-таргетами в цій гілці.

## Views

- **System Landscape** — хто з ким взаємодіє на найвищому рівні
- **Container View** — внутрішня структура системи (deployable units)
- **HTTP API Components** — controllers + middleware + services + repository
- **gRPC API Components** — gRPC service + own SubscriptionService/Repository/GitHubService instances
- **Scanner Components** — loop, github adapter, notifier, repository
- **Dynamic — Create Subscription** — флоу створення підписки через HTTP
- **Dynamic — Scan & Notify** — флоу сканера і відправки email

## Підтримка моделі при зміні коду

Модель — це частина коду, не разовий артефакт. Для AI-асистента є skill
`.claude/skills/likec4-architecture-sync/` із покроковими прикладами та правилами.

Короткий чек-лист для людини:

1. Змінив клас у `src/` → онови `components.c4`.
2. Додав/прибрав Docker-сервіс → онови `containers.c4`.
3. Підключив новий зовнішній API → онови `landscape.c4`.
4. Перед commit-ом: `make c4-validate`.

## VS Code

Для live-preview і автодоповнення став розширення **LikeC4** від `likec4`.
