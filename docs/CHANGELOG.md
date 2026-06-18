# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
