# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN)
SERVICE_PHP = php

.PHONY: help ensure-up up down down-dev build shell install assets test test-coverage test-coverage-100 \
	check-twig-extra check-no-raw-html-form check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history \
	coverage-check cs-check cs-fix rector rector-dry phpstan igor qa release-check \
	release-check-demos composer-sync clean update validate validate-translations setup-hooks demo-smoke twig-lint

help:
	@echo "Auth Kit Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Container:"
	@echo "  up                Start Docker container (installs Composer deps when missing)"
	@echo "  down              Stop Docker container"
	@echo "  build             Rebuild Docker image (no cache)"
	@echo "  shell             Open shell in container"
	@echo ""
	@echo "Dependencies:"
	@echo "  install           Install Composer dependencies (starts container if needed)"
	@echo ""
	@echo "Assets:"
	@echo "  assets            No-op: the bundle ships hand-written public JS (no build step)"
	@echo ""
	@echo "Tests:"
	@echo "  test              Run PHPUnit tests"
	@echo "  test-coverage     Run tests with code coverage (prints the PHP percentage)"
	@echo ""
	@echo "Quality:"
	@echo "  cs-check          Check code style"
	@echo "  cs-fix            Fix code style"
	@echo "  rector            Apply Rector refactoring"
	@echo "  rector-dry        Run Rector in dry-run mode"
	@echo "  phpstan           Run PHPStan static analysis"
	@echo "  igor              Run Igor worker-state audit (REQ-CS-008)"
	@echo "  qa                Run cs-check and tests"
	@echo ""
	@echo "Release:"
	@echo "  release-check     Pre-release chain (REQ-MAKE-002): hygiene, Twig gates, QA, Igor, coverage gate,"
	@echo "                    translations, demos"
	@echo "  composer-sync     Validate composer.json and align composer.lock"
	@echo ""
	@echo "Cleanup:"
	@echo "  clean             Remove vendor, caches and coverage reports"
	@echo ""
	@echo "Composer:"
	@echo "  update            Update dependencies (composer update, bundle only)"
	@echo "  update-deps       Update bundle and demo dependencies (REQ-MAKE-008)"
	@echo "  validate          Validate composer.json (--strict)"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  test-coverage-100 Run coverage and fail below 100% (alias: coverage-check)"
	@echo "  validate-translations  YAML lint + key parity across locales (REQ-MAKE-004)"
	@echo "  check-twig-extra  Fail when Twig Extra is not wired (REQ-TWIG-004)"
	@echo "  check-no-raw-html-form  Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)"
	@echo "  check-no-cursor-coauthor  Fail on Cursor co-author trailers (REQ-GIT-001)"
	@echo "  check-open-prs    Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo "  twig-lint         Lint bundle Twig templates"
	@echo "  setup-hooks       Install git hooks (.githooks)"
	@echo "  down-dev          Stop root container (non-destructive; --remove-orphans)"
	@echo ""
	@echo "Demos:"
	@echo "  demo-smoke        Boot demo/symfony8 and assert HTTP 200 (REQ-TEST-011)"
	@echo "  release-check-demos  Run release-check in every demo"
	@echo "  make -C demo up-symfony8 / down-symfony8"

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
	@$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

qa: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update --lock --no-interaction


check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

check-no-raw-html-form:
	@bash .scripts/check-no-raw-html-form.sh

assets:
	@echo "No frontend build in this bundle (hand-written public JS)."

# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer igor

# REQ-MAKE-002 order.
release-check: ensure-up check-no-cursor-coauthor check-open-prs check-twig-extra check-no-raw-html-form composer-sync cs-fix cs-check rector-dry phpstan igor validate-translations test-coverage-100 release-check-demos

# REQ-TEST-011 — boot demo stack and assert one HTTP 200 (login page)
demo-smoke:
	@$(MAKE) -C demo/symfony8 up
	@PORT=$$(grep "^PORT=" demo/symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" demo/symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8010; \
	echo "Smoke GET http://localhost:$$PORT/en/login"; \
	code=000; \
	i=0; \
	while [ $$i -lt 30 ]; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/en/login" || true); \
		[ -z "$$code" ] && code=000; \
		if [ "$$code" = "200" ]; then break; fi; \
		i=$$((i+1)); \
		sleep 2; \
	done; \
	if [ "$$code" != "200" ]; then echo "demo-smoke failed: HTTP $$code"; exit 1; fi; \
	echo "demo-smoke OK (HTTP 200)"

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
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

twig-lint: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer twig:lint || $(COMPOSE) exec -T $(SERVICE_PHP) ./vendor/bin/twig-cs-fixer lint --config=.twig-cs-fixer.php
