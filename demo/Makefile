DEMOS := symfony8

.PHONY: help up down update-bundle test test-coverage release-check \
	up-symfony8 down-symfony8 install-symfony8 database-symfony8 shell-symfony8 \
	test-symfony8 test-coverage-symfony8 update-bundle-symfony8

help:
	@echo "Auth Kit Bundle — Demos"
	@echo ""
	@echo "Symfony 8.1 (port 8010):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 database-symfony8 shell-symfony8"
	@echo "  test-symfony8 test-coverage-symfony8 update-bundle-symfony8"
	@echo ""
	@echo "Aliases: up down update-bundle test test-coverage"
	@echo "  release-check          Verify demo"
	@echo "  update-deps-all        composer update in all demos (REQ-MAKE-008)"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

install-symfony8:
	@$(MAKE) -C symfony8 install

database-symfony8:
	@$(MAKE) -C symfony8 database

shell-symfony8:
	@$(MAKE) -C symfony8 shell

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

up:
	@$(MAKE) -C symfony8 up

down:
	@$(MAKE) -C symfony8 down

update-bundle:
	@$(MAKE) -C symfony8 update-bundle

test:
	@$(MAKE) -C symfony8 test

test-coverage:
	@$(MAKE) -C symfony8 test-coverage

release-check:
	@$(MAKE) -C symfony8 release-check

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
