COMPOSE = docker compose
SERVICE_PHP = php

.PHONY: help ensure-env up down build install database shell test update-bundle

help:
	@echo "Auth Kit Bundle — Symfony 7.4 Demo"
	@echo ""
	@echo "  up             Start demo (FrankenPHP, port from .env)"
	@echo "  down           Stop containers"
	@echo "  install        Install Composer dependencies"
	@echo "  database       Create DB and run migrations"
	@echo "  shell          Open shell in PHP container"
	@echo "  test           Run demo tests"
	@echo "  update-bundle  Sync bundle autoload and clear cache"

ensure-env:
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")

up: ensure-env
	@echo "Starting Auth Kit Symfony 7.4 demo (FrankenPHP)..."
	@$(COMPOSE) build
	@$(COMPOSE) up -d
	@sleep 3
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --no-scripts
	@$(COMPOSE) exec -T $(SERVICE_PHP) test -f bin/console && $(COMPOSE) exec -T $(SERVICE_PHP) composer run-script post-install-cmd || true
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@PORT=$$(grep "^PORT=" .env | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	@$(COMPOSE) down

build:
	@$(COMPOSE) build

install:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install

database:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer database

shell:
	@$(COMPOSE) exec $(SERVICE_PHP) sh

test:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test || echo "No demo tests yet"

update-bundle:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer dump-autoload
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true
