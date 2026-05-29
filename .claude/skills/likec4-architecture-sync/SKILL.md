---
name: likec4-architecture-sync
description: Keep the LikeC4 architecture model in docs/architecture/ in sync with the PHP code under src/. Trigger when adding/renaming/removing controllers, services, repositories, middleware, gRPC handlers, or scanner components, when changing infrastructure (PostgreSQL, Redis, SMTP, GitHub) wiring, or whenever the user asks to update the C4 model.
---

# LikeC4 Architecture Sync

Keep the C4 model under `docs/architecture/` aligned with what the code actually does. The model is **architecture-as-code** — not a one-shot document. If the code changes, the model changes in the same PR.

## Source of truth

- **Code is canonical.** When the model and the code disagree, fix the model.
- The C4 model lives in 5 files under `docs/architecture/`:
  - `specification.c4` — element kinds (actor, system, container, component, database, cache) and styles
  - `landscape.c4` — System Context: actors, external systems, the system itself
  - `containers.c4` — Containers: HTTP API, gRPC API, Scanner, PostgreSQL, Redis
  - `components.c4` — Components inside `httpApi`, `grpcApi` and `scanner` containers
  - `views.c4` — All views (5 structural + 2 dynamic)

## When to update what

| Code change | Model file to update |
|---|---|
| New/renamed/removed controller in `src/Controller/` | `components.c4` (under `extend notifier.httpApi`) |
| New/renamed/removed service in `src/Service/` | `components.c4` (httpApi or scanner block — depending on usage) |
| New/renamed/removed middleware in `src/Middleware/` | `components.c4` (httpApi block) |
| New/renamed/removed repository in `src/Repository/` | `components.c4` (httpApi or scanner block) |
| New/renamed gRPC handler in `src/Grpc/` | `components.c4` (under `extend notifier.grpcApi`) |
| New deployable unit (e.g. new worker container in docker-compose) | `containers.c4` |
| New external dependency (new third-party API, message broker) | `landscape.c4` (external system) + `containers.c4` (relationships) |
| New flow worth documenting | `views.c4` (add a `dynamic view`) |

## Workflow

1. **Read the code change first.** Identify what entered, left, or moved.
2. **Map it to the right C4 level.** Components are PHP classes that hold business logic or data access. Containers are deployable units (Docker services). External systems live in `landscape.c4`.
3. **Edit the smallest set of files possible.**
4. **Validate.** Always run validation after edits:
   ```bash
   make c4-validate
   ```
   If validation fails, **fix the model — do not commit**. The most common error is `FqnRef "X" is empty` — see `reference/common-mistakes.md`.
5. **Verify visually if non-trivial.** For container/landscape-level changes:
   ```bash
   make c4-up
   ```
   Open `http://localhost:5173` and confirm the diagram makes sense.
6. **Commit model changes in the same PR as the code change.**

## Critical rules

1. **Always validate before committing.** `make c4-validate` is cheap; broken models break the docs site build.
2. **Use fully-qualified names (FQN) for cross-scope references.** Inside `extend notifier.httpApi { ... }`, sibling components can use short names. Anything outside that scope (e.g. `notifier.redis`, `github`, `smtp`) must be referenced by FQN. See `reference/common-mistakes.md`.
3. **Never duplicate elements.** A class instantiated in three containers (e.g. `GitHubService` lives in `httpApi`, `grpcApi`, and `scanner` — each builds its own DI container) is modeled as **three separate components** with distinct identifiers (`githubSvc`, `grpcGithubSvc`, `scannerGithub`). Same for `SubscriptionService` (`subscriptionSvc`, `grpcSubscriptionSvc`) and `SubscriptionRepository`. The C4 model represents runtime instances, not source files.
4. **Keep relationships meaningful.** Every `->` should describe a real call/dependency, with a verb-phrase label and (where useful) a protocol annotation.
5. **Do not add `autolayout`.** LikeC4 already auto-layouts. Manual layout hints belong in views, not the model.
6. **Do not invent C4 levels.** Stick to actor → system → container → component. If it does not fit, talk to the user before adding a new element kind in `specification.c4`.
7. **Element kinds are defined in `specification.c4`.** New kinds must be declared there before use.

## Adding new elements (cheat sheet)

**New container** (`containers.c4`):
```
extend notifier {
    newWorker = container "Name" {
        description "What it does."
        technology "PHP CLI / Slim / etc."
    }
}
notifier.newWorker -> notifier.db "..." "SQL/TCP"
```

**New component** (`components.c4`):
```
extend notifier.httpApi {
    fooSvc = component "FooService" {
        description "..."
        technology "PHP"
    }
    subscriptionSvc -> fooSvc "uses"
}
```

**New external system** (`landscape.c4`):
```
slack = externalSystem "Slack" {
    description "Outbound notifications channel."
}
notifier -> slack "Posts release alerts" "HTTPS"
```

**New dynamic view** (`views.c4`):
```
dynamic view newFlow {
    title "Dynamic — New Flow"
    description "..."

    user -> notifier.httpApi.someCtrl "POST /..."
    notifier.httpApi.someCtrl -> notifier.httpApi.someSvc "do(args)"
    // every step is `source -> target "label"`
}
```

## Quick reference

- **DSL syntax cheat sheet:** `reference/dsl-syntax.md`
- **Common mistakes & how to fix them:** `reference/common-mistakes.md`
- **Worked examples:** `examples/`

## Make targets you will use

```bash
make c4-validate   # check the model parses and FQNs resolve
make c4-up         # live preview at http://localhost:5173
make c4-down       # stop preview
make c4-logs       # tail container logs
```

All of these run via Docker (`ghcr.io/likec4/likec4:1.56.0`) — no local Node required.

The model is reviewed in the LikeC4 web UI; for one-off image exports use the
**Export** button in that UI. Build / batch PNG / Mermaid / D2 export targets are
not part of this branch — they are kept available as `npm run ...` scripts in
`docs/architecture/package.json` if a contributor needs them locally.
