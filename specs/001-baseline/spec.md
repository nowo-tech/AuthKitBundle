# Feature Specification: AuthKitBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  

**Package**: `nowo-tech/auth-kit-bundle`  
**Configuration root**: `nowo_auth_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Drop-in Symfony **authentication UI**: login, registration (gated modes), optional **remember-me**, **password reset** (email/code flows), embeddable auth panels, locale-aware routes, and `ConfigureSecurityCommand` to scaffold firewall YAML.

---

## User Scenarios

### US-01 — Login & logout (P1)

**Given** `form_login` firewall referencing bundle routes, **When** user submits `LoginFormType`, **Then** Symfony Security authenticates using configured identifier field.

### US-02 — Registration modes (P1)

**Given** `registration_mode` (`disabled`, `first_user_only`, `always`), **When** register route is hit, **Then** `RegistrationGate` allows or denies access and `UserRegistrar` persists the user with `registration_role`.

### US-03 — Password reset (P1)

**Given** reset enabled in config, **When** user requests reset, **Then** `PasswordResetRequestHandler` issues token via `PasswordResetTokenManager` and notifies through `PasswordResetNotifierInterface`.

### US-04 — Auth embed (P2)

**Given** embed mode dropdown/panel, **When** Twig renders `auth_embed()`, **Then** `AuthEmbedContextFactory` builds login/register partials for header integration.

### US-05 — Security scaffolding (P2)

**Given** new integrator, **When** `nowo:auth-kit:configure-security` runs, **Then** documented firewall and access_control snippets are emitted.

---

## Requirements

### Bundle & config

- **FR-BUNDLE-001**: `NowoAuthKitBundle` alias `nowo_auth_kit`.
- **FR-CFG-001**: `Configuration` — `user_class`, identifier field, registration mode/role, login fields, remember_me, password reset trees, embed, routes, templates.
- **FR-CFG-002**: `NowoAuthKitExtension` + `FieldConfigNormalizer`, `RememberMeConfigResolver`.

### HTTP controllers

- **FR-CTRL-001**: Login, logout, register controllers.
- **FR-CTRL-002**: Password reset request, code entry, completion controllers.

### Forms & validation

- **FR-FORM-001**: Login, registration, reset form types.
- **FR-FORM-002**: `PasswordFieldConstraintResolver`, `PasswordFieldTypeResolver`, `PasswordRepeatedFieldBuilder`.

### Password reset subsystem

- **FR-RESET-001**: Token manager + user resolver + gate + completer.
- **FR-RESET-002**: Notifier interface with logging/null implementations; `PasswordResetRequestedEvent`.
- **FR-RESET-003**: Delivery modes and enums (`PasswordResetMode`, `PasswordResetDeliveryMode`).

### Security integration

- **FR-SEC-001**: `RegistrationGate`, `UserRegistrar`, `AuthKitFormLoginParameters`.
- **FR-SEC-002**: `ApiStudioAccessChecker`-style access is N/A — use `RegistrationGate` only.

### Routing & Twig

- **FR-ROUT-001**: `AuthKitRouteLoader`, locale parameters, custom URL generator.
- **FR-TWIG-001**: Routing + embed extensions; security and embed Twig templates.

### Embed

- **FR-EMBED-001**: `AuthEmbedContext`, factory, `AuthEmbedMode` enum.

### CLI

- **FR-CLI-001**: `ConfigureSecurityCommand` outputs firewall recipe.

---

## Success Criteria

- **SC-001**: **66/66** files mapped.
- **SC-002**: 100% PHPUnit line coverage on `src/` (project standard).
- **SC-003**: Config keys match [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).

---

## Explicit non-goals

- OAuth2/OIDC social login (integrator-owned).
- Authorization voters beyond registration role assignment.

---

## Validation

`make test-coverage-100`, PHPStan level 8, `make release-check`.
