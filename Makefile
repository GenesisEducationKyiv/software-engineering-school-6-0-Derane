.PHONY: help ensure-env install build up down restart logs migrate test lint psalm check proto behat-up behat-run behat-down behat ci c4-up c4-down c4-logs c4-validate c4-build c4-install-browsers c4-reinstall-browsers c4-wait-ready c4-export-png c4-export-png-live c4-export-mermaid c4-export-d2

HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

COMPOSE := docker compose
TEST_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.test.yml
C4_COMPOSE := HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose -f docker-compose.architecture.yml
C4_RUN := $(C4_COMPOSE) run --rm --no-deps -T likec4
C4_SH := $(C4_COMPOSE) run --rm --no-deps -T --entrypoint sh likec4
C4_HOST_URL := http://localhost:$${LIKEC4_PORT:-5173}/
C4_EXPORT_PNG := $(C4_RUN) export png -o ./exports -t 60 --server-url http://likec4:5173

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

test: install ## Run PHPUnit inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --configuration phpunit.xml --testdox

lint: install ## Run PHP_CodeSniffer inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs --standard=PSR12 src/ config/ bin/ tests/

psalm: install ## Run Psalm inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/psalm

check: install ## Run lint, static analysis and unit tests inside Docker
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs --standard=PSR12 src/ config/ bin/ tests/
	$(COMPOSE) run --rm --no-deps app vendor/bin/psalm
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --configuration phpunit.xml --testdox

proto: install ## Generate protobuf and gRPC PHP classes inside Docker
	$(COMPOSE) run --rm --no-deps -v "$(PWD):/app" app bash -lc "chmod +x tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc && mkdir -p generated && chown -R $(HOST_UID):$(HOST_GID) generated && protoc --plugin=protoc-gen-php-grpc=tools/bin/protoc-gen-php-grpc-2025.1.12-linux-amd64/protoc-gen-php-grpc --php_out=generated --php-grpc_out=generated proto/release_notifier.proto && chown -R $(HOST_UID):$(HOST_GID) generated"

behat-up: ensure-env ## Start acceptance environment in Docker
	$(TEST_COMPOSE) up -d --build --wait

behat-run: ## Run Behat tests inside Docker (requires running services)
	$(TEST_COMPOSE) exec -T app composer acceptance

behat-down: ## Stop acceptance environment and remove test volumes
	$(TEST_COMPOSE) down -v

behat: behat-up ## Run Behat acceptance tests inside Docker
	$(MAKE) behat-run
	$(MAKE) behat-down

ci: install ## Run the full Dockerized CI pipeline
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpcs --standard=PSR12 src/ config/ bin/ tests/
	$(COMPOSE) run --rm --no-deps app vendor/bin/psalm
	$(COMPOSE) run --rm --no-deps app vendor/bin/phpunit --configuration phpunit.xml --testdox
	$(MAKE) behat

c4-up: ## Start LikeC4 live preview at http://localhost:5173
	$(C4_COMPOSE) up -d
	@echo "LikeC4 preview: http://localhost:$${LIKEC4_PORT:-5173}"

c4-down: ## Stop LikeC4 preview
	$(C4_COMPOSE) down

c4-logs: ## Tail LikeC4 logs
	$(C4_COMPOSE) logs -f

c4-validate: ## Validate the LikeC4 model
	$(C4_RUN) validate

c4-build: ## Build static LikeC4 site to docs/architecture/dist
	$(C4_RUN) build -o ./dist

c4-install-browsers: ## Install Playwright Chromium (idempotent; stamp is keyed by LikeC4 version, so image upgrades trigger reinstall)
	$(C4_SH) -lc 'mkdir -p /data/.likec4-cache/playwright && cd /usr/local/lib/node_modules/likec4 && v=$$(likec4 --version) && stamp="/data/.likec4-cache/playwright/.likec4-installed-$$v" && test -f "$$stamp" || ( npx playwright install chromium-headless-shell && touch "$$stamp" )'

c4-reinstall-browsers: ## Force re-install of Playwright Chromium (drops all version stamps)
	$(C4_SH) -lc 'mkdir -p /data/.likec4-cache/playwright && rm -f /data/.likec4-cache/playwright/.likec4-installed* && cd /usr/local/lib/node_modules/likec4 && v=$$(likec4 --version) && npx playwright install chromium-headless-shell && touch "/data/.likec4-cache/playwright/.likec4-installed-$$v"'

c4-wait-ready: ## Block until LikeC4 server responds at http://localhost:$$LIKEC4_PORT (used by exports)
	@echo "Waiting for LikeC4 server at $(C4_HOST_URL)..."
	@timeout 60 sh -c 'until curl -sfI $(C4_HOST_URL) >/dev/null 2>&1; do sleep 1; done'

c4-export-png: c4-install-browsers ## Export PNG (self-contained; cleans up server on failure or Ctrl+C)
	@set -e; \
		trap '$(C4_COMPOSE) down >/dev/null 2>&1' EXIT INT TERM; \
		$(C4_COMPOSE) up -d; \
		$(MAKE) --no-print-directory c4-wait-ready; \
		$(C4_EXPORT_PNG); \
		$(C4_EXPORT_PNG) --sequence -f "*Flow"

c4-export-png-live: c4-install-browsers c4-wait-ready ## Export PNG against an already-running c4-up (no server lifecycle)
	$(C4_EXPORT_PNG)
	$(C4_EXPORT_PNG) --sequence -f "*Flow"

c4-export-mermaid: ## Export Mermaid (.mmd) sources to docs/architecture/exports/mermaid
	$(C4_RUN) gen mermaid --outdir ./exports/mermaid

c4-export-d2: ## Export D2 (.d2) sources to docs/architecture/exports/d2
	$(C4_RUN) gen d2 --outdir ./exports/d2
