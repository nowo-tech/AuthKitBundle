# Spec-driven development

## Table of contents

- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [Validation](#validation)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [See also](#see-also)

This repository uses **spec-driven development** with three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — login/register flows, configuration, and integration with Symfony Security ([`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md)).
3. **Traceability anchors** — `REQ-*` anchors in Makefiles and alignment with org-wide checklist docs ([`ENGRAM.md`](ENGRAM.md)).

PHPUnit and PHPStan enforce contracts in CI. There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As a** Symfony integrator, **I want** configurable login/register routes and forms **so that** I avoid boilerplate controllers. |
| US-02 | **As an** integrator, **I want** registration modes (`disabled`, `first_user_only`, `always`) **so that** I control self-service signup. |
| US-03 | **As an** integrator, **I want** overridable Twig templates and translations **so that** I match my application UI. |
| US-04 | **As an** integrator, **I want** documented `security.yaml` setup **so that** `form_login` works with bundle routes. |
| US-05 | **As a** maintainer, **I want** tests and static analysis **so that** regressions are caught in CI. |
| US-10 | **As an** integrator, **I want** optional slide-to-confirm on registration consent and QR approve **so that** irreversible actions get a gesture without a hard Composer dependency. |
| US-11 | **As an** integrator, **I want** optional device intelligence on auth pages **so that** I can observe devices, notify on new clusters, extra-limit by ULID, and require a trusted device on QR approve without a hard Composer dependency. |

## Functional scope

**In scope:** configurable user entity/fields, registration modes, login/logout/register routes, password reset (request/code/complete), remember-me, auth embed, locale-aware routing, Twig templates, i18n, `ConfigureSecurityCommand`, optional slide-to-confirm (registration consent + QR approve), optional device intelligence (collect, new-device notify, device rate limit, QR trusted-device step-up). The full product surface is [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md).

**Out of scope:** OAuth/OIDC social login, email verification beyond password reset, authorization rules beyond registration role assignment.

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
| REQ-DEMO-005 | `demo/symfony8/Makefile` | `up` prints demo URL with `PORT` |
| REQ-DEMO-007 | `demo/symfony8/Makefile` | `update-bundle` syncs bundle code |

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR (functional spec + Makefile/demo impact).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes (`REQ-*` comments).
4. **Ship integrator docs** when behavior or configuration changes ([`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md)).
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md)
- [ENGRAM.md](ENGRAM.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
