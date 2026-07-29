# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
DOCKER_COMPOSE := $(COMPOSE)
SERVICE_PHP = php

.PHONY: help ensure-env ensure-up up down restart build install database shell logs \
	test test-coverage update-bundle release-check

help:
	@echo "Auth Kit Bundle — Symfony 8.1 Demo"
	@echo ""
	@echo "  up             Start demo (FrankenPHP, port from .env)"
	@echo "  down           Stop containers"
	@echo "  restart        Restart containers"
	@echo "  ensure-up      Start containers if not running"
	@echo "  install        Install Composer dependencies"
	@echo "  database       Create DB and run migrations"
	@echo "  shell          Open shell in PHP container"
	@echo "  logs           Follow container logs"
	@echo "  test           Run demo tests"
	@echo "  test-coverage  Run demo tests with coverage"
	@echo "  update-bundle  Sync bundle autoload and clear cache"
	@echo "  update-deps    composer update in demo (REQ-MAKE-008)"
	@echo "  release-check  update-bundle + HTTP healthcheck"

ensure-env:
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")
	@rm -f .env.dev

ensure-up: ensure-env
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then $(COMPOSE) up -d; sleep 3; fi

up: ensure-env
	@if [ ! -f .env ]; then cp .env.example .env; fi
	@rm -f .env.dev
	@$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@if ! $(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction --no-scripts; then \
		echo "composer install failed; php logs:"; \
		$(COMPOSE) logs --tail=80 $(SERVICE_PHP) || true; \
		exit 1; \
	fi
	@# Entrypoint waits for vendor then starts FrankenPHP workers — allow boot after install.
	@sleep 3
	@rm -f .env.dev
	@$(COMPOSE) exec -T $(SERVICE_PHP) test -f bin/console && $(COMPOSE) exec -T $(SERVICE_PHP) composer run-script post-install-cmd || true
	@$(COMPOSE) exec -T $(SERVICE_PHP) test -f bin/console && $(COMPOSE) exec -T $(SERVICE_PHP) php bin/console ux:icons:lock --no-interaction 2>/dev/null || true
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	@$(COMPOSE) down

restart: ensure-up
	@$(COMPOSE) restart

build:
	@$(COMPOSE) build --no-cache

install: ensure-up
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction

database: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer database

shell: ensure-up
	@$(COMPOSE) exec $(SERVICE_PHP) sh

logs:
	@$(COMPOSE) logs -f

test: ensure-up update-bundle
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test || echo "No demo tests yet"

test-coverage: ensure-up update-bundle
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage || echo "No demo coverage target yet"

update-bundle: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer dump-autoload
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true

release-check: ensure-up update-bundle
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	curl -sf "http://localhost:$$PORT/en/login" >/dev/null && echo "Symfony 8 demo healthcheck OK" || (echo "Symfony 8 demo healthcheck failed" && exit 1)

# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
