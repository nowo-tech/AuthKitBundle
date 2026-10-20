# Feature Specification: AuthKitBundle baseline (100% product coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  
**Package**: `nowo-tech/auth-kit-bundle` (tag **v1.23.0+**)  
**Configuration root**: `nowo_auth_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md) — **165** units (137 PHP + 28 Resources), audited **2026-10-13**

---

## Summary

Drop-in Symfony **authentication kit**: login/logout, gated registration, remember-me, password reset (email/code), magic login (request/confirm/check), **QR login** (desktop↔mobile challenge), **OAuth2/OIDC social login** (including enterprise SSO via `enterpriseSso` credentials), optional **slide-to-confirm** on registration consent and QR approve, optional **device intelligence** (collect on auth pages, new-device notify, device-keyed rate limits, QR trusted-device step-up), optional **OTP input** on password-reset code entry, embeddable auth chrome, locale-aware routes, outbound-mail readiness gate, attempt limiting hooks, and `ConfigureSecurityCommand` to scaffold firewall YAML.

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

### US-10 — Optional slide-to-confirm (P2)

**Given** `nowo-tech/slide-to-confirm-bundle` is optional (`suggest` / `require-dev` only), **When** `slide_to_confirm.enabled` is true and a registration field or QR approve requests a profile, **Then** AuthKit uses `SlideToConfirmType` / `SwipeToSubmitType` with Form CSRF. **When** the package is missing or `enabled` is false, **Then** checkbox / plain QR submit remain. Login forms MUST stay unchanged. The gesture is confirmation UX, not authorization.

### US-11 — Optional device intelligence (P2)

**Given** `nowo-tech/device-intelligence-bundle` is optional (`suggest` only; **not** `require-dev` because that package requires PHP 8.3+), **When** `device_intelligence.enabled` is true and the package is installed, **Then** AuthKit may load collect JS on auth layouts, notify on new-device `LoginSuccess`, extra-limit by device ULID, and require a trusted device on QR approve. **When** the package is missing or `enabled` is false, **Then** AuthKit behaviour is unchanged. Device ID MUST NOT be a credential. Login forms, LoginThrottle, CSRF, and remember-me MUST stay unchanged. AuthKit MUST NOT auto-`trust()` a device after login. Missing observation on `device_rate_limit` MUST be a no-op.

### US-12 — Optional OTP input (P2)

**Given** `nowo-tech/otp-input-bundle` is optional (`suggest` / `require-dev` only), **When** `otp_input.enabled` is true, `otp_input.password_reset_code` is true, and the package is installed, **Then** AuthKit uses `OtpType` on the password-reset code field (`length` / charset from `password_reset`) and loads OTP Input JS on auth layouts. **When** the package is missing or `enabled` is false, **Then** the existing `TextType` remains. Login forms MUST stay unchanged. The widget is UX only; server OTP checks MUST stay mandatory.

### US-13 — Translated flash messages (P1, v1.23.0)

**Given** a host renders flashes with `{{ message }}` / `{{ message|trans }}` / UiKitBundle's toast partial, **When** an AuthKit controller adds a flash, **Then** (with `flash.translate: true`, default) the stored flash is the text translated from `NowoAuthKitBundle` in the request locale — never a raw key. **When** `flash.translate: false`, **Then** the raw key is stored (pre-1.23). **Given** host controllers flash keys from several domains, **When** a template uses `|flash_trans`, **Then** the first domain of `flash.twig_filter_domains` whose catalogue has the key is used, otherwise `messages`; non-string flashes pass through.

### US-14 — Recipient-locale notifications (P1, v1.23.0)

**Given** the user implements `PreferredLocaleAwareInterface` (or `recipient_locale.resolver` returns a locale) and that locale is in `locale.enabled`, **When** a password reset, magic link or new-device notice is prepared, **Then** the reset / magic link uses that `{_locale}` (localized routes only), the notification context carries `recipientLocale`, and the notifier + request event run inside `LocaleSwitcher::runWithLocale()`; the request locale is restored afterwards, also on exceptions. **When** the user has no allowed preference or `recipient_locale.enabled: false`, **Then** behaviour equals 1.22 (`recipientLocale` null, request locale).

### US-15 — Admin provisioning CLI (P1, v1.23.0)

**Given** public registration is disabled, **When** an operator runs `nowo:auth-kit:create-admin <identifier> [--auth-profile=NAME]` interactively, **Then** a user is created for the profile with `create_admin.roles` after a hidden, repeated password prompt that satisfies `create_admin.min_password_length` and the profile password-strength policy (when enabled and installed), and `AdminUserCreatingEvent` / `AdminUserCreatedEvent` are dispatched. The command MUST refuse non-interactive runs, MUST NOT accept the password as option/argument nor fall back to visible input, and MUST reject an existing identifier.

---

## Requirements

### Bundle & config

- **FR-BUNDLE-001**: `NowoAuthKitBundle` alias `nowo_auth_kit`.
- **FR-CFG-001**: `Configuration` — `user_class`, identifier field, registration mode/role, login fields, remember_me, password reset, magic login, QR login, social login, embed, routes, templates, profiles, optional `slide_to_confirm`, optional `device_intelligence`, optional `otp_input`. `beforeNormalization` only maps keys present in each block (legacy flat keys → `profiles.default`, legacy locale keys → `locale`); `default_profile` and legacy locale mirrors are derived in `validate()` after the multi-block merge, so a partial env block never resets `locale` / `default_profile` (v1.23.1).
- **FR-CFG-002**: `NowoAuthKitExtension` + `FieldConfigNormalizer`, `RememberMeConfigResolver`; `TwigPathsPass` registers bundle Twig paths.

### HTTP controllers

- **FR-CTRL-001**: Login, logout, register controllers.
- **FR-CTRL-002**: Password reset request, code entry, completion controllers.

### Forms & validation

- **FR-FORM-001**: Login, registration, reset form types.
- **FR-FORM-002**: `PasswordFieldConstraintResolver`, `PasswordFieldTypeResolver`, `PasswordRepeatedFieldBuilder`.
- **FR-FORM-003**: Registration field `mapped` / `slide_to_confirm`; `UserRegistrar` MUST skip `mapped: false`.

### Password reset subsystem

- **FR-RESET-001**: Token manager (+ interface) + user resolver + gate + completer + result DTO.
- **FR-RESET-002**: Notifier interface with logging/null implementations; `PasswordResetRequestedEvent` + notification context.
- **FR-RESET-003**: Delivery modes and enums (`PasswordResetMode`, `PasswordResetDeliveryMode`).

### Magic login subsystem

- **FR-MAGIC-001**: Gate, request handler, user resolver, notifiers (null/logging), notification context, event, controllers/forms for request/**confirm**/check (`MagicLoginConfirmController` / `MagicLoginConfirmType`). Logging sample must not log magic URLs or tokens (REQ-OBS-001).

### QR login subsystem

- **FR-QR-001**: Gate, challenge manager, user resolver, rate limiter, QR code generators (Endroid + null), step-up interface/null, entities/repos, compiler pass, enums, and controllers (start/show/status/approve/deny/complete) + approve Twig + optional `QrLoginApproveType` + `QrLoginDenyType` (field-less POST deny form, no raw `<form>`). Expiry, cookie lifetime and remaining seconds (`expiresIn()`) use the optional injected `Psr\Clock\ClockInterface`.
- **FR-QR-002**: Domain events — challenge created, approved, denied, completed.
- **FR-QR-003**: `Resources/public/js/nowo-auth-kit-qr-login.js` — CSP-safe progressive-enhancement poller of the desktop challenge page (status polling, countdown, state attribute); `QrLoginRetryType` is the field-less GET retry form of that page.

### Social / enterprise SSO

- **FR-SOCIAL-001**: `SocialLoginGate`, `OAuth2Client`, endpoint catalog + URL validator, state store, account linker, profile DTO, credential/account entities + repos, start/check controllers, `SocialLoginMode` enum. Enterprise SSO reuses the same pipeline with `enterpriseSso` on credentials (no separate SAML stack). Authorization-code requests use PKCE S256 (`Pkce`). Token / userinfo calls are bounded by `http_client.timeout` / `http_client.max_duration` (REQ-RUNTIME-001).

### Profiles & locale

- **FR-PROFILE-001**: `ProfileSettings`, `ProfileRegistry`, `RequestProfileResolver`, `UnknownProfileException`.
- **FR-LOCALE-001**: Locale-in-path / unlocalized redirect modes and `UnlocalizedLocaleRedirectController`. When `unlocalized: serve`, `UnlocalizedDefaultLocaleSubscriber` forces `locale.default` at runtime on `*_unlocalized` AuthKit routes (covers warmed route-cache vs runtime env drift).

### Security integration

- **FR-SEC-001**: `RegistrationGate`, `UserRegistrar`, `AuthKitFormLoginParameters`.
- **FR-SEC-002**: No ApiStudio-style access checker — registration gating only via `RegistrationGate`.
- **FR-SEC-003**: `AuthKitAttemptLimiter` for pre-auth HTTP throttling hooks (pairs with host LoginThrottle / REQ-THROTTLE patterns).
- **FR-SEC-004**: `LoginThrottleRequiredPass` fails container compilation when `login_throttle_required` is true and LoginThrottleBundle is not registered.

### Outbound mail gate

- **FR-MAIL-001**: `OutboundMailReadyCheckerInterface` + `AlwaysOutboundMailReadyChecker`.

### Routing & Twig

- **FR-ROUT-001**: `AuthKitRouteLoader`, locale parameters, custom URL generator.
- **FR-ROUT-002**: `AbsoluteUrlGenerator` — optional `absolute_url.base_uri` origin for out-of-band absolute URLs (reset / magic / QR / OAuth `redirect_uri`); router context restored in `finally` (v1.24.0, [spec 002](../002-absolute-url-base-uri/spec.md)). `PublicUrlGenerator` (service `nowo_auth_kit.public_url_generator`) exposes the same origin to host code building out-of-band links, never trusting the request `Host`.
- **FR-TWIG-001**: Routing + embed Twig extensions and security/embed templates.
- **FR-TWIG-002**: `AuthKitUiExtension` for shared UI helpers (buttons/providers chrome).
- **FR-TWIG-003**: CSP — inline `<script>`/`<style>` in bundle templates emit `nonce` from request attribute `csp_nonce` when set; no inline event handlers (enforced by `InlineBlocksDeclareNonceTest`).

### Embed

- **FR-EMBED-001**: `AuthEmbedContext`, factory, `AuthEmbedMode` enum.
- **FR-EMBED-002**: `AuthEmbedOptions` typed DTO; factory/Twig accept `AuthEmbedOptions|array` (REQ-PHP-001).

### Enums

- **FR-ENUM-001**: Registration and shared enums not owned by a subsystem FR above.

### DI / i18n / assets

- **FR-DI-001**: `Resources/config/routing.yaml` + `services.yaml`.
- **FR-ORM-001**: `Doctrine\TablePrefixListener` prepends `doctrine.table_prefix` to AuthKit entity tables and their named indexes / unique constraints only; registered only when the prefix is not empty (REQ-ORM-002).
- **FR-I18N-001**: `NowoAuthKitBundle.{de,en,es,fr,it,nl,pt}.yaml`.
- **FR-I18N-002**: `validators.{de,en,es,fr,it,nl,pt}.yaml` — form constraint messages in the Symfony `validators` domain (kept in sync with the bundle domain).
- **FR-ASSET-001**: Public CSS under `Resources/public/`.

### UiKit page contract

- **FR-UI-001**: `View\AuthPageFactory` builds the UiKit `AuthPage` view models (REQ-UI-003) — translation-key labels, per-profile layout override, bundle name for theme exceptions; `security/_auth_page.html.twig` extends the UiKit auth scaffold; `_auth_kit_styles.html.twig` / `_auth_kit_scripts.html.twig` feed `nowo_ui_styles` / `nowo_ui_scripts` (CSP nonce on every tag); `form/password_toggle_widget.html.twig` maps the optional PasswordToggle widget onto the UiKit theme slots.

### Optional slide-to-confirm

- **FR-SLIDE-001**: `SlideToConfirmTypeResolver` (`class_exists` fallback); profile `slide_to_confirm.*`; Twig partials `_slide_to_confirm_assets.html.twig` and `_registration_submit.html.twig`; assets loaded only when enabled **and** the package is present. Default `enabled: false`. Swipe is UX, not authorization.

### Optional device intelligence

- **FR-DIINTEL-001**: Duck-typed bridge (`DeviceIntelligenceContext`, `class_exists` / injectable `Closure`); never imports DeviceIntelligence classes so AuthKit stays PHP 8.2. Profile `device_intelligence.*` default `enabled: false`. Assets `_device_intelligence_assets.html.twig` only when enabled **and** the package is present. `NewDeviceLoginSubscriber` + `NewDeviceLoginNotifierInterface` (default null) on `isNew()` after `LoginSuccess` — session flag only, no auto-trust. Extra limiter consume by device ULID is a no-op without observation. `DeviceIntelligenceQrLoginStepUp` decorates `QrLoginStepUpInterface` for opt-in trusted-device QR approve; `NullQrLoginStepUp` is skipped when that check passes, a custom inner still runs. Device ID is not a credential.

### Optional OTP input

- **FR-OTP-001**: `OtpInputTypeResolver` (`class_exists` fallback); profile `otp_input.*` default `enabled: false`; `ResetPasswordCodeFormType` uses `OtpType` when enabled, `password_reset_code` is true, and the package is present. Twig partial `_otp_input_assets.html.twig` loads JS only when enabled **and** the package is present. Widget is UX, not authorization. Login forms MUST stay unchanged.

### CLI

- **FR-CLI-001**: `ConfigureSecurityCommand` outputs firewall recipe.
- **FR-CLI-002**: `CreateAdminCommand` (`nowo:auth-kit:create-admin`) — US-15; config `create_admin.min_password_length` (default 12, min 8), `create_admin.roles` (default `[ROLE_ADMIN]`).

### Admin provisioning

- **FR-ADMIN-001**: `AdminUserCreator` (profile-aware, independent of `registration_mode`, unique-violation → `RuntimeException`, worker-safe `EntityManagerRecovery`) + `AdminUserCreatingEvent` (pre-persist) + `AdminUserCreatedEvent` (post-flush).

### Recipient locale

- **FR-RLOCALE-001**: `PreferredLocaleAwareInterface`, `RecipientLocaleResolverInterface` (default `PreferredLocaleAwareRecipientLocaleResolver`, override `recipient_locale.resolver`), `RecipientLocale::resolve()` filtered by `locale.enabled` and `recipient_locale.enabled` (default true).
- **FR-RLOCALE-002**: `PasswordResetRequestHandler`, `MagicLoginRequestHandler`, `NewDeviceLoginSubscriber` set `recipientLocale` on their notification contexts, generate links in that locale (`AuthKitUrlGenerator` explicit `$locale`; request copy for `LoginLinkHandler`), and run event + notifier through `RecipientLocale::run()` (`LocaleSwitcher`, restored in `finally`).

### Flash messages

- **FR-FLASH-001**: `AuthKitFlashMessenger` used by every AuthKit controller flash; `flash.translate` (default true) stores translated text.
- **FR-FLASH-002**: `AuthKitFlashExtension` — `flash_trans` filter, `flash.twig_filter` (default true), `flash.twig_filter_domains` (default `[messages, NowoAuthKitBundle]`), catalogue lookup without request state.

---

## Success Criteria

- **SC-001**: All production files in [`code-inventory.md`](code-inventory.md) mapped (**183/183** units under `src/` as of 2026-10-20: 143 PHP + 40 Resources).
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
