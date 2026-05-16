# Testing

## Prerequisites

- `git`
- `docker` (with `docker compose` v2)

No local PHP, Node, or Composer required — every suite runs inside containers.
On first run Docker will build the app image and pull `postgres` / `redis` /
`playwright` images (~2-3 min). Subsequent runs use the cache and finish in
seconds.

## Run every test suite in one command

```bash
make tests
```

Sequence: unit → integration → acceptance → e2e. Each step boots only the
services it needs and tears them down afterwards — even if the suite fails.

## Per-suite commands

| Command            | Suite                        | What runs in Docker                              |
| ------------------ | ---------------------------- | ------------------------------------------------ |
| `make test`        | PHPUnit **Unit** (55 tests)  | `app` image                                      |
| `make integration` | PHPUnit **Integration** (32) | `app` + Postgres + Redis                         |
| `make acceptance`  | Behat **Acceptance** (27)    | `app` + Postgres + Redis + scanner + grpc + smtp |
| `make e2e`         | Playwright **E2E** (6)       | `app` + Postgres + Redis + Playwright image      |

Each `make X` is end-to-end: it brings the required Docker stack up, runs the
suite, and brings it down with volumes removed — even on failure. The next
run starts from a clean DB.

External GitHub API is **never** contacted: the test stack exports
`GITHUB_STUB=true`, which makes the container bind `FakeGitHubService` instead
of the real one. Repository names beginning with `nonexistent` simulate a
GitHub 404 so 404 paths stay covered.

## What each suite covers

- **Unit** — services, controllers, middleware with mocked collaborators. No
  I/O.
- **Integration** — `SubscriptionRepository`, `RedisGitHubCache`, `Migrator`
  against real Postgres and Redis. No HTTP layer. Each test starts on a
  truncated DB and flushed Redis.
- **Acceptance** — REST API contract: HTTP requests against the running Slim
  app; responses validated against `swagger.yaml` by `behat-open-api`.
- **E2E** — Playwright drives headless Chromium against the homepage (`/`):
  subscribe / list / unsubscribe flows.

## Running a single test

```bash
# Single PHPUnit test by name
docker compose -f docker-compose.yml -f docker-compose.test.yml \
  run --rm --no-deps app \
  vendor/bin/phpunit --testsuite Integration --filter testCreateIsIdempotentOnDuplicate

# Single Behat scenario by line number
docker compose -f docker-compose.yml -f docker-compose.test.yml \
  exec -T app composer acceptance -- features/subscription.feature:62

# Single Playwright spec
docker compose -f docker-compose.yml -f docker-compose.test.yml -f docker-compose.e2e.yml \
  run --rm playwright sh -c "npm ci && npx playwright test subscription.spec.ts -g 'unsubscribes'"
```

For Behat/E2E you must have the stack already up (`make acceptance-up` /
`make e2e-up`); use the matching `-down` target when finished.

## CI

Each suite is its own GitHub Actions workflow, all running in parallel on
every push and pull request:

| Workflow                                  | Job                                                    |
| ----------------------------------------- | ------------------------------------------------------ |
| `.github/workflows/unit-tests.yml`        | PHPUnit Unit                                           |
| `.github/workflows/integration-tests.yml` | PHPUnit Integration                                    |
| `.github/workflows/acceptance-tests.yml`  | Behat                                                  |
| `.github/workflows/e2e-tests.yml`         | Playwright (uploads HTML report and traces on failure) |

## Troubleshooting

- **Port conflict on 5432 / 6379 / 8080** — stop other Postgres / Redis / app
  instances, or change `APP_PORT` in `.env`.
- **Stale Docker state after a failed run** — `make acceptance-down` /
  `make e2e-down` / `make integration-down` removes containers and volumes.
- **Root-owned files in `tests/e2e/`** — Playwright container runs as root.
  Clean with:
  ```bash
  docker run --rm -v "$(pwd)/tests/e2e:/work" \
    mcr.microsoft.com/playwright:v1.49.1-noble \
    sh -c 'rm -rf /work/node_modules /work/playwright-report /work/test-results'
  ```
