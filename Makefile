COMPOSE = docker compose
SERVICE_PHP = php

.PHONY: help ensure-up up down down-dev build shell install test test-coverage test-coverage-100 \
	check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history \
	coverage-check cs-check cs-fix rector rector-dry phpstan qa release-check \
	release-check-demos composer-sync clean update validate validate-translations setup-hooks

help:
	@echo "Auth Kit Bundle - Development Commands"
	@echo ""
	@echo "  up down down-dev build shell install"
	@echo "  test test-coverage test-coverage-100 coverage-check"
	@echo "  cs-check cs-fix rector rector-dry phpstan qa"
	@echo "  validate-translations release-check release-check-demos composer-sync"
	@echo "  setup-hooks clean update validate"
	@echo "  down-dev      Stop root container (non-destructive; --remove-orphans)"
	@echo "  check-open-prs Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo ""
	@echo "Demo: make -C demo up-symfony8"

ensure-up:
	@$(COMPOSE) ps -q $(SERVICE_PHP) >/dev/null 2>&1 || true
	@$(COMPOSE) up -d --build
	@sleep 2
	@$(COMPOSE) exec -T $(SERVICE_PHP) sh -lc 'test -d vendor || composer install --no-interaction'

up: ensure-up

down:
	@$(COMPOSE) down

# REQ-MAKE-007: non-destructive stop for the root PHP container
down-dev:
	@$(COMPOSE) down --remove-orphans

build:
	@$(COMPOSE) build --no-cache

shell:
	@$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction

test: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test

test-coverage: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	@sh ./.scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-100: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage-100

coverage-check: test-coverage-100

cs-check: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector-dry: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

rector: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

phpstan: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

validate-translations: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) php vendor/bin/yaml-lint src/Resources/translations

qa: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update --lock --no-interaction

release-check: check-no-cursor-coauthor check-open-prs ensure-up composer-sync cs-fix cs-check rector-dry phpstan test-coverage-100 validate-translations release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-check

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

clean:
	rm -rf vendor .phpunit.cache coverage coverage.xml .php-cs-fixer.cache coverage-php.txt

update: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update

validate: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
