.PHONY: help ensure-env install build up down restart logs migrate test lint psalm check proto \
        acceptance-up acceptance-run acceptance-down acceptance \
        integration-up integration-run integration-down integration \
        e2e-up e2e-run e2e-down e2e tests ci c4-up c4-down c4-logs c4-validate

HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

COMPOSE := docker compose
TEST_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml
E2E_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml -f docker-compose.e2e.yml
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

migrate: ensure-env ## Run database migrations inside Docker
	$(COMPOSE) exec -T app php bin/migrate.php

test: install ## Run PHPUnit Unit suite inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --testsuite Unit --testdox

lint: install ## Run PHP_CodeSniffer inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs

psalm: install ## Run Psalm inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/psalm

check: install ## Run lint, static analysis and unit tests inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs
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

e2e-up: ensure-env ## Start E2E environment (app stack) in Docker
	$(E2E_COMPOSE) up -d --build --wait app

e2e-run: ## Run Playwright E2E tests inside Docker (requires running app)
	$(E2E_COMPOSE) run --rm playwright

e2e-down: ## Stop E2E environment and remove volumes
	$(E2E_COMPOSE) down -v

e2e: e2e-up ## Run Playwright E2E tests end-to-end
	@$(MAKE) e2e-run; status=$$?; $(MAKE) e2e-down; exit $$status

tests: ## Run every test suite (unit, integration, acceptance, e2e)
	$(MAKE) test
	$(MAKE) integration
	$(MAKE) acceptance
	$(MAKE) e2e

ci: install ## Run the full Dockerized CI pipeline locally
	$(MAKE) lint
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
