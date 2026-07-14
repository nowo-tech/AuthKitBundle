# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.4] - 2026-07-14

### Added

- **GitHub Spec Kit** baseline: `specs/001-baseline/` (`spec.md`, `code-inventory.md` covering 100% of `src/`), operator manual [`SPEC-KIT.md`](SPEC-KIT.md), Cursor Agent skills (`.cursor/skills/speckit-*`), and `.specify/` scaffolding.
- Maintainer workflow and checklist in [`SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md); link from root [`README.md`](../README.md).

### Changed

- Dev dependency: `nowo-tech/password-toggle-bundle` ^2.0.0 (`require-dev`).

[1.4.4]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.4.4

## [1.4.3] - 2026-07-06

### Fixed

- Password field labels inherit the parent form `translation_domain` (`NowoAuthKitBundle`) instead of defaulting to `PasswordStrengthBundle` when password strength is enabled.

[1.4.3]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.4.3

## [1.4.2] - 2026-07-05

### Added

- `PasswordRepeatedFieldBuilder` centralizes password + confirmation fields for registration and password-reset forms.

### Fixed

- When `password_strength.enabled` is true, the confirmation field no longer uses `PasswordStrengthType` (match validation only via toggle/Symfony `PasswordType`).

[1.4.2]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.4.2

## [1.4.1] - 2026-07-05

### Fixed

- Restore **100%** PHPUnit coverage (`PasswordFieldTypeResolver` `class_exists` path) required by CI.

### Changed

- CI: validate coverage with `scripts/check-coverage.php` (statements and elements); bump `actions/checkout` to v7.

[1.4.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.4.1

## [1.4.0] - 2026-07-05

### Added

- Translation catalogues **de**, **fr**, **it**, **nl**, and **pt** for domain `NowoAuthKitBundle`.
- Login template variable `registration_allowed` (from `RegistrationGate`) to show or hide the register link consistently with `registration_mode`.

### Changed

- Default login template and demo overrides hide the register link when registration is not allowed (`disabled`, or `first_user_only` after the first user).
- Login footer omits the whole block when neither password reset nor registration links apply.
- [USAGE.md](USAGE.md): documents `registration_allowed`, `reset_password_route`, and `password_reset_enabled` template variables.

[1.4.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.4.0

## [1.3.0] - 2026-07-03

### Added

- Optional **`password_strength`** configuration for integration with `nowo-tech/password-strength-bundle` on registration and password-reset fields (`enabled`, `level`, `policy_mode`).
- `PasswordFieldConstraintResolver`: applies `PasswordStrength` validator when strength is enabled, otherwise `Length(min: 6)`.
- `PasswordFieldTypeResolver::resolveForNewPassword()` — login fields unchanged; new-password flows prefer `PasswordStrengthType` when configured and installed.

### Changed

- Documentation: password strength section in [CONFIGURATION.md](CONFIGURATION.md); upgrade notes in [UPGRADING.md](UPGRADING.md); Flex recipe comment in `nowo_auth_kit.yaml`.
- `composer.json`: suggest and require-dev entry for `nowo-tech/password-strength-bundle`.

[1.3.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-07-03

### Added

- **`remember_me` configuration** (`enabled`, `lifetime`, `path`): optional persistent login cookie aligned with the login checkbox and Symfony firewall.
- `AuthKitFormLoginParameters`: central mapping of nested `form_login` / `remember_me` parameter names.
- `RememberMeConfigResolver`: resolves remember-me enablement from bundle config and login fields.
- Integration smoke test for bundle extension loading.
- Unit tests for remember-me config resolution and security command behaviour.

### Changed

- `nowo:auth-kit:configure-security` writes nested `form_login` parameters (`login_form[_username]`, etc.) and `invalidate_session: true` on logout.
- `remember_me` firewall block is **synced on every** `configure-security` run (independent of the `--force` guard on `form_login`).
- Demo `security.yaml` files updated with nested login parameters.
- Documentation: remember-me section in [CONFIGURATION.md](CONFIGURATION.md); installation snippet in [INSTALLATION.md](INSTALLATION.md); upgrade guide in [UPGRADING.md](UPGRADING.md).

### Fixed

- Disabling remember-me in bundle config now removes a stale `remember_me` block from `security.yaml` without requiring `--force`.

[1.2.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.2.0

## [1.1.1] - 2026-06-18

### Added

- Demo screenshots in the main [README](../README.md) (`docs/assets/`: welcome, login, embed dropdown).

### Changed

- CI: bump `actions/checkout` to v6 and `actions/github-script` to v9.

### Fixed

- `composer.json` `homepage` and `support` URLs now point to the GitHub repository `nowo-tech/AuthKitBundle`.

[1.1.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.1.1

## [1.1.0] - 2026-06-18

### Added

- **Password reset** flow: request, link/code/both delivery, completion routes, and Twig templates.
- `PasswordResetNotifierInterface` for app-specific delivery (email, SMS, logging, etc.).
- `PasswordResetRequestedEvent` for audit, rate limiting, or webhooks without a full notifier.
- Configurable `password_reset.*` (mode, delivery, TTL, token/code settings, entity field names).
- **Embedded auth UI** via `auth_kit_dropdown()` Twig function (`embed.mode: dropdown`).
- Configurable embed templates for login, register, and authenticated states.
- **`locale_in_path`**: prefix login, register, logout, and password-reset routes with `/{_locale}`.
- Twig helper `auth_kit_route_params()` for locale-aware Auth Kit links.
- `AuthKitUrlGenerator` and `AuthKitRouteLocaleParameters` for internal URL generation.
- Password-reset and embed translation keys in `NowoAuthKitBundle` (`en`, `es`).
- Documentation: [PASSWORD-RESET.md](PASSWORD-RESET.md); [USAGE.md](USAGE.md) sections for embed and locale paths.
- Demo welcome page, embed dropdown in navbar, password reset wiring, and locale-prefixed URLs (`/en/login`, etc.).

### Changed

- `nowo:auth-kit:configure-security` adds `access_control` for password-reset routes when enabled and locale-aware patterns when `locale_in_path` is true.
- Form types use explicit `#[Autowire]` for configuration parameters (fixes autowiring in consuming apps).
- Demos: FrankenPHP images include the `intl` PHP extension; MySQL 8.0 with explicit `serverVersion`; Symfony 8.1 profiler option cleanup.

### Fixed

- Demo redirect loop at `/` when `locale_in_path` is enabled (default locale must not collapse `app_welcome` to `/`).
- Demo `.env` PostgreSQL DSN leftover conflicting with MySQL configuration.

[1.1.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.1.0

## [1.0.0] - 2026-06-18

### Added

- Configurable **login** and **registration** flows integrated with Symfony `form_login`.
- Registration modes: `disabled`, `first_user_only`, `always`.
- Configurable `user_class`, `user_identifier_field`, `login_fields`, `registration_fields`, `registration_role`, routes, and Twig templates.
- Translation domain `NowoAuthKitBundle` with English and Spanish catalogues.
- Twig overrides via `templates/bundles/NowoAuthKitBundle/` or `nowo_auth_kit.templates.*`.
- CLI helper `nowo:auth-kit:configure-security` to merge `security.yaml` snippets.
- Symfony Flex recipe (`nowo-tech/auth-kit-bundle`) with `nowo_auth_kit.yaml`, routes, `nowo_password_toggle.yaml`, `ux_icons.yaml`, and locked Tabler icon assets.
- **`PasswordFieldTypeResolver`**: uses `Nowo\PasswordToggleBundle\Form\Type\PasswordType` when installed, otherwise Symfony core `PasswordType`.
- **Suggested** dependencies: `nowo-tech/password-toggle-bundle`, `symfony/ux-icons`, `symfony/http-client` (installed by default through the Flex recipe).
- FrankenPHP demos for **Symfony 7.4** (`:8009`) and **Symfony 8.1** (`:8010`) with Docker Compose.
- Demo **Bootstrap 5** template overrides, combined form theme (`bootstrap_5_layout` + password toggle widget), and **en/es** locale switcher.
- PHPUnit suite with **100%** line coverage requirement.

### Changed

- N/A (initial public release).

### Fixed

- N/A (initial public release).

### Removed

- N/A (initial public release).

[1.0.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.0.0
