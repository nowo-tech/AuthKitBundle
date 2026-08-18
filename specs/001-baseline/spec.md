# Feature Specification: AuthKitBundle baseline (100% product coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  
**Package**: `nowo-tech/auth-kit-bundle` (tag **v1.17.0+**)  
**Configuration root**: `nowo_auth_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md) — **137** units (113 PHP + 24 Resources), audited **2026-08-15**

---

## Summary

Drop-in Symfony **authentication kit**: login/logout, gated registration, remember-me, password reset (email/code), magic login (request/confirm/check), **QR login** (desktop↔mobile challenge), **OAuth2/OIDC social login** (including enterprise SSO via `enterpriseSso` credentials), embeddable auth chrome, locale-aware routes, outbound-mail readiness gate, attempt limiting hooks, and `ConfigureSecurityCommand` to scaffold firewall YAML.

---

## User Scenarios

### US-01 — Login & logout (P1)

**Given** `form_login` firewall referencing bundle routes, **When** user submits `LoginFormType`, **Then** Symfony Security authenticates using the configured identifier field and optional remember-me.

### US-02 — Registration modes (P1)

**Given** `registration_mode` (`disabled`, `first_user_only`, `always`), **When** register route is hit, **Then** `RegistrationGate` allows or denies and `UserRegistrar` persists the user with `registration_role`.

### US-03 — Password reset (P1)

**Given** reset enabled, **When** user requests reset, **Then** `PasswordResetRequestHandler` issues a token via `PasswordResetTokenManager` and notifies through `PasswordResetNotifierInterface` (null/logging samples).

### US-04 — Magic login (P1)

**Given** magic login enabled, **When** user requests a link and later confirms/checks it, **Then** `MagicLoginRequestHandler` + confirm/check controllers complete passwordless session establishment. Logging notifier MUST NOT log URLs or tokens (REQ-OBS-001).

### US-05 — QR login (P1)

**Given** `qr_login.mode` enabled, **When** desktop starts a challenge and mobile approves/denies, **Then** `QrLoginChallengeManager` + approve/complete/deny/status controllers bind the desktop session without sharing passwords. Rate limits apply via `QrLoginRateLimiter`.

### US-06 — Social / enterprise SSO (P1)

**Given** `social_login.mode: enabled` and stored `SocialLoginCredential` rows, **When** user hits start/check for a provider, **Then** `OAuth2Client` + `SocialAccountLinker` authenticate/link the local user. Credentials with `enterpriseSso: true` render under organization SSO (see `docs/SSO.md`).

### US-07 — Auth embed (P2)

**Given** embed mode dropdown/panel, **When** Twig renders `auth_embed()`, **Then** `AuthEmbedContextFactory` builds login/register partials for host chrome.

### US-08 — Security scaffolding (P2)

**Given** a new integrator, **When** `nowo:auth-kit:configure-security` runs, **Then** documented firewall and `access_control` snippets are emitted.

### US-09 — Outbound mail gate (P2)

**Given** flows that send email (reset / magic), **When** mail is not ready, **Then** `OutboundMailReadyCheckerInterface` (default `AlwaysOutboundMailReadyChecker`) gates sending so hosts can block until Mailer DSN is configured.

---

## Requirements

### Bundle & config

- **FR-BUNDLE-001**: `NowoAuthKitBundle` alias `nowo_auth_kit`.
- **FR-CFG-001**: `Configuration` — `user_class`, identifier field, registration mode/role, login fields, remember_me, password reset, magic login, QR login, social login, embed, routes, templates, profiles.
- **FR-CFG-002**: `NowoAuthKitExtension` + `FieldConfigNormalizer`, `RememberMeConfigResolver`; `TwigPathsPass` registers bundle Twig paths.

### HTTP controllers

- **FR-CTRL-001**: Login, logout, register controllers.
- **FR-CTRL-002**: Password reset request, code entry, completion controllers.

### Forms & validation

- **FR-FORM-001**: Login, registration, reset form types.
- **FR-FORM-002**: `PasswordFieldConstraintResolver`, `PasswordFieldTypeResolver`, `PasswordRepeatedFieldBuilder`.

### Password reset subsystem

- **FR-RESET-001**: Token manager (+ interface) + user resolver + gate + completer + result DTO.
- **FR-RESET-002**: Notifier interface with logging/null implementations; `PasswordResetRequestedEvent` + notification context.
- **FR-RESET-003**: Delivery modes and enums (`PasswordResetMode`, `PasswordResetDeliveryMode`).

### Magic login subsystem

- **FR-MAGIC-001**: Gate, request handler, user resolver, notifiers (null/logging), notification context, event, controllers/forms for request/**confirm**/check (`MagicLoginConfirmController` / `MagicLoginConfirmType`). Logging sample must not log magic URLs or tokens (REQ-OBS-001).

### QR login subsystem

- **FR-QR-001**: Gate, challenge manager, user resolver, rate limiter, QR code generators (Endroid + null), step-up interface/null, entities/repos, compiler pass, enums, and controllers (start/show/status/approve/deny/complete) + approve Twig.
- **FR-QR-002**: Domain events — challenge created, approved, denied, completed.

### Social / enterprise SSO

- **FR-SOCIAL-001**: `SocialLoginGate`, `OAuth2Client`, endpoint catalog + URL validator, state store, account linker, profile DTO, credential/account entities + repos, start/check controllers, `SocialLoginMode` enum. Enterprise SSO reuses the same pipeline with `enterpriseSso` on credentials (no separate SAML stack).

### Profiles & locale

- **FR-PROFILE-001**: `ProfileSettings`, `ProfileRegistry`, `RequestProfileResolver`, `UnknownProfileException`.
- **FR-LOCALE-001**: Locale-in-path / unlocalized redirect modes and `UnlocalizedLocaleRedirectController`.

### Security integration

- **FR-SEC-001**: `RegistrationGate`, `UserRegistrar`, `AuthKitFormLoginParameters`.
- **FR-SEC-002**: No ApiStudio-style access checker — registration gating only via `RegistrationGate`.
- **FR-SEC-003**: `AuthKitAttemptLimiter` for pre-auth HTTP throttling hooks (pairs with host LoginThrottle / REQ-THROTTLE patterns).

### Outbound mail gate

- **FR-MAIL-001**: `OutboundMailReadyCheckerInterface` + `AlwaysOutboundMailReadyChecker`.

### Routing & Twig

- **FR-ROUT-001**: `AuthKitRouteLoader`, locale parameters, custom URL generator.
- **FR-TWIG-001**: Routing + embed Twig extensions and security/embed templates.
- **FR-TWIG-002**: `AuthKitUiExtension` for shared UI helpers (buttons/providers chrome).

### Embed

- **FR-EMBED-001**: `AuthEmbedContext`, factory, `AuthEmbedMode` enum.
- **FR-EMBED-002**: `AuthEmbedOptions` typed DTO; factory/Twig accept `AuthEmbedOptions|array` (REQ-PHP-001).

### Enums

- **FR-ENUM-001**: Registration and shared enums not owned by a subsystem FR above.

### DI / i18n / assets

- **FR-DI-001**: `Resources/config/routing.yaml` + `services.yaml`.
- **FR-I18N-001**: `NowoAuthKitBundle.{de,en,es,fr,it,nl,pt}.yaml`.
- **FR-ASSET-001**: Public CSS under `Resources/public/`.

### CLI

- **FR-CLI-001**: `ConfigureSecurityCommand` outputs firewall recipe.

---

## Success Criteria

- **SC-001**: All production files in [`code-inventory.md`](code-inventory.md) mapped (**137** units as of 2026-08-15).
- **SC-002**: 100% PHPUnit line coverage on `src/` (project standard / `make test-coverage-100`).
- **SC-003**: Config keys match [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-004**: Product docs exist for each shipped surface: USAGE, CONFIGURATION, PASSWORD-RESET, MAGIC-LOGIN, QR-LOGIN, SOCIAL-LOGIN, SSO, SECURITY.

---

## Explicit non-goals

- **WebAuthn / passkeys** — design locked in [`docs/WEBAUTHN.md`](../../docs/WEBAUTHN.md); **not implemented** (no `src/` units yet).
- Native **SAML 2.0** — enterprise SSO is OIDC-via-social only; SAML is roadmap.
- Authorization voters beyond registration role assignment / host security.

---

## Validation

`make test-coverage-100`, PHPStan level 8, `make release-check`.
