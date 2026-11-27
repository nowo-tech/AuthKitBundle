# Feature Specification: AuthKitBundle baseline (100% product coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  
**Package**: `nowo-tech/auth-kit-bundle` (tag **v1.30.0+**)  
**Configuration root**: `nowo_auth_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md) — **463** units (392 PHP + 71 Resources), audited **2026-11-17**

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

### US-06b — OpenID Connect providers (P1, v2.3.0)

**Given** a credential for a generic issuer, `gitlab` (gitlab.com or self-hosted), `apple`, `linkedin` or `slack`, **when** the visitor signs in, **then** the bundle discovers the provider, verifies the ID token (signature from the issuer JWKS, alg allow-list, iss, aud/azp, exp/iat/nbf, nonce) and links / signs in with the existing rules; any failed check, private-address endpoint or forged token signs nobody in and shows `social.error.failed`. **Given** Apple's cross-site form_post callback, **then** it is relayed to a same-site POST without touching the session, and the first-sign-in name becomes the display name.

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
- **FR-CFG-002**: `NowoAuthKitExtension` + `FieldConfigNormalizer`, `RememberMeConfigResolver`; `TwigPathsPass` registers bundle Twig paths. `doctrine.register_mapping` (`true` | `false` | `auto`) leaves the `auth_kit_*` entities unmapped when social and QR login are disabled (v1.30.0).

### HTTP controllers

- **FR-CTRL-001**: Login, logout, register controllers. `routes.logout.methods` (default `[GET]`; `[POST]` = CSRF-protected sign-out with the firewall `logout.enable_csrf`, the embed widget then renders `LogoutFormType`).
- **FR-CTRL-003**: Every action declares `#[Route]` (default path and name = `routes.<key>` defaults, HTTP methods, requirements); `AuthKitRouteLoader` copies it per profile and the controller services are registered with `autoconfigure: false` (explicit `controller.service_arguments` tag, no `routing.controller`) so a host `routing.controllers` import never registers un-profiled duplicates (REQ-SF-004). `UnlocalizedLocaleRedirectController` has no route of its own (it serves the bare twins).
- **FR-CTRL-002**: Password reset request, code entry, completion controllers.

### Forms & validation

- **FR-FORM-001**: Login, registration, reset form types.
- **FR-FORM-002**: `PasswordFieldConstraintResolver`, `PasswordFieldTypeResolver`, `PasswordRepeatedFieldBuilder`.
- **FR-FORM-003**: Registration field `mapped` / `slide_to_confirm`; `UserRegistrar` MUST skip `mapped: false`.
- **FR-FORM-004**: `LogoutFormType` — unnamed, field-less POST sign-out form of the embed widget (hidden firewall `csrf_parameter`, token id `logout`; own CSRF off) when `routes.logout.methods` excludes GET (REQ-TWIG-005).
- **FR-FORM-005**: `Form\Extension\ConstraintMessageTranslationExtension` — after validation of the root AuthKit form (registration, reset request/password/code, magic-login request), rebuilds the AuthKit constraint errors from their message template in the form translation domain (`NowoAuthKitBundle`); a key defined in the application `validators` catalogue keeps the validator translation (BC); other errors untouched (REQ-I18N-003).

### Password reset subsystem

- **FR-RESET-001**: Token manager (+ interface) + user resolver + gate + completer + result DTO.
- **FR-RESET-002**: Notifier interface with logging/null implementations; `PasswordResetRequestedEvent` + notification context.
- **FR-RESET-003**: Delivery modes and enums (`PasswordResetMode`, `PasswordResetDeliveryMode`).
- **FR-RESET-004**: Legacy-host options (v1.30.0): `password_reset.after_request_route` + `PasswordResetCheckEmail` session payload (profile, `tokenTtl`, `throttled`, `availableAt`); `password_reset.token_in_session` (token-less reset URL); `PasswordResetThrottledException` thrown by a custom token manager → `PasswordResetRequestHandler::handle()` returns `PasswordResetRequestOutcome` and sends nothing, without revealing it on the request page (no enumeration).

### Magic login subsystem

- **FR-MAGIC-001**: Gate, request handler, user resolver, notifiers (null/logging), notification context, event, controllers/forms for request/**confirm**/check (`MagicLoginConfirmController` / `MagicLoginConfirmType`). Logging sample must not log magic URLs or tokens (REQ-OBS-001). `MagicLinkFactory` builds the login link (recipient locale, `absolute_url.base_uri`) for the sign-in page and administrator-issued links.
- **FR-MAGIC-002**: `AdminMagicLinkIssuer` (v1.29.0) — support sign-in links an administrator issues for another account: `issue()` (show once), `send()` (through `MagicLoginNotifierInterface`, recipient locale), `canIssue()`; refused for the issuer's own account, protected roles or missing configuration (`AdminMagicLinkRefusedException` reason codes); `AdminMagicLinkIssuedEvent` for the host audit trail (link never included). Config `magic_login.admin_link.lifetime` / `protected_roles`.

### QR login subsystem

- **FR-QR-001**: Gate, challenge manager, user resolver, rate limiter, QR code generators (Endroid + null), step-up interface/null, entities/repos, compiler pass, enums, and controllers (start/show/status/approve/deny/complete) + approve Twig + optional `QrLoginApproveType` + `QrLoginDenyType` (field-less POST deny form, no raw `<form>`). Expiry, cookie lifetime and remaining seconds (`expiresIn()`) use the optional injected `Psr\Clock\ClockInterface`.
- **FR-QR-002**: Domain events — challenge created, approved, denied, completed.
- **FR-QR-003**: `Resources/public/js/nowo-auth-kit-qr-login.js` — CSP-safe progressive-enhancement poller of the desktop challenge page (status polling, countdown, state attribute); `QrLoginRetryType` is the field-less GET retry form of that page.

### Social / enterprise SSO

- **FR-SOCIAL-001**: `SocialLoginGate`, `OAuth2Client`, endpoint catalog + URL validator, state store, account linker, profile DTO, credential/account entities + repos, start/check controllers, `SocialLoginMode` enum. Enterprise SSO reuses the same pipeline with `enterpriseSso` on credentials (no separate SAML stack). Authorization-code requests use PKCE S256 (`Pkce`). Token / userinfo calls are bounded by `http_client.timeout` / `http_client.max_duration` (REQ-RUNTIME-001).
- **FR-SOCIAL-002** (2.3.0): OpenID Connect providers. `OidcProviderCatalog` decides per credential: presets `gitlab` (issuer `https://gitlab.com` or the credential's self-hosted base URL), `apple`, `linkedin`, `slack` (fixed issuers), any other key with `issuer` = generic provider; preset keys with their own OAuth2 URLs and keys without issuer stay on `OAuth2Client`. `OidcDiscovery` reads `<issuer>/.well-known/openid-configuration`, requires the document's `issuer` to be the configured one (trailing slash tolerated) and every endpoint to be a public HTTPS URL. Issuer and provider settings (`team_id`, `key_id`) are validated by `OidcCredentialRules` on write (entity setters) and on read.
- **FR-SOCIAL-003** (2.3.0): `OidcClient::begin()` issues state + nonce (unless the preset says the provider does not echo it: LinkedIn) + PKCE S256 (override, preset, else discovery `S256`), merges `authorization_params` under the reserved protocol parameters, adds `response_mode=form_post` for Apple. `authenticate()` exchanges the code (`client_secret_post` or `client_secret_basic` per preset / discovery), requires `access_token` and `id_token`, verifies the ID token, fetches userinfo when the provider has one (same `sub` required) and builds `SocialUserProfile` (e-mail and `email_verified` from one source; Apple's unsigned `user` field only for the display name). Account linking is `SocialAccountLinker`, unchanged.
- **FR-SOCIAL-004** (2.3.0): `IdTokenValidator`: compact JWS, `alg` on `oidc.allowed_algorithms` (RS*/ES*, never none/HS*), no `crit`, key only from the issuer JWKS and fitting kty/crv/use/alg/key_ops (RSA ≥ 2048, no private members, `JwkConverter`), `iss` exact, `aud`/`azp`, `exp`/`iat`/`nbf` with skew and max age, nonce in constant time, non-empty `sub`. Failures are `OidcException` (RuntimeException, `reason` code, no secret in the message) and take the social failure path.
- **FR-SOCIAL-005** (2.3.0): `OidcHttpClient` SSRF guard for every OIDC request: HTTPS, every resolved address public (`PublicIpChecker`, `HostResolverInterface`/`DnsHostResolver`), connection pinned to the checked address, `max_redirects: 0`, `http_client` deadlines, `oidc.max_response_bytes` enforced in `on_progress` and after download, JSON objects only.
- **FR-SOCIAL-006** (2.3.0): Discovery documents and JWKS in the `oidc.cache_pool` cache pool with TTLs; unknown `kid` refetches at most every `oidc.jwks_refresh_interval`; no service state (REQ-RUNTIME-002). Configuration `nowo_auth_kit.oidc` validated at build time (`OidcOptions`).
- **FR-SOCIAL-007** (2.3.0): `AppleClientSecret` signs the ES256 client-secret JWT (iss team, sub client id, aud Apple, 5 min) with the P-256 `.p8` key stored as the encrypted client secret; any other key is refused without echoing it.
- **FR-SOCIAL-008** (2.3.0): form_post callbacks (Apple): `SocialLoginCheckController` accepts POST; a POST without the relay marker (or with `Sec-Fetch-Site` other than same-origin) is answered by `SocialFormPostRelay` (template `security/social_form_post.html.twig`, `SocialFormPostRelayType`, `js/nowo-auth-kit-form-post.js`) without reading or writing the session, re-posting only `code`, `state`, `error`, `user`; a POST for a non-form_post provider goes to the login page.
- **FR-SOCIAL-009** (2.3.0, audit ZBP-L1): a link records its issuer (`SocialLoginAccount::issuer`, null for OAuth2 and pre-2.3 links); `SocialAccountLinker` refuses an existing link whose issuer differs from the profile's (`SocialUserProfile::issuer`). `SocialCredentialIssuerGuard` tells admin screens that the issuer is locked while accounts are linked.
- **FR-SOCIAL-010** (2.3.0, audit ZBP-L3): `OidcDiscovery` remembers a failed discovery per issuer for `oidc.discovery_failure_ttl`; `SocialLoginStartController` limits starts per client IP (`social_login.start_rate_limit` / `start_rate_window`, `AuthKitAttemptLimiter`).
- **FR-SOCIAL-011** (2.7.0, [spec 009](../009-enterprise-oidc/spec.md)): per-credential `listed` (unlisted credentials are no button and start by name), `login_hint` on the start route (`LoginHint`: valid e-mail ≤ 254 bytes, else dropped), `link_existing_account` per credential (`SocialCredentialPolicy`, read by `SocialAccountLinker` for every caller; the accept page skips `deny`), MFA first-factor policy per credential (`required` / `exempt` / `exempt_when_amr`, `amr` from the validated ID token only: `SocialUserProfile::$idTokenClaims`), fail closed on unknown stored values.
- **FR-SOCIAL-012** (2.7.0): test sign-in. `SocialLoginTestStart` stores a one-shot marker with the OAuth state; the callback of that state reaches only `SocialLoginTestHandlerInterface` (tag `nowo_auth_kit.social_test_handler`, `SocialLoginTestGate`), never signs in, links or provisions; no URL parameter starts or marks a test (`SocialLoginTestException` on refusal).
- **FR-SOCIAL-013** (2.8.0, [spec 010](../010-rp-logout/spec.md)): OpenID Connect RP-initiated logout, opt-in per credential (`rp_initiated_logout`). `OidcDiscovery` reads a safe `end_session_endpoint` (else null); the callback keeps a sealed hint in the session (`RpLogoutSession`); `RpInitiatedLogoutSubscriber` (`LogoutEvent`, priority 32) redirects to the endpoint with `id_token_hint`, `client_id` and an allow-listed `post_logout_redirect_uri` (`PostLogoutRedirectUri`, `social_login.rp_logout.post_logout_redirect_uris`); any doubt keeps the local sign-out, which always completes.
- **FR-SOCIAL-014** (2.8.0): enterprise client secrets fail closed. `EnterpriseSecretEncryptionGuard` (`onFlush`) refuses to write a credential with `enterprise_sso` and a client secret unless DoctrineEncrypt encrypts on that manager (`EnterpriseSecretNotEncryptedException`; `doctrine.require_enterprise_secret_encryption: false` for tests only).

### Profiles & locale

- **FR-PROFILE-001**: `ProfileSettings`, `ProfileRegistry`, `RequestProfileResolver`, `UnknownProfileException`.
- **FR-LOCALE-001**: Locale-in-path / unlocalized redirect modes and `UnlocalizedLocaleRedirectController`. When `unlocalized: serve`, `UnlocalizedDefaultLocaleSubscriber` forces `locale.default` at runtime on `*_unlocalized` AuthKit routes (covers warmed route-cache vs runtime env drift). Opt-in `locale.localized_logout_redirect` (1.32.2): with `both` + `serve`, `LocalizedLogoutRedirectSubscriber` redirects each profile's localized logout route to its bare twin (router-generated path, query kept, 302/307) so a firewall `logout.path` on the bare twin intercepts both; `nowo:auth-kit:configure-security` (FR-CLI-001) writes that bare twin for `both` + `serve`.

### Security integration

- **FR-SEC-001**: `RegistrationGate`, `UserRegistrar`, `AuthKitFormLoginParameters`.
- **FR-SEC-002**: No ApiStudio-style access checker — registration gating only via `RegistrationGate`.
- **FR-SEC-003**: `AuthKitAttemptLimiter` for pre-auth HTTP throttling hooks (pairs with host LoginThrottle / REQ-THROTTLE patterns).
- **FR-SEC-005**: SEC-004 2026-10-27 hardening (1.31): `UserIdentifierMatcher` (anonymous lookups accept only the account whose stored identifier equals the input after NFC + lower-casing; notification contexts carry the stored identifier and the `user`); `IdentifierConstraints` (plain ASCII e-mail on request forms); social links scoped to the user class + `create_user_if_missing` default false gated by `RegistrationGate` + `link_existing_account`; reset-code attempts keyed by account + per-IP `code_rate_limit`; `LoginLinkHandlerLocator` / `LoginLinkHandlerLocatorPass` (per-firewall login link handler); QR desktop cookie bound to the profile and approving class checked; enabled-locale whitelist in generated paths; `password_policy`; registration via `Security::login()`; `PasswordResetTokenInvalidationListener`; CSRF fails closed (`CsrfProtectionPass`, `FormCsrf`).
- **FR-SEC-004**: `LoginThrottleRequiredPass` fails container compilation when `login_throttle_required` is true and LoginThrottleBundle is not registered.

### Outbound mail gate

- **FR-MAIL-001**: `OutboundMailReadyCheckerInterface` + `AlwaysOutboundMailReadyChecker`.

### Routing & Twig

- **FR-ROUT-001**: `AuthKitRouteLoader` (attribute-driven per-profile copies, FR-CTRL-003), locale parameters, custom URL generator. `locale.unlocalized_route_suffix` (default `_unlocalized`) names the bare twins; `routing.skip_disabled_features` registers no route for disabled features (v1.30.0).
- **FR-ROUT-002**: `AbsoluteUrlGenerator` — optional `absolute_url.base_uri` origin for out-of-band absolute URLs (reset / magic / QR / OAuth `redirect_uri`); router context restored in `finally` (v1.24.0, [spec 002](../002-absolute-url-base-uri/spec.md)). `PublicUrlGenerator` (service `nowo_auth_kit.public_url_generator`) exposes the same origin to host code building out-of-band links, never trusting the request `Host`.
- **FR-INV-001 – FR-INV-010**: invitations instead of open registration (opt-in `invitations`, `registration_mode: invite_only`; v1.32, [spec 003](../003-invitations/spec.md)).
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
- **FR-I18N-002**: Single translation domain `NowoAuthKitBundle` (REQ-I18N-003): form constraint messages live in the bundle catalogues too (FR-FORM-005); no `validators.*` catalogue is shipped (removed after v1.30.0; application overrides in `validators.<locale>.yaml` keep working).
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

### Multi-factor authentication (v1.34, opt-in `mfa.enabled`)

- **FR-MFA-001**: `MfaManager` is the public API of the second factor (status, enrolment, code checks with rate limits, lockout and replay protection, disable, recovery codes, trusted devices). Value objects: `MfaStatus` (never carries the secret), `MfaCheck`, `MfaEnrolment` (the new secret lives only in the session), `MfaOwner` / `MfaOwnerResolver` (stable owner reference by Doctrine root class and id), `MfaFirstFactor`, `MfaRequirement`, `MfaLoginCompleter`, `MfaStore`, `MfaTrustedDevices`, `RecoveryCodes`, `MfaUnavailableException`. Policy SPIs: `MfaPolicyInterface` and the framework-free `MfaRequirementPolicyInterface`. `MfaConfiguration` and `Resources/config/mfa.yaml` register nothing while `enabled: false`.
- **FR-MFA-002**: Entities `MfaCredential` (TOTP secret encrypted at rest), `MfaRecoveryCode` (only an HMAC stored) and `MfaTrustedDevice` (HMAC of the device token); mapped only with `register_mapping: auto` and MFA enabled.
- **FR-MFA-003**: Controllers `MfaAccountController` (status, enrolment, disable, new recovery codes, trusted devices), `MfaChallengeController` (second step of a pending sign-in) and the optional `MfaAdminController` (administrator reset, `mfa.admin.routes: true`).
- **FR-MFA-004**: Audit events, none of them carrying a secret or a code: challenge failed / passed, enabled, disabled, locked out, login completed, recovery code used, recovery codes regenerated, reset by admin, trusted device added, trusted devices forgotten (base class `MfaEvent`).
- **FR-MFA-005**: Subscribers: `MfaAuthenticationSubscriber` (two-step sign-in per firewall), `MfaContainmentSubscriber` (keeps a half-authenticated browser inside the second step), `MfaPasswordChangeListener` (a changed password forgets trusted devices), `MfaSessionsSubscriber` (UserKit sessions integration). `MfaPendingToken` wraps the first factor's token and `MfaVoter` gates access.
- **FR-MFA-006**: `MfaLockRequiredPass` fails container compilation when MFA is enabled without a lock factory (fail closed).
- **FR-MFA-007**: `Totp` (RFC 6238) and `Base32` (RFC 4648) implement the authenticator-app format.
- **FR-MFA-008**: Twig `MfaExtension`, `MfaCodeFormType`, the `mfa/*` templates, `form/mfa_otp_widget.html.twig` and the progressive-enhancement script `nowo-auth-kit-otp.js`.

### Passkeys / WebAuthn (v2.4.0, opt-in `webauthn.mode: enabled`; [spec 008](../008-webauthn/spec.md))

- **FR-WEBAUTHN-001**: `WebauthnManager` is the public API of the passkeys (list, registration options and verification, passwordless sign-in, second step, rename, remove); `WebauthnCeremonies` runs the ceremonies on web-auth/webauthn-lib with session-bound, single-use challenges (claimed once under the attempt limiter's lock), user verification required and exact origins; `WebauthnStore` (DBAL statements, detached `WebauthnCredentialRecord` values, conditional counter UPDATE); VOs `WebauthnPasskey`, `WebauthnRemoval`, `WebauthnFailure`, helper `Base64Url`. `WebauthnConfiguration` and `Resources/config/webauthn.yaml` register nothing while disabled.
- **FR-WEBAUTHN-002**: Entity `WebauthnCredential` (exact-key columns, XML mapping) and the up / down SQL scripts per platform under `Resources/sql/webauthn/`.
- **FR-WEBAUTHN-003**: Controllers `WebauthnLoginController` (passwordless options and assertion, first factor `webauthn`), `WebauthnMfaController` (second step of a pending sign-in) and `WebauthnAccountController` (account page, registration, rename, remove, recent sign-in, last-factor guard); forms `WebauthnCredentialFormType`, `WebauthnRenameFormType`; templates `webauthn/*` and the script `nowo-auth-kit-webauthn.js` (CSP nonce).
- **FR-WEBAUTHN-004**: Events `WebauthnCredentialAddedEvent`, `WebauthnCredentialUsedEvent`, `WebauthnCredentialRenamedEvent` (2.5.0: `oldLabel`, `newLabel`, none for the same label), `WebauthnCredentialRemovedEvent` (base `WebauthnEvent`) and `WebauthnAuthenticationFailedEvent`, framework-free, after the write; `WebauthnSignedInSubscriber` records the full sign-in time.
- **FR-WEBAUTHN-005**: UserKit eraser `WebauthnPersonalDataEraser` (`auth_kit_webauthn`) and export section `WebauthnPersonalDataExportProvider` (`passkeys`); since 2.4.1 `WebauthnErasure` (always registered) deletes a user's passkeys whatever the passkey mode, used by `MfaManager::forgetUser()` and `WebauthnManager::forgetUser()` (audit ZBX-v-I2).
- **FR-WEBAUTHN-006**: 2.6.0 runtime relying party (spec 008, FR-WA-09..11): port `WebAuthnRelyingPartyProviderInterface` (context VO `ValueObject\WebAuthnRelyingPartyContext`; default `RelyingParty\ConfiguredWebAuthnRelyingPartyProvider`, the configuration), asked per ceremony by the internal `RelyingParty\WebAuthnRelyingPartyResolver` and validated by the public `WebAuthnRelyingPartyValidator` (fail closed: `WebauthnFailure::RELYING_PARTY`, logged); internal VO `RelyingParty\WebAuthnRelyingParty`; nullable `rp_id` column per passkey (`WebauthnFailure::RP_ID_MISMATCH`); `WebauthnManager::countCredentialsForRpId()` / `countCredentialsOutsideRpId()`.

### Multi-account switcher (v1.34, opt-in `multi_account.enabled`)

- **FR-MULTI-001**: `AccountVault` keeps several accounts signed in at once in one browser, each with its own session; `AccountState`, `SwitchOutcome`, `VaultEntry`, `VaultCookie` (opaque token per account, no identity inside), `VaultCrypto` (opaque tokens, HMAC lookups, sealed blob), `SessionHopper`, `AccountPresentation` and the presenter SPIs (`AccountPresenterInterface`, framework-free `AccountPresentationProviderInterface`, default `IdentifierAccountPresenter`), `AccountRemovalReason`, enrolment SPIs (`EnrolmentGateInterface`, `EnrolmentPolicyInterface`, `DefaultEnrolmentGate`). `MultiAccountConfiguration` and `Resources/config/multi_account.yaml` register nothing while disabled.
- **FR-MULTI-002**: Entity `AccountVaultEntry` and `AccountVaultStore` (one parameterised DBAL statement per operation, conditional token rotation).
- **FR-MULTI-003**: `AccountsController` (chooser, add, switch, sign one account out) and `MultiAccountSubscriber` (glue to the firewall events).
- **FR-MULTI-004**: Events: account added, removed, switched and the deny-only switching event (base `AccountEvent`).
- **FR-MULTI-005**: `PurgeAccountVaultCommand` deletes entries past `max_lifetime`.
- **FR-MULTI-006**: `MultiAccountPass` fails closed when UserKit sessions or the firewall data are missing.
- **FR-MULTI-007**: Twig `MultiAccountExtension`, `AccountRow`, `AccountSwitcherView` and the switcher / chooser templates.

### Private entity manager (v1.46 opt-in; v2.0 the only mode)

- **FR-PEM-001**: `AuthKitEntityManager` and `AuthKitManagerRegistry` give AuthKit stores a private entity manager, so the bundle never flushes the host's (REQ-ARCH-001 rule 6).
- **FR-PEM-002**: `PrivateEntityManagerMiddleware`, `Driver`, `Connection` and `Subscriber` report commits, rollbacks and savepoints of the shared connection to the private manager.
- **FR-PEM-003**: `PrivateEntityManagerPass` wires the mode and `EntityManagerOptionPass` validates `doctrine.entity_manager`.
- **FR-PEM-004** (v1.54; removed in 2.0 with host mode): `HostFlushReporter` reports, in host mode (`private_entity_manager` unset or false), the application's pending changes an AuthKit write flushes (one deprecation per class and unit of work; read-only, reset at every boundary).
- **FR-PEM-005** (v1.55, Q-W2-1): `AuthKitEntities` re-resolves by id, in AuthKit's manager, an AuthKit row the application loaded through its own manager (`adopt()`) and refuses to save an existing row that manager does not hold (`assertSavable()`), with `AuthKitEntityNotFoundException` / `AuthKitEntityNotManagedException`; nothing of the application's manager is flushed, merged or detached. (The 1.54 host-flush reporter `HostFlushReporter` was removed in 2.0 with host mode.)
- **FR-PEM-006** (v2.0): with the AuthKit entities mapped the private manager is always registered; `doctrine.private_entity_manager: false` fails the build, `true` is a deprecated no-op (removed in 3.0). The account writes stay on the application's manager (documented residual).

### Transactions, locks and savepoints (v1.43-1.48)

- **FR-TX-001**: `AuthKitTransactionRunner`, `AuthKitNamedLock` and `AccountLock` run check-then-insert races under named locks taken in a fixed order before any row lock.
- **FR-TX-002**: `AuthKitSavepoint` and `AuthKitRows` run DBAL / bulk DQL writes inside a savepoint when a transaction is already open.
- **FR-TX-003**: `UnitOfWorkBoundaries` makes the bundle act only at the outermost unit-of-work boundary of a process.
- **FR-TX-004**: `StorageFailureSubscriber` answers a lock conflict (deadlock, lock wait, serialization failure, named lock not obtained) on AuthKit's pages with 409 (503 per `conflict_response.status`) and a conflict code: `CONFLICT_001` + `Retry-After` when nothing was committed, `CONFLICT_002` without it when the request already committed (1.53.0, REQ-RUNTIME-003). Unique violations are not mapped. Every answer carries `X-Conflict-Code` (2.0). The commit count is the main request's, also for an AuthKit sub-request (2.2.1); a lock conflict thrown by a host social callback handler gets the same answer (2.2.1).
- **FR-TX-005**: `ConflictResponsePass` gives the conflict answer a commit ledger: NowoTransactionDoctrineBundle's when registered, else AuthKit's own with transaction-doctrine's commit tracking middleware (`conflict_response.commit_detection`).

### Exact identifiers and identifier normalisation (v1.47-1.49)

- **FR-EXACT-001**: `ExactIdentifier` compares identities used as database keys exactly (REQ-SEC-006).
- **FR-EXACT-002**: `IdentityCollationListener` and `PasswordResetCollationListener` set a binary NO PAD collation on identity columns (MySQL / MariaDB).
- **FR-IDNORM-001**: `UserIdentifierNormalizerInterface` with `ExactIdentifierNormalizer` (default) and `LowercaseAsciiEmailIdentifierNormalizer` (opt-in `identifier_normalizer`); `NormalizingUserLookup` decorates the user lookup port and `LoginIdentifierNormalizationListener` normalises the posted login identifier.

### Framework-free ports (v1.46; v2.0 the only ports, REQ-ARCH-001 rule 3)

- **FR-PORT-001**: Gates (`UserCreatorGate`, `UserDirectoryGate`, `UserStoreGate`, `EnrolmentPolicyGate`, `AccountPresentationGate`, `MfaRequirementPolicyGate`, `InvitationActionPolicyGate`, `InvitationGrantHandlerGate`, `InvitationsAccessPolicyGate`, `QrLoginStepUpGate`, `SocialCallbackStepGate`) serve AuthKit's internal adapter interfaces (`Port\Internal\*`, v2.0; the 1.x Symfony-typed host interfaces they replace were removed) with the host's (or the built-in) port; `Ports` wraps per-profile and tagged port services at call time; `AuthUserRefs` and `AuthRequestContexts` convert from Symfony types.
- **FR-PORT-002**: Value objects `AuthUserRef`, `AuthRequestContext`, `EnrolmentCandidate`, `QrLoginChallengeRef`, `SocialCallbackResult`; `Internal\HiddenValues` keeps their sensitive values out of dumps.
- **FR-PORT-003**: `AuthKitPortsPass` refuses a port service that does not implement its port; `RemovedPortsPass` (v2.0) fails the build on any service, alias, decorator or autodiscovered class still on an interface removed in 2.0.

### User ports (v1.41, v1.46)

- **FR-USER-001**: `UserFactoryInterface` / `UserCreatorInterface`, `UserLookupInterface` / `UserDirectoryInterface`, `UserWriterInterface` / `UserStoreInterface` with Doctrine defaults (`DoctrineUserLookup`, `DoctrineUserWriter`, `PropertyAccessUserFactory`); `NewUser`, `UserCreationSource`, `UserIds`, `DuplicateUserException`; `UserRegisteredEvent`, `CredentialsChangedEvent` and `CredentialsChangedListener`.

### Password reset table storage (v1.46, `password_reset.storage: table`)

- **FR-RESET-005**: `PasswordResetRequest` entity, `PasswordResetRequestRow`, `PasswordResetRequestRepository` (DBAL), `TablePasswordResetTokenManager`, `ProfilePasswordResetTokenManager` (per-profile storage), `PasswordResetCredentials` (credential rules shared by both storages) and `PasswordResetCompletedEvent`.
- **FR-RESET-006**: `PasswordResetTableInvalidationListener` kills the pending reset row when the user's credentials change (SEC-004 L6).

### GDPR ports and UserKit sections (v1.36-1.45)

- **FR-GDPR-001**: Erasers and export providers registered with UserKit installed: MFA (`MfaPersonalDataEraser` / `ExportProvider`, dormant variant when tables are missing), account switcher (`AccountSwitcherPersonalDataEraser`), social accounts (`SocialAccountPersonalDataEraser`, exporter port and Doctrine default, `SocialAccountUserKitExportProvider`), invitations (eraser and exporter ports, Doctrine default, subject resolver SPI, `InvitationUserKitEraser`, `InvitationUserKitExportProvider`, `InvitationErasureResult`, `InvitationPersonalDataErasedEvent`); `ExactRows` does exact-key lookups.

### Social callback steps (v1.37, v1.46)

- **FR-SOCSTEP-001**: `SocialCallbackHandlerInterface` / framework-free `SocialCallbackStepInterface` take over the OAuth callback for a host step; `SocialCallbackContext`, `SocialCallbackFailure`, `SocialHandlerSignInRollback`, `FirstUserLockRequired` (retry under the user-class lock) and `ProviderMarkResolver`.

### Login target, session expiry and programmatic login (v1.44)

- **FR-LOGINT-001**: `LoginTargetGuard`, `LoginTargetPolicy`, `LoginTarget`, `NavigationKind`, `LoginTargetCacheWarmer`, voter and fallback-provider ports and `BackgroundTargetPathPreserver` keep the post-login target to a same-origin HTML page.
- **FR-LOGINT-002**: `SessionExpiredResponder` answers background calls of an expired session (opt-in `session_expired.enabled`).
- **FR-LOGINT-003**: `ProgrammaticLoginAuthenticator` and its compiler pass name an authenticator for `Security::login()` calls that have none (social, QR).

### Other extras

- **FR-AVAIL-001**: `FeatureAvailability` and `FeatureAvailabilityCheckerInterface` (`availability_checker` of magic login / password reset, v1.37).
- **FR-MAGIC-003**: `MagicLoginDisabledListener` refuses magic-login requests on the firewall dispatcher when the feature is off.
- **FR-QR-004**: `QrLoginStepUpPolicyInterface` (framework-free step-up before a phone approves a QR login).
- **FR-INV-011**: Framework-free invitation SPIs (`InvitationGrantHandlerInterface`, `InvitationActionPolicyInterface`, `InvitationsAccessPolicyInterface`).
- **FR-ORM-002**: `Resources/config/doctrine-xml*` XML mappings for hosts that map entities by XML (DDD opt-in).
- **FR-UI-002**: `View\ProviderLink` / `ProviderMark` and the shipped provider marks under `Resources/public/img/social`.

---

## Success Criteria

- **SC-001**: All production files in [`code-inventory.md`](code-inventory.md) mapped (**493/493** units under `src/` as of 2026-11-25: 422 PHP + 71 Resources).
- **SC-002**: 100% PHPUnit line coverage on `src/` (project standard / `make test-coverage-100`).
- **SC-003**: Config keys match [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-004**: Product docs exist for each shipped surface: USAGE, CONFIGURATION, PASSWORD-RESET, MAGIC-LOGIN, QR-LOGIN, SOCIAL-LOGIN, OIDC-PROVIDERS, SSO, INVITATIONS, SECURITY.

---

## Explicit non-goals

- Native **SAML 2.0** — enterprise SSO is OIDC-via-social only; SAML is roadmap.
- Authorization voters beyond registration role assignment / host security.

---

## Validation

`make test-coverage-100`, PHPStan level 8, `make release-check`.
