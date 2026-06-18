# ENGRAM

Project memory for AI assistants working on **Auth Kit Bundle**.

## Identity

- Package: `nowo-tech/auth-kit-bundle`
- Namespace: `Nowo\AuthKitBundle`
- DI alias: `nowo_auth_kit`
- Translation domain: `NowoAuthKitBundle`
- Twig namespace: `NowoAuthKitBundle`

## Architecture

- Login/register controllers + forms; Symfony Security owns authentication (`form_login`).
- `RegistrationGate` enforces `registration_mode`.
- `UserRegistrar` persists users with configurable role and field mapping.
- Routes loaded via `AuthKitRouteLoader` (`type: nowo_auth_kit`).
- Twig overrides: `templates/bundles/NowoAuthKitBundle/`.
- Optional CLI: `nowo:auth-kit:configure-security`.

## Demo

- `demo/symfony8` — port `8010`, `make -C demo up-symfony8`
- Bundle mounted at `/var/auth-kit-bundle` in demo container.

## Standards

Follow `BUNDLES_FULL_SPECS_DETAILS.md` in the parent `bundles` repo.
