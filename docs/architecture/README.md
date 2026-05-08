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

Не потребує локального Node — все запускається через `ghcr.io/likec4/likec4`.
Make-таргети викликаються з кореня проєкту:

```bash
make c4-up                   # live preview на http://localhost:5173
make c4-down                 # зупинити
make c4-logs                 # логи контейнера
make c4-validate             # перевірити модель
make c4-build                # статичний сайт у docs/architecture/dist
make c4-install-browsers     # one-time: встановити Chromium у іменований volume для PNG-експорту
make c4-export-png           # PNG: піднімає сервер, експортує, ЗУПИНЯЄ сервер
make c4-export-png-live      # PNG проти вже запущеного c4-up (без чіпання lifecycle)
make c4-export-mermaid       # .mmd у docs/architecture/exports/mermaid
make c4-export-d2            # .d2  у docs/architecture/exports/d2
```

Опційно — `LIKEC4_PORT=5174 make c4-up`, якщо 5173 зайнятий.

### Поведінка PNG-експорту

- `c4-export-png` — **self-contained**: піднімає LikeC4-сервер, чекає readiness через
  `curl http://localhost:$LIKEC4_PORT/`, експортує і **зупиняє** сервер у кінці. Якщо
  у тебе вже був `make c4-up` запущений — він буде зупинений в кінці. Підходить для CI
  та одноразового експорту.
- `c4-export-png-live` — для інтерактивної роботи: вимагає попередньо запущеного
  `make c4-up`, **не чіпає** сервер. Швидше при повторних експортах.
- Обидва роблять два проходи: спочатку всі views у graph layout, потім **dynamic flows
  переекспортуються з `--sequence`** layout (вертикальніший, краще читаються кроки).
- Перший раз скачається Chromium (~150 MB) у named volume `likec4-playwright`,
  далі шар кешується.

### Який формат для чого

| Що показуєш | Рекомендований формат | Чому |
|---|---|---|
| Live drill-down, demo, презентація | `make c4-up` (web) | Інтерактивне, можна зумити, перемикати views |
| Статичний сайт для GitHub Pages | `make c4-build` (`./dist`) | Production-ready bundle |
| Картинки у README репозиторію | `make c4-export-mermaid` | GitHub рендерить `.mmd` нативно |
| Картинки у звіт/PDF | `make c4-export-png` | Готові растрові зображення |
| Review довгого flow-у | Mermaid (sequence) > PNG | Mermaid sequence у flow-views читається найкраще; PNG-flow буде широкий |

## Запуск через npm (альтернатива)

Якщо Node 20+ є локально:

```bash
cd docs/architecture
npm install              # один раз

npm start                  # live preview на http://localhost:5173
npm run validate           # перевірити модель
npm run build              # статичний сайт у ./dist
npm run install:browsers   # one-time: Playwright Chromium для PNG
npm run export:png         # PNG у ./exports (запускає install:browsers перед експортом)
npm run export:mermaid     # .mmd у ./exports/mermaid
npm run export:d2          # .d2  у ./exports/d2
```

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
