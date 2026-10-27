# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.30.0] - 2026-10-23](#1300---2026-10-23)
- [[1.29.0] - 2026-10-23](#1290---2026-10-23)
- [[1.28.0] - 2026-10-20](#1280---2026-10-20)
- [[1.27.3] - 2026-10-19](#1273---2026-10-19)
- [[1.27.2] - 2026-10-18](#1272---2026-10-18)
- [[1.27.1] - 2026-10-18](#1271---2026-10-18)
- [[1.27.0] - 2026-10-18](#1270---2026-10-18)
- [[1.26.0] - 2026-10-15](#1260---2026-10-15)
- [[1.25.0] - 2026-10-13](#1250---2026-10-13)
- [[1.24.1] - 2026-10-13](#1241---2026-10-13)
- [[1.24.0] - 2026-10-13](#1240---2026-10-13)
- [[1.23.1] - 2026-10-12](#1231---2026-10-12)
- [[1.23.0] - 2026-10-12](#1230---2026-10-12)
- [[1.22.4] - 2026-10-09](#1224---2026-10-09)
- [[1.22.3] - 2026-10-09](#1223---2026-10-09)
- [[1.22.2] - 2026-10-09](#1222---2026-10-09)
- [[1.22.1] - 2026-10-01](#1221---2026-10-01)
- [[1.22.0] - 2026-09-28](#1220---2026-09-28)
- [[1.21.1] - 2026-09-27](#1211---2026-09-27)
- [[1.21.0] - 2026-09-24](#1210---2026-09-24)
- [[1.20.1] - 2026-09-07](#1201---2026-09-07)
- [[1.20.0] - 2026-08-24](#1200---2026-08-24)
- [[1.19.0] - 2026-08-24](#1190---2026-08-24)
- [[1.18.0] - 2026-08-24](#1180---2026-08-24)
- [[1.17.5] - 2026-08-24](#1175---2026-08-24)
- [[1.17.4] - 2026-08-20](#1174---2026-08-20)
- [[1.17.3] - 2026-08-19](#1173---2026-08-19)
- [[1.17.2] - 2026-08-18](#1172---2026-08-18)
- [[1.17.1] - 2026-08-18](#1171---2026-08-18)
- [[1.17.0] - 2026-08-12](#1170---2026-08-12)
- [[1.16.0] - 2026-08-10](#1160---2026-08-10)
- [[1.15.0] - 2026-08-05](#1150---2026-08-05)
- [[1.14.0] - 2026-08-05](#1140---2026-08-05)
- [[1.13.1] - 2026-08-04](#1131---2026-08-04)
- [[1.13.0] - 2026-08-04](#1130---2026-08-04)
- [[1.12.2] - 2026-08-01](#1122---2026-08-01)
- [[1.12.1] - 2026-07-31](#1121---2026-07-31)
- [[1.12.0] - 2026-07-31](#1120---2026-07-31)
- [[1.11.4] - 2026-07-30](#1114---2026-07-30)
- [[1.11.3] - 2026-07-30](#1113---2026-07-30)
- [[1.11.2] - 2026-07-30](#1112---2026-07-30)
- [[1.11.1] - 2026-07-30](#1111---2026-07-30)
- [[1.11.0] - 2026-07-30](#1110---2026-07-30)
- [[1.10.1] - 2026-07-30](#1101---2026-07-30)
- [[1.10.0] - 2026-07-30](#1100---2026-07-30)
- [[1.9.1] - 2026-07-30](#191---2026-07-30)
- [[1.9.0] - 2026-07-30](#190---2026-07-30)
- [[1.8.2] - 2026-07-29](#182---2026-07-29)
- [[1.8.1] - 2026-07-29](#181---2026-07-29)
- [[1.8.0] - 2026-07-28](#180---2026-07-28)
- [[1.7.6] - 2026-07-27](#176---2026-07-27)
- [[1.7.5] - 2026-07-23](#175---2026-07-23)
- [[1.7.4] - 2026-07-22](#174---2026-07-22)
- [[1.7.3] - 2026-07-22](#173---2026-07-22)
- [[1.7.2] - 2026-07-22](#172---2026-07-22)
- [[1.7.1] - 2026-07-21](#171---2026-07-21)
- [[1.7.0] - 2026-07-21](#170---2026-07-21)
- [[1.6.1] - 2026-07-21](#161---2026-07-21)
- [[1.6.0] - 2026-07-21](#160---2026-07-21)
- [[1.5.1] - 2026-07-16](#151---2026-07-16)
- [[1.5.0] - 2026-07-14](#150---2026-07-14)
- [[1.4.4] - 2026-07-14](#144---2026-07-14)
- [[1.4.3] - 2026-07-06](#143---2026-07-06)
- [[1.4.2] - 2026-07-05](#142---2026-07-05)
- [[1.4.1] - 2026-07-05](#141---2026-07-05)
- [[1.4.0] - 2026-07-05](#140---2026-07-05)
- [[1.3.0] - 2026-07-03](#130---2026-07-03)
- [[1.2.0] - 2026-07-03](#120---2026-07-03)
- [[1.1.1] - 2026-06-18](#111---2026-06-18)
- [[1.1.0] - 2026-06-18](#110---2026-06-18)
- [[1.0.0] - 2026-06-18](#100---2026-06-18)

## [Unreleased]

Security hardening (SEC-004 2026-10-27), single translation domain and attribute-declared routes. Several defaults and behaviours change: read [UPGRADING](UPGRADING.md#unreleased) before updating.

### Security

- **High — look-alike identifiers (CWE-178/640), affects password reset >= 1.1.0, magic login >= 1.6.0, social login e-mail linking >= 1.9.0, up to 1.30.0.** With an accent/case-insensitive database collation (MySQL `utf8mb4_0900_ai_ci`, MariaDB `*_unicode_ci`) `victim@exámple.com` resolved to `victim@example.com`, and the typed address was handed to the notifier: the victim's reset / magic link went to an IDN look-alike the attacker owns. Lookups now accept only an account whose stored identifier equals the input after NFC + lower-casing (`Security\UserIdentifierMatcher`); notification contexts carry the **stored** identifier and the `user`; e-mail identifiers must be plain ASCII addresses on the request forms.
- **High — cross-profile social sign-in (CWE-639), affects 1.9.0 to 1.30.0 with several profiles.** A social link was found by provider subject only and loaded with the current profile's user class: a member's identity signed in to the admin profile as the admin with the same id. Links are now scoped to the user class (unique `provider + provider_user_id + user_class`, schema change) and the loaded row must be of that class.
- **Medium:** social login created accounts regardless of `registration_mode` (`create_user_if_missing` now defaults to `false` and honours `RegistrationGate`); a social identity was linked to a pre-registered local account and kept its password (`social_login.link_existing_account`, default `reset_password`); the reset-code attempt counter was keyed by the typed identifier, so spelling variants bypassed `max_code_attempts` (now keyed by the account, plus a per-IP `code_rate_limit`).
- **Low:** `configure-security` wrote a `login_link.lifetime` shorter than administrator links and no password in `signature_properties`; magic links were signed / consumed by the `main` firewall handler and QR challenges were not bound to a profile; a request locale from input could reach generated paths (`//host` redirect); new passwords needed only 6 characters; registration auto-login built the token by hand; a pending reset credential survived an identifier or password change.
- **CSRF fails closed:** QR approval and the magic-login confirm interstitial refuse to compile (`CsrfProtectionPass`) and to act (`Form\FormCsrf`) without the form CSRF extension; `symfony/security-csrf` is now required.

### Added

- Config `password_policy.min_length` (default 12) and `password_policy.not_compromised` (default false); `password_reset.code_rate_limit` (10) / `code_rate_window` (900); `social_login.link_existing_account` (`reset_password` | `deny` | `keep`).
- `MagicLoginNotificationContext::$user` and `PasswordResetNotificationContext::$user`.
- `Security\LoginLinkHandlerLocator` (+ `LoginLinkHandlerLocatorPass`): the login link handler of each firewall.
- `Doctrine\PasswordResetTokenInvalidationListener` (Doctrine `onFlush`).
- Translations `validation.identifier_email` and `validation.password_compromised` (7 locales).
- Every controller action declares its `#[Route]` (default path and name, HTTP methods, requirements); `AuthKitRouteLoader` copies the attribute once per profile with the configured `routes.<key>.path` / `.name` and locale variants, so the generated routes are identical to 1.30.0 (REQ-SF-004).
- `DependencyInjection\Compiler\ControllerRouteAttributesPass` removes the autoconfigured `routing.controller` tag from the AuthKit controllers, so an application importing `resource: routing.controllers` does not register un-profiled copies.
- `Form\Extension\ConstraintMessageTranslationExtension`: AuthKit constraint messages are translated in the form translation domain (`NowoAuthKitBundle`) after validation; a key the application defines in its `validators` catalogue keeps its translation (REQ-I18N-003).

### Changed

- `social_login.create_user_if_missing` defaults to `false` (was `true`).
- `MagicLoginNotificationContext::$identifier` / `PasswordResetNotificationContext::$identifier` hold the identifier stored on the account (was the typed input) and `maskedIdentifier` is derived from it.
- New-password minimum length 12 (was 6) unless `password_policy.min_length` says otherwise.
- `nowo:auth-kit:configure-security` writes `login_link.signature_properties: [<identifier>, password]` and `lifetime: max(magic_login.lifetime, magic_login.admin_link.lifetime)`.
- `SocialLoginAccount` unique constraint `uniq_auth_kit_social_account_provider_subject_class` on `provider, provider_user_id, user_class` (was `uniq_auth_kit_social_account_provider_subject` on `provider, provider_user_id`); `SocialLoginAccountRepository::findOneByProviderSubject()` takes an optional user class.
- The registration page signs the new account in with `Security::login($user, 'form_login', <firewall>)`.
- `QrLoginChallengeManager::verifyDesktopCookie()` takes the profile name; challenges created before the update stop completing (their 90 s lifetime makes this invisible in practice).
- Requires `symfony/security-csrf`, `symfony/polyfill-intl-normalizer` and `symfony/polyfill-mbstring`.
- The AuthKit form constraint messages live only in the `NowoAuthKitBundle` catalogues; `make validate-translations` fails on any catalogue outside the bundle domain (REQ-I18N-003).
- PHPUnit fails on any deprecation (`failOnDeprecation`, `ignoreSuppressionOfDeprecations`, `restrictDeprecations="false"`): `SYMFONY_DEPRECATIONS_HELPER` is ignored by PHPUnit >= 10. Tests that exercise deprecated configuration on purpose are marked `#[IgnoreDeprecations]`; `phpunit-baseline.xml` lists the two indirect Twig deprecations raised by the UiKit `PageContractTestCase` (REQ-SF-005).
- CI pins the transitive Symfony components (event-dispatcher, error-handler, …) to the cell minor too, so the 7.4 cells no longer run 8.x components; the test kernels set `framework.property_info.with_constructor_extractor` on Symfony 7.3/7.4.
- Spec Kit baseline (`specs/001-baseline`) covers the 1.29/1.30 units: 186/186 production files mapped (REQ-SPECKIT-003).
- The test bootstrap clears the integration kernels' cache directory, so repeated local runs do not hit the previous run's rate limits.

### Removed

- `src/Resources/translations/validators.<locale>.yaml` (7 locales): same keys and texts as the `NowoAuthKitBundle` catalogues.

## [1.30.0] - 2026-10-23

Opt-in options for legacy hosts: unlocalized route suffix, POST logout, reset check-email page and session token, throttled reset signal, optional entity mapping, no routes for disabled features. See [UPGRADING](UPGRADING.md#to-1300).

### Added

- `locale.unlocalized_route_suffix` (default `_unlocalized`): name suffix of the bare route twins when `locale.in_path: both`, e.g. `.default` for applications whose bare routes are already named `<route>.default`. `UnlocalizedDefaultLocaleSubscriber` follows it.
- `routes.logout.methods` (default `[GET]`): `[POST]` makes the sign-out a CSRF-protected POST with the firewall `logout.enable_csrf`; the embed widget then renders `Form\LogoutFormType` (hidden `_csrf_token`) instead of the GET link.
- `password_reset.after_request_route` (default null): redirect after a valid reset request to a host "check your email" page; `PasswordReset\PasswordResetCheckEmail` (session payload: profile, `tokenTtl`, `throttled`, `availableAt`) is stored for it.
- `password_reset.token_in_session` (default false): the link token moves into the session and the form is served on the token-less URL (`/reset-password/reset`).
- `PasswordReset\PasswordResetThrottledException`: a custom `PasswordResetTokenManagerInterface::createForUser()` throws it when a credential was requested too recently; `PasswordResetRequestHandler::handle()` now returns a `PasswordResetRequestOutcome` (`throttled`, `availableAt`) and sends nothing in that case, without revealing it on the request page.
- `doctrine.register_mapping` (`true` default | `false` | `auto`): leave the `auth_kit_*` entities unmapped when social and QR login are disabled, including under Doctrine `auto_mapping`.
- `routing.skip_disabled_features` (default false): do not register the routes of disabled registration, password reset, magic, social and QR login.

## [1.29.0] - 2026-10-23

Administrator-issued magic links (harvested from Symfony Beacon spec 159). See [UPGRADING](UPGRADING.md#to-1290).

### Added

- `MagicLogin\AdminMagicLinkIssuer`: support sign-in links an administrator issues for another account — `issue()` (show once), `send()` (e-mail through `MagicLoginNotifierInterface` in the recipient's locale), `canIssue()`. Never for the issuer's own account nor for protected roles (`AdminMagicLinkRefusedException`), and `AdminMagicLinkIssuedEvent` for the host's audit trail. Documented in [MAGIC-LOGIN.md](MAGIC-LOGIN.md#administrator-issued-links).
- Config `profiles.*.magic_login.admin_link.lifetime` (default 3600) and `admin_link.protected_roles` (default `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`); parameters `nowo_auth_kit.magic_login.admin_link.*`.

### Changed

- `MagicLogin\MagicLinkFactory` builds the login link (recipient locale, `absolute_url.base_uri`) for both the sign-in page and administrator-issued links; `MagicLoginRequestHandler` behaves as before.

## [1.28.0] - 2026-10-20

Wave-2 REQ compliance: configurable OAuth2 timeouts, Doctrine table prefix, QR deny form type, injectable clock, CI and tooling. See [UPGRADING](UPGRADING.md#to-1280).

### Added

- `http_client.timeout` / `http_client.max_duration` root config (defaults 10 s / 20 s, `max_duration >= timeout` validated) for the OAuth2 token and userinfo calls of social login, documented with the PHP / Caddy / FrankenPHP timeout hierarchy (REQ-RUNTIME-001).
- `doctrine.table_prefix` root config and `Doctrine\TablePrefixListener`: prefixes the AuthKit tables and their named indexes / unique constraints, never host entities (REQ-ORM-002).
- `QrLoginDenyType`: the QR approve page renders the Deny button through `form_start` / `form_end` (`deny_form` template variable) instead of a raw `<form>` (REQ-TWIG-005).
- `QrLoginChallengeManager::expiresIn()`; the manager and `QrLoginChallengeRepository` accept an optional `Psr\Clock\ClockInterface` (autowired when available) for expiry, cookie lifetime and status timestamps (REQ-DI-001).
- `make check-no-raw-html-form` (in `release-check`), `make assets` (no-op) and `.scripts/validate-translations.php` (key parity across locales for `NowoAuthKitBundle` and `validators`, run by `make validate-translations` and CI) (REQ-MAKE-001/002/004).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- The password-toggle stylesheet is loaded through the `nowo_password_toggle` asset package; `conflict` with `nowo-tech/password-toggle-bundle` < 2.0.3 (the first release registering that package) (REQ-ASSETS-004).
- CI: every `symfony/*` package is pinned to the matrix minor (`7.4.*`, `8.1.*`) so a 7.4 cell no longer resolves Symfony 8; the Symfony 8.0 cell is dropped (end of maintenance, every 8.0.x release has security advisories; `composer.json` still allows `^8.0`). The quality job (cs-check, PHPStan, Igor, translation parity) now also runs on pushes (REQ-SF-002, REQ-CS-005/006/008).
- Makefile `help` follows the canonical block order and lists `igor`; `release-check` runs in REQ-MAKE-002 order (REQ-MAKE-001/002).
- Demo: Caddyfiles and `php-dev.ini` set PHP `max_execution_time` 30 s, Caddy write timeout 40 s and FrankenPHP `max_wait_time` 30 s (REQ-RUNTIME-001); `.env.test` `DATABASE_URL` points at the Compose `mysql` service (REQ-DEMO-011).
- Tests use `#[DataProvider]` attributes instead of `@dataProvider` docblocks (REQ-TEST-010).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (`.git` file) (REQ-GIT-001).

### Documentation

- Documentation: wave-1 REQ compliance — README documentation list order (PSR/Templates moved to Additional documentation), CONTRIBUTING Cursor Agent queue settings (REQ-IDE-006), TOCs on long docs, SDD scope (social login / SSO in scope) and ENGRAM link, demo `.env.test` comment, Spec Kit inventory refresh (181/181).

[1.28.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.28.0

## [1.27.3] - 2026-10-19

flash_trans translates TranslatableMessage / TranslatableInterface flashes instead of returning the object (500 in host toast templates); optional locale argument. See [UPGRADING](UPGRADING.md#to-1273).

### Fixed

- **`flash_trans` with `TranslatableInterface` flashes**: a `TranslatableMessage` (or any `TranslatableInterface`) flash
  was returned unchanged, so `{{ message|flash_trans }}` failed with "Object of class ... could not be converted to
  string" (HTTP 500). The filter now calls `->trans($translator, $locale)`, with the message's own domain and
  parameters. It also takes an optional locale (`message|flash_trans({}, 'es')`), used for string keys too.
  `AuthKitFlashExtensionTest` covers both shapes.

[1.27.3]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.27.3

## [1.27.2] - 2026-10-18

QR login Stimulus targets resolved, approve link always shown, reset request links the code page, override namespace fixed in the docs. See [UPGRADING](UPGRADING.md#to-1272).

### Fixed

- **Override namespace in the docs**: `docs/TEMPLATES.md`, `USAGE.md`, `CONFIGURATION.md`, `UPGRADING.md`, the README
  and the `web_ui.templates` config info documented `{% extends '@!NowoAuthKitBundle/...' %}`, which fails with "There
  are no registered paths for namespace "!NowoAuthKitBundle"". The bundle-original namespace is `@!NowoAuthKit/...`
  (Symfony's "!" namespace of the bundle); every snippet now uses it. `AuthPagesKernelTest` asserts the documented
  namespace resolves and that no doc mentions the wrong one.
- **QR challenge with a Stimulus controller**: with `web_ui.qr_login.stimulus_controller` set, `data-controller` and
  the `data-<id>-*-value` attributes were on the challenge root while the `continue` and `retry` targets render in the
  form actions, outside it, so Stimulus never found them. The controller attributes now sit on the auth panel
  (`AuthPage::attributes`, built by the new `AuthPageFactory::stimulusAttributes()`), the common ancestor of the
  challenge and of the actions. `PageContractTest` asserts every `data-<id>-target` is a descendant of the controller
  element.
- **Password reset request links the code page**: with `password_reset.delivery: code` or `both` the reset request
  page now links the code-entry page ("I already have a code", `reset.request.enter_code`, all 7 locales) before "Back
  to sign in". `delivery: link` is unchanged.
- **QR approve link always offered**: "Open approve link on your phone" was rendered only without a QR image. It is
  now also rendered next to the image (`[data-nowo-auth-kit-qr-region="link"]`, Stimulus target `link`) for the
  same-device flow and screen readers; without an image it stays the `image` region as before.

[1.27.2]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.27.2

## [1.27.1] - 2026-10-18

Root form errors shown once with UiKit 2.3 (and 2.2). See [UPGRADING](UPGRADING.md#to-1271).

### Fixed

- **Root form errors shown once with UiKit 2.3**: the UiKit 2.3 `page/auth` scaffold prints the root form errors
  (`form_errors(form)`) itself, and AuthKit already prints them in `nowo_ui_auth_fields`, so the invalid-CSRF and other
  form-level errors appeared twice. `security/_auth_page.html.twig` now sets `nowo_ui_auth_root_errors = false` (the
  UiKit 2.3 opt-out; ignored by 2.2) and keeps its own `form_errors(form)` call, so the errors are shown exactly once on
  UiKit 2.2 and 2.3. Covered by `AuthPagesKernelTest` (error text counted once in the response).

[1.27.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.27.1

## [1.27.0] - 2026-10-18

Auth pages on the UiKit 2.2 page contract (REQ-UI-003): every security page on the auth scaffold and guest layout, reset-password field order fixed, override-friendly QR login page, translated validation messages; deprecated `templates.*` / `css.*` keys mapped to `web_ui.*` / UiKit theme. See [UPGRADING](UPGRADING.md#to-1270).

UiKit 2.2 page contract (REQ-UI-003, phase 2): every auth page is the UiKit auth scaffold on the host's guest layout.
See [UPGRADING — Template contract](UPGRADING.md#template-contract-127) and [TEMPLATES.md](TEMPLATES.md).

### Added

- **Page contract**: requires `nowo-tech/ui-kit-bundle` ^2.2. Login, register, reset request / password / code, magic
  link request / confirm, QR challenge and QR approve extend `@NowoUiKit/page/auth.html.twig` (through
  `security/_auth_page.html.twig`) on `nowo_ui_kit.layouts.guest`; controllers build a UiKit `AuthPage`
  (`View\AuthPageFactory`: heading, intro, brand, security error, form, submit label, footer links).
- Per-profile **`web_ui`** node: `layout_template` (null = UiKit guest layout), `templates.<page>` (now also
  `qr_login_show` / `qr_login_approve`), `form_themes`, `brand`, `qr_login.stimulus_controller`.
- **QR challenge page**: state / URL / timing data attributes, translated state texts, regions and blocks (status,
  countdown, continue, retry), optional Stimulus values and targets, bundled CSP-safe poller
  `js/nowo-auth-kit-qr-login.js` (countdown, status, redirect on approval, `nowo-auth-kit:qr-state` event); no-JS
  Continue and "Generate a new code".
- `validators` catalogues (7 locales) for the form constraint messages; translations for the QR states.
- AuthKit PasswordToggle form theme on the UiKit `input` slot (`@NowoAuthKitBundle/form/password_toggle_widget.html.twig`).
- Page contract test (`Tests\Integration\Contract\PageContractTest`) and real-kernel tests of the login (CSRF and
  credential errors), password reset by link / code and QR pages.

### Changed

- Default form themes: UiKit form theme (`input`, `check`, `check_input` slots) + the AuthKit PasswordToggle theme
  when NowoPasswordToggleBundle is registered (it was the PasswordToggle theme only, also when the bundle was not
  registered). The seeded FormKit `auth_kit` profile no longer adds Bootstrap classes.
- Embedded widget (`auth_kit_dropdown()`): native `<details>` disclosure and UiKit theme slots / tabs runtime instead
  of Bootstrap dropdown / tab markup and JavaScript.
- Widget scripts (SlideToConfirm, Device Intelligence, OTP input) carry the CSP nonce and load on `nowo_ui_scripts`;
  stylesheets on `nowo_ui_styles`. The PasswordToggle stylesheet loads only when that bundle is registered.

### Fixed

- New-password pages rendered the confirmation field (and CSRF token) **after** the submit button; every field now
  renders before the actions.
- Validation messages (`reset.password.mismatch`, `*.required`, …) were printed as raw keys (no `validators`
  catalogue); the invalid reset code error is translated; form-level errors (invalid CSRF token) are shown.
- SlideToConfirm assets no longer break the pages when the package is installed but not enabled.

### Deprecated

- `profiles.<p>.templates.layout` → `web_ui.layout_template`; `templates.<page>` → `web_ui.templates.<page>`;
  `templates.form_theme` → `web_ui.form_themes` (mapped; `templates.*` stays readable as a resolved mirror).
- `css.button_class` / `css.secondary_button_class` → `nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary`
  / `btn_secondary` (bridged automatically); Twig globals `nowo_auth_kit_button_class` /
  `nowo_auth_kit_secondary_button_class`.
- `@NowoAuthKitBundle/layout.html.twig` (now a bridge to the UiKit guest layout for 1.x page templates); blocks
  `auth_panel_heading`, `auth_brand`.

[1.27.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.27.0

## [1.26.0] - 2026-10-15

### Added

- **`PublicUrlGenerator` (`nowo_auth_kit.public_url_generator`).** A `UrlGeneratorInterface` for host code that builds links leaving the request (notification mails, chat / webhook payloads, share links, exports): absolute URLs and network paths go through `AbsoluteUrlGenerator` on `absolute_url.base_uri` (e.g. `%env(DEFAULT_URI)%`), relative paths through the router. Inject it instead of the router in notifiers so a forged `Host` never reaches a mail generated during a request (Nowo REQ-AUTH-011 item 3); apps can drop their host copies.

[1.26.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.26.0

## [1.25.0] - 2026-10-13

### Security

- Social login: PKCE (RFC 7636, `S256`) on the OAuth authorization-code flow. The `code_verifier` is stored in the session next to the `state`, `code_challenge` / `code_challenge_method` go on the authorize redirect, and `code_verifier` is sent in the token exchange. Per-provider toggle `social_login.pkce` (provider → bool); providers not listed default to enabled for built-in `google`, `github` and `microsoft` and disabled for custom IdPs.
- `AuthKitAttemptLimiter`: `hit()` / `consume()` run the counter read-modify-write under a per-key lock when `symfony/lock` is installed (`lock.factory` service), so concurrent requests can no longer overshoot rate limits / OTP lockout. Without a lock factory the behaviour is unchanged (best-effort, now documented). `symfony/lock` is suggested, not required.

[1.25.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.25.0

## [1.24.1] - 2026-10-13

### Security

- QR login: `/qr-login/{id}/complete` now rejects an approved challenge whose lifetime has expired instead of logging the desktop user in.

### Fixed

- Social login callback: after a successful login without `login_success_route`, the redirect falls back to the profile login route (like the other controllers) instead of the hard-coded `homepage` route, which broke apps without such a route.
- Social login callback: a provider denial (`?error=access_denied&state=…`, no `code`) now shows `social.error.provider_denied` (and burns the pending state) instead of `social.error.invalid_state`.
- `locale.unlocalized: redirect`: the redirect from a bare auth URL to its localized route keeps the query string (e.g. `?token=…`, `_target_path`).

[1.24.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.24.1

## [1.24.0] - 2026-10-13

### Security

- **Password-reset poisoning hardening** — new `absolute_url.base_uri` (nullable, default `null`; e.g. `'%env(DEFAULT_URI)%'`). When set, every absolute URL that leaves the request is built against that fixed origin (scheme, host, port, path prefix) instead of the incoming `Host` header: password-reset link and reset-code page link, magic-login link (Symfony `LoginLinkHandler`, `{_locale}` kept), QR login approve URL, and the social-login OAuth `redirect_uri` (start and code exchange). A forged `Host` on the reset / magic request no longer reaches the victim's email. `null` keeps the previous behaviour exactly; then configure `framework.trusted_hosts` (see `docs/SECURITY.md`).

### Added

- `Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator` — wraps the router, applies a `RequestContext` built from `base_uri` for one generation (`generate()` / `withBaseContext()`) and restores the original context in `finally` (FrankenPHP worker-safe). `AuthKitUrlGenerator` routes `ABSOLUTE_URL` / `NETWORK_PATH` through it; relative paths are unchanged.
- `base_uri` is validated (absolute http(s) URL, no user info / query / fragment) at compile time for literals and at service construction for `%env()%` values (empty env = disabled).

[1.24.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.24.0

## [1.23.1] - 2026-10-12

### Fixed

- A partial `nowo_auth_kit` block loaded after the main config (e.g. `config/packages/prod/*.yaml` with only `profiles.default.registration_mode`) no longer resets `locale` to the legacy defaults (`in_path: never`, `default: en`, `unlocalized: redirect`) nor `default_profile` to its first profile. Each block now maps only the keys it sets; `default_profile` (first profile when unset) and the deprecated `default_locale` / `enabled_locales` / `locale_in_path` mirrors are derived after the merge. Hosts on `in_path: both` lost `/{_locale}/login` and every `*_unlocalized` route (`RouteNotFoundException` on `/login`).

[1.23.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.23.1

## [1.23.0] - 2026-10-12

### Added

- **Admin provisioning CLI** — `nowo:auth-kit:create-admin <identifier> [--auth-profile=NAME]` (not `--profile`, which Symfony reserves for the console profiler) creates a user for an AuthKit profile with the roles from `create_admin.roles` (default `[ROLE_ADMIN]`). The password is always read twice from a hidden prompt (no option/argument, no visible fallback, refuses non-interactive runs), must reach `create_admin.min_password_length` (default `12`) and, when the profile enables `password_strength` and `nowo-tech/password-strength-bundle` is installed, pass the same strength policy as the registration form. Duplicate identifiers are rejected. Bypasses `registration_mode` on purpose. Backed by the `AdminUserCreator` service; dispatches `Nowo\AuthKitBundle\Admin\AdminUserCreatingEvent` (before persist — fill host-specific fields) and `AdminUserCreatedEvent` (after flush — e.g. mark an install wizard as completed).
- **Recipient-locale notifications** — password-reset, magic-login and new-device notifications are prepared in the recipient account's preferred locale: the user entity implements `Nowo\AuthKitBundle\Locale\PreferredLocaleAwareInterface` (or `recipient_locale.resolver` names a `RecipientLocaleResolverInterface` service); when the locale is listed in `locale.enabled`, AuthKit generates the reset / magic link with that `{_locale}`, exposes it as `recipientLocale` on `PasswordResetNotificationContext`, `MagicLoginNotificationContext` and `NewDeviceLoginNotificationContext`, and calls the notifier (and dispatches `PasswordResetRequestedEvent` / `MagicLoginRequestedEvent`) inside Symfony's `LocaleSwitcher::runWithLocale()` so `$translator->trans()` without an explicit locale uses it; the request locale is restored afterwards (worker-safe). `recipient_locale.enabled` defaults to `true` and is a no-op for users without a preference.
- **`flash_trans` Twig filter** (`flash.twig_filter`, default on) — translates a flash key with the first domain of `flash.twig_filter_domains` (default `[messages, NowoAuthKitBundle]`) whose catalogue has it; unknown text and structured flashes pass through.
- `AuthKitUrlGenerator::generate()` / `AuthKitRouteLocaleParameters::merge()` accept an optional explicit `$locale`.

### Changed

- **Flash messages are stored translated** (`flash.translate`, default `true`): AuthKit controllers translate their flash keys (`magic_login.request.flash_sent`, `reset.*.flash_*`, `register.flash_rate_limited`, `social.error.*`, …) from the `NowoAuthKitBundle` domain in the request locale before adding them, so host templates that print `{{ message }}` or `{{ message|trans }}` (and UiKitBundle's toast partial) show text instead of raw keys. Set `flash.translate: false` to keep raw keys.

[1.23.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.23.0

## [1.22.4] - 2026-10-09

### Fixed

- `AuthKitRouteLoader` no longer throws `AuthKit routes already loaded.` when the router loads the `nowo_auth_kit` resource a second time in the same process (cold route cache rebuilt after a host invalidates routes, console commands that warm the router twice, long-running workers). The loader is stateless and returns the same collection on every load.

[1.22.4]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.22.4

## [1.22.3] - 2026-10-09

### Changed

- CSP: the inline `<script>` blocks in `_device_intelligence_assets.html.twig` and `_registration_submit.html.twig` now carry `nonce="…"` from the request attribute `csp_nonce` when it is set, so they run under a nonce-based `script-src` without template overrides. Added a template scan test (inline blocks must declare the nonce; no inline event handlers).

## [1.22.2] - 2026-10-09

### Fixed

- Demo (Symfony 8): require `doctrine/orm` `^3.7` instead of the stale `3.6.7` pin, which made `composer update` unresolvable since the bundle requires ORM 3.7 (`SortDirection`).
- Tests: avoid PHP 8.4-only `new Foo()->method()` chaining in `UnlocalizedDefaultLocaleSubscriberTest` so PHP 8.2/8.3 CI jobs parse the suite.

### Dependencies

- Dev: `igor-php/igor-php` `^0.10.0` (was `^0.9.7`).
- Demo lock refreshed: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `nowo-tech/form-kit-bundle` 2.6.0, `nowo-tech/doctrine-encrypt-bundle` 2.4.2, Twig 3.30; regenerated `config/reference.php`.

[1.22.3]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.22.3
[1.22.2]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.22.2

## [1.22.1] - 2026-10-01

### Fixed

- **`locale.unlocalized: serve`:** `UnlocalizedDefaultLocaleSubscriber` forces `locale.default` at request time on AuthKit `*_unlocalized` routes (after Symfony `LocaleListener`), so bare `/login` (etc.) stays correct when the compiled route `_locale` default was warmed with a different value (Docker image build vs runtime env).

[1.22.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.22.1

## [1.22.0] - 2026-09-28

### Security

- QR login approve always uses Form CSRF via `QrLoginApproveType` (slide-to-confirm remains optional UX only).

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.21.1] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.22.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.22.0
[1.21.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.21.1

## [1.21.0] - 2026-09-24

### Fixed

- **FrankenPHP worker mode without kernel reset** (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)):
  - QR login: `QrLoginChallengeManager::find()` now reads the challenge with `Query::HINT_REFRESH` (`QrLoginChallengeRepository::findFresh()`), so a worker never decides on a stale identity-map copy. `approve()`, `deny()`, `consume()` and the expiry check move the status with an atomic conditional `UPDATE … WHERE status = :expected`; a lost race throws the new `QrLoginChallengeConflictException` (approve/deny answer `409`, complete redirects to login without logging in). A challenge can only be consumed once across workers.
  - Password reset: the code flow refreshes the user before comparing the stored hash/expiry, and the link-token lookup uses `Query::HINT_REFRESH`. `PasswordResetUserResolver` / `MagicLoginUserResolver` also `refresh()` managed users after lookup.
  - Social login: credential and account lookups use `Query::HINT_REFRESH`; `SocialAccountLinker` refreshes linked/matched users before login. OAuth token and userinfo calls set explicit `timeout` (10 s) and `max_duration` (20 s); override the `$timeout` / `$maxDuration` arguments of `OAuth2Client` to change them.
  - Failed flushes no longer leave a closed EntityManager for the rest of the worker's life: the new `EntityManagerRecovery` service resets closed managers through `ManagerRegistry` before rethrowing (registration, password reset, social login, QR login).
  - Registration: a unique constraint violation on flush is converted to a `RuntimeException`, so `RegisterController` redirects instead of returning a 500.
  - `auth_kit_dropdown` / `AuthEmbedContextFactory` only trusts the security token when the main request is behind a firewall with security enabled, so a token left over from a previous request is never rendered on public pages.

### Added

- [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) — audit under scenario B (kernel not reset between requests).
- `Doctrine\EntityManagerRecovery`, `QrLoginChallengeConflictException`, `QrLoginChallengeRepository::findFresh()` / `transitionStatus()`.

[1.21.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.21.0

## [1.20.1] - 2026-09-07

### Fixed

- When `nowo-tech/slide-to-confirm-bundle` is not installed, register no-op Twig stubs for `nowo_slide_to_confirm_asset_path` and `nowo_slide_to_confirm_asset_package` so AuthKit templates that reference those helpers still compile.

### Notes

- **No configuration changes.** Optional SlideToConfirm integration behaviour is unchanged when the package is installed.

[1.20.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.20.1

## [1.20.0] - 2026-08-24

### Added

- Optional **OTP input** integration with `nowo-tech/otp-input-bundle` (`suggest` + `require-dev` only — no hard Composer dependency):
  - Profile keys `otp_input.enabled` (default `false`) and `otp_input.password_reset_code` (default `true`).
  - Password-reset code field uses `OtpType` (length/charset from `password_reset`) when enabled **and** the package is installed; otherwise the existing `TextType` remains.
  - Layout partial `_otp_input_assets.html.twig` loads `otp-input.js` (`nowo_otp_input` asset package) only when enabled **and** the package is present.
  - Global/function `nowo_auth_kit_otp_input_assets`. The widget is UX only; server OTP checks (`hash_equals`, `max_code_attempts`) stay mandatory.

### Changed

- Spec Kit baseline inventory **151/151** (`123` PHP + `28` Resources), including OTP-input units.

### Security

- REQ-SEC-004 re-audit of the 1.20.0 OTP-input delta: overall **Pass (conditional)** / **Medium**; this delta **Low** (no new Critical/High/Medium). The widget is UX only. `hash_equals` and `max_code_attempts` are unchanged. Login forms are unchanged.

### Notes

- No application change unless you install the optional package and set `otp_input.enabled: true`. Login forms are unchanged.

[1.20.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.20.0

## [1.19.0] - 2026-08-24

### Added

- Optional **device intelligence** integration with `nowo-tech/device-intelligence-bundle` (`suggest` only — **not** `require` / `require-dev`; that package needs PHP 8.3+ while AuthKit CI stays on 8.2):
  - Profile keys `device_intelligence.enabled` (default `false`), `collect_on_auth_pages` (default `true`), `collect_endpoint` (default `/_device/collect`), `new_device_notify` (default `false`), `device_rate_limit` (default `false`), `qr_login.approve_require_trusted` (default `false`).
  - Layout partial `_device_intelligence_assets.html.twig` calls `collect()` when enabled **and** Device Intelligence is installed.
  - After `LoginSuccess`, optional session flag `nowo_auth_kit.new_device` + `NewDeviceLoginNotifierInterface` (default no-op) when the observed cluster is new. Does **not** auto-`trust()`.
  - Extra `AuthKitAttemptLimiter` consume keyed by device ULID on register / password-reset request / magic-login request when `device_rate_limit` is true. Missing observation is a no-op.
  - QR approve: `DeviceIntelligenceQrLoginStepUp` decorates `QrLoginStepUpInterface`; when `approve_require_trusted` is true and the bundle is present, requires `_device` + `isTrusted()`. Default `NullQrLoginStepUp` is skipped so it does not throw; a custom inner still runs. Device ID is **not** a credential.

### Changed

- Spec Kit baseline inventory **149/149** (`122` PHP + `27` Resources), including device-intelligence units.

### Security

- REQ-SEC-004 re-audit of the 1.19.0 HTTP surface: overall **Pass (conditional)** / **Medium**; this delta **Low** (no new Critical/High). Device ID is not a credential. Login forms, CSRF, LoginThrottle, and remember-me are unchanged. AuthKit never auto-`trust()`s after login.

### Notes

- No application change unless you install the optional package (PHP 8.3+) and set `device_intelligence.enabled: true`. Login forms and remember-me are unchanged.

[1.19.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.19.0

## [1.18.0] - 2026-08-24

### Added

- Optional **slide-to-confirm** integration with `nowo-tech/slide-to-confirm-bundle` (`suggest` + `require-dev` only — no hard Composer dependency):
  - Profile keys `slide_to_confirm.enabled` (default `false`), `registration_consent` (default `gate`), `qr_login_approve` (default `false`).
  - Registration fields: `slide_to_confirm` (`true` or a profile name) and `mapped` (defaults to `false` when a slide is requested). `UserRegistrar` does not persist unmapped fields.
  - QR approve: `QrLoginApproveType` with Form CSRF + `SwipeToSubmitType` when enabled and the package is installed.
  - Twig partials `_slide_to_confirm_assets.html.twig` and `_registration_submit.html.twig`; global/function `nowo_auth_kit_slide_to_confirm_assets`.
  - Translations: `register.field.terms`, `register.slide.*`, `qr_login.approve.slide` / `confirmed` / `slide_hint`.

### Changed

- Spec Kit baseline inventory **142/142** (`116` PHP + `26` Resources), including `LoginThrottleRequiredPass` (shipped in 1.17.4) and slide-to-confirm units.

### Security

- REQ-SEC-004 re-audit of the 1.18.0 HTTP surface: overall **Pass (conditional)** / **Medium**; this delta **Low** (no new Critical/High). QR approve with slide uses Form CSRF. The swipe is confirmation UX, not authorization. Login forms are unchanged.

### Notes

- No application change unless you install the optional package and set `slide_to_confirm.enabled: true`.

[1.18.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.18.0

## [1.17.5] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.17.5]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.17.5

## [1.17.4] - 2026-08-20

### Security

- **`login_throttle_required`:** when `true`, container compilation fails if `nowo-tech/login-throttle-bundle` is not registered. Flex recipe `when@prod` enables the flag. Default remains `false` so existing apps that skip the recipe do not break.

[1.17.4]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.17.4

## [1.17.3] - 2026-08-19

### Changed

- **Flex recipe:** requires `nowo-tech/login-throttle-bundle` ^3.1, enables `NowoLoginThrottleBundle`, copies `nowo_login_throttle.yaml`.
- **INSTALLATION.md / SECURITY.md:** login throttling is **required in production** (was optional); post-install runs `nowo:login-throttle:configure-security`.

[1.17.3]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.17.3

## [1.17.2] - 2026-08-18

### Documentation

- **Specs:** baseline inventory **137/137** (`src/` PHP + Resources) and user stories for magic login, QR login, social/enterprise SSO, and the outbound-mail gate (product already shipped in 1.17.x).

[1.17.2]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.17.2

## [1.17.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.17.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.17.1

## [1.17.0] - 2026-08-12

### Changed
- **Magic login confirm interstitial** (closes [#9](https://github.com/nowo-tech/AuthKitBundle/issues/9)): `MagicLoginConfirmType` with Form CSRF; GET `magic_login_check` only; POST `magic_login_confirm` validates CSRF, consumes the login link, then `Security::login(..., 'login_link')`. Stock confirm Twig uses `form_start` / `form_end` (REQ-TWIG-005; no plain `<form`).
- `nowo:auth-kit:configure-security` adds public `access_control` for `magic_login_confirm` when `confirm_interstitial` is enabled.

### Documentation
- MAGIC-LOGIN / CONFIGURATION / UPGRADING: confirm Form CSRF flow.

[1.17.0]: https://github.com/nowo-tech/AuthKitBundle/compare/v1.16.0...v1.17.0

## [1.16.0] - 2026-08-10

### Added
- **`magic_login.confirm_interstitial`**: when `true`, `magic_login_check` accepts GET+POST; GET renders a confirm interstitial (`MagicLoginConfirmController` + `templates.magic_login_confirm`) for firewalls with `login_link.check_post_only`. POST remains handled by Symfony `login_link`.
- Template `@NowoAuthKitBundle/security/magic_login_confirm.html.twig` and translation keys `magic_login.confirm.*`.
- `nowo:auth-kit:configure-security` sets `check_post_only: true` when `confirm_interstitial` is enabled.

### Documentation
- MAGIC-LOGIN / CONFIGURATION / UPGRADING: confirm interstitial + `check_post_only` pairing.

## [1.15.0] - 2026-08-05

### Security

- Encrypt OAuth secrets at rest with [`nowo-tech/doctrine-encrypt-bundle`](https://github.com/nowo-tech/DoctrineEncryptBundle): `SocialLoginCredential::$clientSecret` and `SocialLoginAccount::$accessToken` / `$refreshToken` use `#[Encrypted]`. Hosts must configure Halite (same as other encrypted fields). Existing plaintext rows decrypt as-is until the next flush or `doctrine:encrypt:database`.

### Changed

- Composer: require `nowo-tech/doctrine-encrypt-bundle` **`^2.3`**.

[1.16.0]: https://github.com/nowo-tech/AuthKitBundle/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.15.0

## [1.14.0] - 2026-08-05

### Changed

- Login, registration, magic-login, and password-reset FormKit fields pass `help: false` and `placeholder: false` so missing convention keys are not rendered as raw help/placeholder text.
- Default FormKit profile seed (`auth_kit`) sets `auto_help: false` and `auto_placeholder: false` when the host has not defined the profile. Hosts that already define `auth_kit` should set those flags (or keep the per-field `false` overrides).
- Composer: require `nowo-tech/form-kit-bundle` **`^2.2`**.

[1.14.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.14.0

## [1.13.1] - 2026-08-04

### Fixed

- Form service discovery: keep `PasswordFieldTypeResolver` / `PasswordRepeatedFieldBuilder` / `PasswordFieldConstraintResolver` as explicit services (not double-tagged via `Form\` resource) and exclude `Form/DataTransformer/` from the `form.type` resource pattern.

[1.13.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.13.1

## [1.13.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Auth form types use `FormOptionsTrait` + profile `auth_kit` (`#[FormKitConfig]`). Extension prepends that profile (and default `css_framework: bootstrap`) when the host has not defined them; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added

- Unit coverage for `NowoAuthKitExtension::prependFormKitDefaults` (auth_kit profile seed + host override guards).
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

[1.13.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.13.0

## [1.12.2] - 2026-08-01

### Changed

- Restore CHANGELOG TOC entry for 1.12.1; enable `ext-gd` in the PHP Docker image so Endroid QR PNG path is covered in CI; add `QrCodeGeneratorPass` unit test.

[1.12.2]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.12.2

## [1.12.1] - 2026-07-31

### Added

- Optional **Endroid** QR image generator (`EndroidQrCodeGenerator`) when `endroid/qr-code` is installed (PNG with `ext-gd`, otherwise SVG); compiler pass aliases `QrCodeGeneratorInterface` over Null.

## [1.12.0] - 2026-07-31

### Added

- **QR phone login (P0):** opt-in `qr_login` profile config, `auth_kit_qr_login_challenge` entity, start/show/status/approve/deny/complete flow, desktop binding cookie, rate limits, login-page link, events, and tests. See [QR-LOGIN.md](QR-LOGIN.md).
- **Enterprise SSO (OIDC):** `SocialLoginCredential::enterpriseSso` flag splits organization IdP buttons from consumer social login on the login page. See [SSO.md](SSO.md).
- **WebAuthn design:** [WEBAUTHN.md](WEBAUTHN.md) locks the planned passkey surface (runtime not shipped in 1.12.0).

### Changed

- Login template exposes `qr_login_*` and `sso_login_*` variables alongside existing social login affordances.

## [1.11.4] - 2026-07-30

### Fixed

- `AuthKitUiExtension`: use `AbstractExtension::getFunctions()` instead of `#[AsTwigFunction]` (Symfony forbids combining both).

## [1.11.3] - 2026-07-30

### Fixed

- Register `AuthKitUiExtension` as a Twig extension (`AbstractExtension` + `twig.extension`) so `nowo_auth_kit_button_class` / form theme globals apply. (Corrects empty `v1.11.2` tag.)

## [1.11.2] - 2026-07-30

### Fixed

- Register `AuthKitUiExtension` as a Twig extension (`AbstractExtension` + `twig.extension`) so `nowo_auth_kit_button_class` / form theme globals apply.

## [1.11.1] - 2026-07-30

### Fixed

- Security Twig: `{% form_theme … with nowo_auth_kit_form_themes %}` so list themes apply correctly.

## [1.11.0] - 2026-07-30

### Added

- `nowo_auth_kit_outbound_mail_ready()` Twig function plus `OutboundMailReadyCheckerInterface` and the default `AlwaysOutboundMailReadyChecker`.
- Profile-level UI config for `templates.form_theme`, `css.button_class`, and `css.secondary_button_class`.
- Minimal CSS tokens asset at `@NowoAuthKitBundle` package path `css/nowo-auth-kit.css`.

### Changed

- Default layout now exposes `auth_brand` and `auth_panel` blocks so hosts can brand the shared shell without overriding every security page.
- Full-page security templates now render headings in `auth_panel_heading`, append `auth_footer_extra`, honor configurable button classes/form themes, and hide password-reset or magic-login links when outbound mail is not ready.
- Bundle DI now prepends the `nowo_auth_kit` framework asset package (`/bundles/nowoauthkit`) for the default stylesheet.

[1.11.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.11.0

## [1.10.1] - 2026-07-30

### Fixed

- Restore `eraseCredentials()` on social-login test user stubs (Symfony 7.4 `UserInterface`) and skip Rector `RemoveEmptyClassMethodRector` for those files so SF 7.4 CI does not fatal.

[1.10.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-07-30

### Security

- Require IdP `email_verified` (or GitHub `verified`) before auto-linking or creating local users from social login (`social_login.require_verified_email`, default `true`).
- Built-in rate limits (PSR-6 `cache.app`): password-reset request, magic-login request, registration POST; OTP reset lockout via `password_reset.max_code_attempts`.
- Reject non-HTTPS / private / loopback custom OAuth endpoint URLs (SSRF hardening).
- `configure-security` adds `PUBLIC_ACCESS` for social login routes when mode is enabled; access_control patterns expand `{provider}` / `{id}`.
- `first_user_only` registration re-checks user count after flush and rolls back on race.
- Magic login no longer 500s when firewall `login_link` is missing (silent skip + warning log; anti-enumeration).

### Added

- `AuthKitAttemptLimiter`, `OAuthEndpointUrlValidator`, profile knobs `registration_rate_*`, `password_reset` / `magic_login` `request_rate_*`, `max_code_attempts`, `require_verified_email`.
- Translation key `register.flash_rate_limited` (all locales).

### Changed

- [QR-LOGIN.md](QR-LOGIN.md) design: hard gate on verified phone (`phone` + `phoneVerifiedAt`) clarified (still **in development** / not shipped).

[1.10.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.10.0

## [1.9.1] - 2026-07-30

### Added

- Design doc [QR-LOGIN.md](QR-LOGIN.md) for future **QR phone login** (status: **in development** — not shipped; no runtime API). Linked from README and INSTALLATION.

[1.9.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-07-30

### Added

- **Social login (OAuth):** profile `social_login` (`mode`, `create_user_if_missing`), Doctrine entities `SocialLoginCredential` / `SocialLoginAccount` (DB-stored app credentials + linked user tokens), routes `/login/social/{provider}` + `/check`, login Twig buttons when mode is enabled **and** enabled credentials exist. See [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md).
- Runtime dependency: `symfony/http-client` (^7.4 || ^8.0).

### Changed

- README documentation list: Social login under Additional; base Documentation order restored.

### Fixed

- `ProfileRegistry` initializes `byExactClass` before the constructor body (avoids uninitialized typed property access).

[1.9.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.9.0

## [1.8.2] - 2026-07-29

### Fixed

- Demo FrankenPHP entrypoint waits for `vendor/autoload_runtime.php` before starting workers so `make demo-smoke` works on a clean CI checkout (REQ-TEST-011).

[1.8.2]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.8.2

## [1.8.1] - 2026-07-29

### Fixed

- Root / demo Makefiles use `-include` for optional monorepo `update-deps` helpers so standalone GitHub Actions checkouts do not fail (REQ-MAKE-009).
- Compose binary detection falls back to `docker-compose` when the Compose V2 plugin is unavailable (REQ-MAKE-010).

[1.8.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.8.1

## [1.8.0] - 2026-07-28

### Added

- **`AuthEmbedOptions`** DTO for typed embed options; `create()` / `auth_kit_dropdown` accept `AuthEmbedOptions|array` (REQ-PHP-001).
- **`Psr\Clock\ClockInterface`** on `PasswordResetTokenManager` (REQ-DI-001); runtime dep `psr/clock`.
- **`make demo-smoke`** + `.github/workflows/demo-smoke.yml` (REQ-TEST-011).

### Changed

- Demo FrankenPHP image: `dunglas/frankenphp:1-php8.5-bookworm` (REQ-DEMO-010).
- `LoggingPasswordResetNotifier` / `LoggingMagicLoginNotifier` no longer log URLs, tokens, or OTP codes (REQ-OBS-001).
- README `## Documentation` base order restored; `GITHUB_CI` under Additional (REQ-DOCS-002).
- TOC on CHANGELOG, UPGRADING, GITHUB_CI, MAGIC-LOGIN, PASSWORD-RESET (REQ-DOCS-005).
- Spec Kit inventory: MagicLogin / Profile / locale units; last audited **2026-07-28** (REQ-SPECKIT-001 / REQ-SPECKIT-003).

[1.8.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.8.0

## [1.7.6] - 2026-07-27

### Added

- `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist` (REQ-SF-005).
- `make check-open-prs` / `.scripts/check-open-prs.sh` wired into `release-check` (REQ-REL-003).
- AI security audit record (Pass conditional) in [`SECURITY.md`](SECURITY.md) (REQ-SEC-004).

### Changed

- Registration auto-login migrates the session ID (session fixation hardening).
- Password-reset OTP verification uses `hash_equals` for stored hashes.
- `configure-security` and demo firewall enable logout CSRF; embed logout link passes `_csrf_token`.
- GitHub About Description / Website / Topics filled (REQ-DOCS-018).
- `require-dev` / suggest: `nowo-tech/password-strength-bundle` ^2.0.

[1.7.6]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.7.6

## [1.7.5] - 2026-07-23

### Added

- PHPStan FrankenPHP rules (`nowo-tech/phpstan-frankenphp` in require-dev; classic + worker rulesets) — REQ-CS-005.
- FrankenPHP Friendly Worker Mode banner in README — REQ-DOCS-017.
- Root Makefile `down-dev` target — REQ-MAKE-007.
- Demo Makefile targets (`restart`, `ensure-up`, `logs`, `test-coverage`, `release-check`, `update-deps`) and aggregator aliases — REQ-MAKE-003 / REQ-MAKE-008.

### Changed

- Demo `.gitignore` ignores `/.pnpm-store` (REQ-GITIGNORE-003).

[1.7.5]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.7.5

## [1.7.4] - 2026-07-22

### Fixed

- `sync-releases` workflow: stop running on tag push (avoids duplicate GitHub Releases racing with `release.yml`), dedupe existing releases by `tag_name`, paginate the Releases API, and tolerate `422` on create/update.

[1.7.4]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.7.4

## [1.7.3] - 2026-07-22

### Changed

- Demo FrankenPHP selects **worker** vs **classic** via `FRANKENPHP_MODE` (`.env` / Compose), not `APP_ENV`. Default: `worker`. Docs: [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).

[1.7.3]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.7.3

## [1.7.2] - 2026-07-22

### Changed

- Emitting a deprecation when legacy `default_locale` / `enabled_locales` / `locale_in_path` are set together with the nested `locale` node (nested values win).

### Added

- Unit coverage for `configure-security` dual `access_control` when `locale.in_path: both`.

[1.7.2]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.7.2

## [1.7.1] - 2026-07-21

### Changed

- Demo Symfony 8 uses `locale.in_path: both` + `unlocalized: redirect`, with welcome use-case cards and bare-path `access_control` for `/login`, `/register`, `/reset-password`, `/magic-login`.

[1.7.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.7.1

## [1.7.0] - 2026-07-21

### Added

- Nested **`locale`** config: `in_path` (`never` \| `always` \| `both`), `default`, `enabled`, `unlocalized` (`serve` \| `redirect`).
- Dual auth routes when `locale.in_path: both`: canonical `/{_locale}/…` plus bare `*_unlocalized` routes.
- `UnlocalizedLocaleRedirectController` for `unlocalized: redirect`.
- `accessControlPatterns()` + `configure-security` dual `access_control` entries for `both`.

### Changed

- Docs: [`CONFIGURATION.md`](CONFIGURATION.md) / [`USAGE.md`](USAGE.md) describe the locale node; legacy flat keys remain supported.

[1.7.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.7.0

## [1.6.1] - 2026-07-21

### Fixed

- `nowo:auth-kit:configure-security` writes required Symfony `login_link.signature_properties` (from `user_identifier_field`). Without this, enabling magic login fails container compilation on Symfony 8.

### Changed

- Login template flags for password reset / magic login go through `PasswordResetGate` / `MagicLoginGate` (same pattern as registration).
- Demo Symfony 8: use-case cards (password / reset / magic / register), session **delivery inbox** for reset and magic login without a mailer, and `signature_properties` on the demo firewall.
- Demo Makefile resolves `docker` via absolute path so a local `docker/` directory does not break `make` targets.
- Docs: [`MAGIC-LOGIN.md`](MAGIC-LOGIN.md) / [`UPGRADING.md`](UPGRADING.md) document `signature_properties`.

[1.6.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.6.1

## [1.6.0] - 2026-07-21

### Added

- **Passwordless magic login**: enter identifier → receive one-time signed URL → click to authenticate (Symfony `login_link`).
- Config `magic_login` (`mode`, `lifetime`, `max_uses`) per profile; routes `magic_login_request` / `magic_login_check`.
- `MagicLoginRequestHandler`, `MagicLoginGate`, `MagicLoginNotifierInterface` (+ null/logging samples), `MagicLoginRequestedEvent`.
- `nowo:auth-kit:configure-security` syncs firewall `login_link` and public `access_control` paths.
- Docs: [`MAGIC-LOGIN.md`](MAGIC-LOGIN.md); login template link when enabled.
- Demo Symfony 8 enables magic login with `DemoMagicLoginNotifier`.

[1.6.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.6.0

## [1.5.1] - 2026-07-16

### Added

- [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md) (Contributor Covenant) and link from [`CONTRIBUTING.md`](CONTRIBUTING.md) / [`README.md`](../README.md).
- [`docs/GITHUB_CI.md`](GITHUB_CI.md) — CI requirements for **REQ-GIT-001** (no Cursor co-author trailers).
- Git hygiene tooling: `.scripts/check-no-cursor-coauthor.sh`, `.scripts/strip-cursor-coauthor-from-history.sh`, `.githooks/commit-msg`, `.cursor/rules/01-git-commits.mdc`.
- CI job `git-hygiene` and Makefile targets `check-no-cursor-coauthor` / `strip-cursor-coauthor-from-history` (wired into `release-check`).

### Removed

- FrankenPHP demo for Symfony 7.4 (`demo/symfony7`, port `:8009`). Use `demo/symfony8` (`make -C demo up-symfony8`).

### Changed

- Demo docs (`README.md`, `demo/README.md`, `docs/DEMO-FRANKENPHP.md`, `docs/USAGE.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`) reference only the Symfony 8 demo.

[1.5.1]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-07-14

### Added

- **Named configuration profiles** under `nowo_auth_kit.profiles` for applications with multiple user entities (e.g. `App\Entity\User` and `App\Entity\Admin`).
- **`ProfileRegistry`** with O(1) class lookup and per-class resolution cache (inheritance supported).
- **`RequestProfileResolver`** — resolves the active profile from the `_auth_kit_profile` route default (set automatically per profile route).
- Each profile carries its own routes, templates, registration, password reset, embed, and firewall settings.

### Changed

- `RegistrationGate`, `UserRegistrar`, password-reset services, controllers, and form types resolve settings from the matching profile (by route context or entity class).
- Configuration YAML and Flex recipe migrated to the `profiles` layout (flat config still accepted and normalized to `profiles.default`).
- `AuthKitRouteLoader` registers routes for every configured profile with unique route names per profile.
- Legacy container parameters (`nowo_auth_kit.user_class`, routes, templates, etc.) reflect the **default profile** for backward compatibility.
- `docs/CONFIGURATION.md` and `docs/UPGRADING.md` document profiles and runtime resolution.

### Fixed

- Symfony DI: form types, `AuthKitRouteLocaleParameters`, and `PasswordResetNotifierInterface` remain correctly wired after the profiles refactor.

[1.5.0]: https://github.com/nowo-tech/AuthKitBundle/releases/tag/v1.5.0

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
