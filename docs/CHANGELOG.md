# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
[1.0.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.0.0

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
