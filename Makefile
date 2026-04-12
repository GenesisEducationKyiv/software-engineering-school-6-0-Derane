.PHONY: help ensure-env install build up down restart logs migrate test lint stan check proto behat-up behat-down behat ci

COMPOSE := docker compose
TEST_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

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

test: install ## Run PHPUnit inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --configuration phpunit.xml --testdox

lint: install ## Run PHP_CodeSniffer inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs --standard=PSR12 src/ config/ bin/ tests/

stan: install ## Run PHPStan inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpstan analyse

check: install ## Run lint, static analysis and unit tests inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs --standard=PSR12 src/ config/ bin/ tests/
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpstan analyse
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --configuration phpunit.xml --testdox

proto: install ## Generate protobuf and gRPC PHP classes inside Docker
	$(COMPOSE) run --rm --no-deps -v "$(PWD):/app" app bash -lc "chmod +x tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc && mkdir -p generated && chown -R $(HOST_UID):$(HOST_GID) generated && protoc --plugin=protoc-gen-php-grpc=tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc --php_out=generated --php-grpc_out=generated proto/release_notifier.proto && chown -R $(HOST_UID):$(HOST_GID) generated"

behat-up: ensure-env ## Start acceptance environment in Docker
	$(TEST_COMPOSE) up -d --build --wait

behat-down: ## Stop acceptance environment and remove test volumes
	$(TEST_COMPOSE) down -v

behat: behat-up ## Run Behat acceptance tests inside Docker
	$(TEST_COMPOSE) exec -T app composer acceptance
	$(MAKE) behat-down

ci: install ## Run the full Dockerized CI pipeline
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs --standard=PSR12 src/ config/ bin/ tests/
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpstan analyse
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --configuration phpunit.xml --testdox
	$(MAKE) behat
