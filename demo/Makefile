.PHONY: help up-symfony7 down-symfony7 install-symfony7 database-symfony7 shell-symfony7 test-symfony7 \
	up-symfony8 down-symfony8 install-symfony8 database-symfony8 shell-symfony8 test-symfony8 \
	release-check update-bundle-symfony7 update-bundle-symfony8

help:
	@echo "Auth Kit Bundle — Demos"
	@echo ""
	@echo "Symfony 7.4 (port 8009):"
	@echo "  up-symfony7 down-symfony7 install-symfony7 database-symfony7 shell-symfony7 test-symfony7"
	@echo ""
	@echo "Symfony 8.1 (port 8010):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 database-symfony8 shell-symfony8 test-symfony8"
	@echo ""
	@echo "  release-check          Verify both demos"
	@echo "  update-bundle-symfony7 / update-bundle-symfony8"

up-symfony7:
	@$(MAKE) -C symfony7 up

down-symfony7:
	@$(MAKE) -C symfony7 down

install-symfony7:
	@$(MAKE) -C symfony7 install

database-symfony7:
	@$(MAKE) -C symfony7 database

shell-symfony7:
	@$(MAKE) -C symfony7 shell

test-symfony7:
	@$(MAKE) -C symfony7 test

update-bundle-symfony7:
	@$(MAKE) -C symfony7 update-bundle

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

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

release-check:
	@$(MAKE) up-symfony7
	@$(MAKE) update-bundle-symfony7
	@curl -sf http://localhost:8009/login >/dev/null && echo "Symfony 7.4 demo healthcheck OK" || (echo "Symfony 7.4 demo healthcheck failed" && exit 1)
	@$(MAKE) up-symfony8
	@$(MAKE) update-bundle-symfony8
	@curl -sf http://localhost:8010/login >/dev/null && echo "Symfony 8 demo healthcheck OK" || (echo "Symfony 8 demo healthcheck failed" && exit 1)
