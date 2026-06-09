.PHONY: help ensure-env install build up down restart logs migrate test lint deptrac psalm check proto \
        acceptance-up acceptance-run acceptance-down acceptance \
        acceptance-auth-up acceptance-auth-run acceptance-auth-down acceptance-auth \
        integration-up integration-run integration-down integration \
        e2e-up e2e-run e2e-down e2e \
        e2e-auth-up e2e-auth-run e2e-auth-down e2e-auth \
        tests ci c4-up c4-down c4-logs c4-validate \
        logs-rabbitmq logs-notification-db logs-notification-svc \
        migrate-notification notification-smoke scanner-smoke \
        notification-unit notification-deptrac \
        notification-integration-up notification-integration-run notification-integration-down notification-integration

HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

COMPOSE := docker compose
TEST_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml
E2E_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml -f docker-compose.e2e.yml
E2E_AUTH_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml -f docker-compose.e2e.yml -f docker-compose.e2e-auth.yml
ACCEPTANCE_AUTH_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml -f docker-compose.acceptance-auth.yml
C4_COMPOSE := HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose -f docker-compose.architecture.yml
C4_RUN := $(C4_COMPOSE) run --rm --no-deps -T likec4

help: ## Show this help
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

ensure-env: ## Create .env from example if missing
	@test -f .env || cp .env.example .env

install: ensure-env ## Prepare Docker images for all workflows
	$(COMPOSE) build

build: install ## Alias for install

up: ensure-env ## Start the full Dockerized application stack
	$(COMPOSE) up -d --build

down: ## Stop the Dockerized application stack
	$(COMPOSE) down

restart: ## Restart running application containers
	$(COMPOSE) restart

logs: ## Show Docker logs
	$(COMPOSE) logs -f

logs-rabbitmq: ## Tail RabbitMQ broker logs (management UI: http://localhost:15672)
	$(COMPOSE) logs -f rabbitmq

logs-notification-db: ## Tail notification-db (Postgres) logs
	$(COMPOSE) logs -f notification-db

logs-notification-svc: ## Tail notification-service worker logs
	$(COMPOSE) logs -f notification-svc

migrate: ensure-env ## Run database migrations inside Docker
	$(COMPOSE) exec -T app php bin/migrate.php

migrate-notification: ensure-env ## Run notification-service migrations inside Docker
	$(COMPOSE) exec -T notification-svc php bin/migrate.php

notification-smoke: ensure-env ## Publish a notification smoke message and wait for MailHog delivery
	$(COMPOSE) run --rm --no-deps notification-svc php bin/smoke.php

scanner-smoke: ensure-env ## Seed a smoke release, run one scan cycle, and wait for MailHog delivery
	$(COMPOSE) run --rm --no-deps app php bin/scanner-smoke.php

notification-integration-up: ensure-env ## Start notification-db + rabbitmq + mailhog for the notification service's Integration suite
	$(COMPOSE) stop notification-svc
	$(COMPOSE) up -d --wait notification-db rabbitmq mailhog

notification-integration-run: ## Run the notification service's PHPUnit Integration suite inside Docker (requires notification-integration-up)
	$(COMPOSE) run --rm --no-deps \
		-v "$(PWD)/apps/notification/tests:/app/tests:ro" \
		-v "$(PWD)/apps/notification/phpunit.xml:/app/phpunit.xml:ro" \
		notification-svc sh -c \
		"composer install --no-interaction --ignore-platform-reqs --quiet && vendor/bin/phpunit --testsuite Integration --testdox"

notification-integration-down: ## Restart notification-svc (stopped by -up so it wouldn't race the suite for queue ownership)
	$(COMPOSE) up -d notification-svc

notification-integration: notification-integration-up ## Run the notification service's PHPUnit Integration suite end-to-end
	@$(MAKE) notification-integration-run; status=$$?; $(MAKE) notification-integration-down; exit $$status

test: install ## Run PHPUnit Unit suite inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --testsuite Unit --testdox

notification-unit: install ## Run the notification service's PHPUnit Unit suite inside Docker
	$(COMPOSE) run --rm --no-deps -w /app/apps/notification app sh -c \
		"composer install --no-interaction --quiet && vendor/bin/phpunit --testsuite Unit --testdox"

lint: install ## Run PHP_CodeSniffer inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs

deptrac: install ## Run deptrac architecture-boundary check (monolith) inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/deptrac analyse --no-progress --no-cache

notification-deptrac: install ## Run the notification service's deptrac architecture check inside Docker
	$(COMPOSE) run --rm --no-deps -w /app/apps/notification app sh -c \
		"composer install --no-interaction --quiet && vendor/bin/deptrac analyse --no-progress --no-cache"

psalm: install ## Run Psalm inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/psalm

check: install ## Run lint, architecture, static analysis and unit tests inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs
	$(COMPOSE) run --rm --no-deps app vendor/bin/deptrac analyse --no-progress --no-cache
	$(COMPOSE) run --rm --no-deps app vendor/bin/psalm
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --testsuite Unit --testdox

proto: install ## Generate protobuf and gRPC PHP classes inside Docker
	$(COMPOSE) run --rm --no-deps -v "$(PWD):/app" app bash -lc "chmod +x tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc && mkdir -p generated && chown -R $(HOST_UID):$(HOST_GID) generated && protoc --plugin=protoc-gen-php-grpc=tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc --php_out=generated --php-grpc_out=generated proto/release_notifier.proto && chown -R $(HOST_UID):$(HOST_GID) generated"

integration-up: install ensure-env ## Start Postgres + Redis for integration tests
	$(TEST_COMPOSE) up -d --wait postgres redis

integration-run: ## Run PHPUnit Integration suite inside Docker (requires running services)
	$(TEST_COMPOSE) run --rm --no-deps app vendor/bin/phpunit --testsuite Integration --testdox

integration-down: ## Stop integration environment and remove volumes
	$(TEST_COMPOSE) down -v

integration: integration-up ## Run PHPUnit Integration suite end-to-end
	@$(MAKE) integration-run; status=$$?; $(MAKE) integration-down; exit $$status

acceptance-up: ensure-env ## Start acceptance environment in Docker
	$(TEST_COMPOSE) up -d --build --wait

acceptance-run: ## Run Behat acceptance tests inside Docker (requires running services)
	$(TEST_COMPOSE) exec -T app composer acceptance

acceptance-down: ## Stop acceptance environment and remove volumes
	$(TEST_COMPOSE) down -v

acceptance: acceptance-up ## Run Behat acceptance tests end-to-end
	@$(MAKE) acceptance-run; status=$$?; $(MAKE) acceptance-down; exit $$status

acceptance-auth-up: ensure-env ## Start acceptance environment with API_KEY auth enabled
	$(ACCEPTANCE_AUTH_COMPOSE) up -d --build --wait

acceptance-auth-run: ## Run Behat @auth scenarios inside Docker (requires running services)
	$(ACCEPTANCE_AUTH_COMPOSE) exec -T app composer acceptance-auth

acceptance-auth-down: ## Stop the auth-enabled acceptance environment and remove volumes
	$(ACCEPTANCE_AUTH_COMPOSE) down -v

acceptance-auth: acceptance-auth-up ## Run Behat @auth suite end-to-end
	@$(MAKE) acceptance-auth-run; status=$$?; $(MAKE) acceptance-auth-down; exit $$status

e2e-up: ensure-env ## Start E2E environment (app stack) in Docker
	$(E2E_COMPOSE) up -d --build --wait app

e2e-run: ## Run Playwright E2E tests inside Docker (requires running app)
	$(E2E_COMPOSE) run --rm playwright

e2e-down: ## Stop E2E environment and remove volumes
	$(E2E_COMPOSE) down -v

e2e: e2e-up ## Run Playwright E2E tests end-to-end
	@$(MAKE) e2e-run; status=$$?; $(MAKE) e2e-down; exit $$status

e2e-auth-up: ensure-env ## Start E2E environment with API_KEY auth enabled
	$(E2E_AUTH_COMPOSE) up -d --build --wait app

e2e-auth-run: ## Run Playwright auth-on suite inside Docker (requires running app)
	$(E2E_AUTH_COMPOSE) run --rm playwright

e2e-auth-down: ## Stop the auth-enabled E2E environment and remove volumes
	$(E2E_AUTH_COMPOSE) down -v

e2e-auth: e2e-auth-up ## Run Playwright auth-on E2E suite end-to-end
	@$(MAKE) e2e-auth-run; status=$$?; $(MAKE) e2e-auth-down; exit $$status

tests: ## Run every test suite (unit, notification-unit, integration, notification-integration, acceptance, acceptance-auth, e2e, e2e-auth)
	$(MAKE) test
	$(MAKE) notification-unit
	$(MAKE) integration
	$(MAKE) notification-integration
	$(MAKE) acceptance
	$(MAKE) acceptance-auth
	$(MAKE) e2e
	$(MAKE) e2e-auth

ci: install ## Run the full Dockerized CI pipeline locally
	$(MAKE) lint
	$(MAKE) deptrac
	$(MAKE) notification-deptrac
	$(MAKE) psalm
	$(MAKE) tests

c4-up: ## Start LikeC4 live preview at http://localhost:5173
	$(C4_COMPOSE) up -d
	@echo "LikeC4 preview: http://localhost:$${LIKEC4_PORT:-5173}"

c4-down: ## Stop LikeC4 preview
	$(C4_COMPOSE) down

c4-logs: ## Tail LikeC4 logs
	$(C4_COMPOSE) logs -f

c4-validate: ## Validate the LikeC4 model
	$(C4_RUN) validate
