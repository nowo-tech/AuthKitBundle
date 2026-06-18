# Spec-driven development

## Table of contents

- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [Validation](#validation)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [See also](#see-also)

This repository uses **spec-driven development** with two layers:

1. **Product behavior** — login/register flows, configuration, and integration with Symfony Security ([USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md)).
2. **Traceability** — `REQ-*` anchors in Makefiles and alignment with [BUNDLES_FULL_SPECS_DETAILS.md](https://github.com/nowo-tech/bundles/blob/main/BUNDLES_FULL_SPECS_DETAILS.md).

PHPUnit and PHPStan enforce contracts in CI.

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As a** Symfony integrator, **I want** configurable login/register routes and forms **so that** I avoid boilerplate controllers. |
| US-02 | **As an** integrator, **I want** registration modes (`disabled`, `first_user_only`, `always`) **so that** I control self-service signup. |
| US-03 | **As an** integrator, **I want** overridable Twig templates and translations **so that** I match my application UI. |
| US-04 | **As an** integrator, **I want** documented `security.yaml` setup **so that** `form_login` works with bundle routes. |
| US-05 | **As a** maintainer, **I want** tests and static analysis **so that** regressions are caught in CI. |

## Functional scope

**In scope:** configurable user entity/fields, registration role, routes, templates, i18n, CLI security helper.

**Out of scope:** password reset, email verification, OAuth, authorization rules beyond registration role assignment.

## Validation

- `make qa` / `make release-check`
- `make test-coverage-100` / `composer coverage-check` (100% line coverage on `src/`)
- PHPStan level 8

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-MAKE-006 | Root `Makefile` | `setup-hooks` installs `.githooks/pre-commit` |
| REQ-MAKE-008 | Root `Makefile` | `update-deps` includes bundle + demos |
| REQ-TEST-006 | Root `Makefile` / `composer.json` | `test-coverage-100` / `coverage-check` |
| REQ-DEMO-005 | `demo/symfony7/Makefile`, `demo/symfony8/Makefile` | `up` prints demo URL with `PORT` |
| REQ-DEMO-007 | `demo/symfony7/Makefile`, `demo/symfony8/Makefile` | `update-bundle` syncs bundle code |

## See also

- [ENGRAM.md](ENGRAM.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
