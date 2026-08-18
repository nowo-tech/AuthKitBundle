# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.17.1] - 2026-08-18](#1171-2026-08-18)
- [[1.17.0] - 2026-08-12](#1170-2026-08-12)
- [[1.16.0] - 2026-08-10](#1160-2026-08-10)
- [[1.15.0] - 2026-08-05](#1150-2026-08-05)
- [[1.14.0] - 2026-08-05](#1140-2026-08-05)
- [[1.13.1] - 2026-08-04](#1131-2026-08-04)
  - [Fixed](#fixed)
- [[1.13.0] - 2026-08-04](#1130-2026-08-04)
  - [Changed](#changed)
  - [Added](#added)
- [[1.12.2] - 2026-08-01](#1122-2026-08-01)
  - [Changed](#changed)
- [[1.12.1] - 2026-07-31](#1121-2026-07-31)
  - [Added](#added)
- [[1.12.0] - 2026-07-31](#1120-2026-07-31)
- [[1.11.4] - 2026-07-30](#1114-2026-07-30)
- [[1.11.3] - 2026-07-30](#1113-2026-07-30)
- [[1.11.2] - 2026-07-30](#1112-2026-07-30)
- [[1.11.1] - 2026-07-30](#1111-2026-07-30)
- [[1.11.0] - 2026-07-30](#1110-2026-07-30)
  - [Added](#added)
  - [Changed](#changed)
- [[1.10.1] - 2026-07-30](#1101-2026-07-30)
  - [Fixed](#fixed)
- [[1.10.0] - 2026-07-30](#1100-2026-07-30)
  - [Security](#security)
  - [Added](#added)
  - [Changed](#changed)
- [[1.9.1] - 2026-07-30](#191-2026-07-30)
  - [Added](#added)
- [[1.9.0] - 2026-07-30](#190-2026-07-30)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.8.2] - 2026-07-29](#182-2026-07-29)
  - [Fixed](#fixed)
- [[1.8.1] - 2026-07-29](#181-2026-07-29)
  - [Fixed](#fixed)
- [[1.8.0] - 2026-07-28](#180-2026-07-28)
  - [Added](#added)
  - [Changed](#changed)
- [[1.7.6] - 2026-07-27](#176-2026-07-27)
  - [Added](#added)
  - [Changed](#changed)
- [[1.7.5] - 2026-07-23](#175-2026-07-23)
  - [Added](#added)
  - [Changed](#changed)
- [[1.7.4] - 2026-07-22](#174-2026-07-22)
  - [Fixed](#fixed)
- [[1.7.3] - 2026-07-22](#173-2026-07-22)
  - [Changed](#changed)
- [[1.7.2] - 2026-07-22](#172-2026-07-22)
  - [Changed](#changed)
  - [Added](#added)
- [[1.7.1] - 2026-07-21](#171-2026-07-21)
  - [Changed](#changed)
- [[1.7.0] - 2026-07-21](#170-2026-07-21)
  - [Added](#added)
  - [Changed](#changed)
- [[1.6.1] - 2026-07-21](#161-2026-07-21)
  - [Fixed](#fixed)
  - [Changed](#changed)
- [[1.6.0] - 2026-07-21](#160-2026-07-21)
  - [Added](#added)
- [[1.5.1] - 2026-07-16](#151-2026-07-16)
  - [Added](#added)
  - [Removed](#removed)
  - [Changed](#changed)
- [[1.5.0] - 2026-07-14](#150-2026-07-14)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.4.4] - 2026-07-14](#144-2026-07-14)
  - [Added](#added)
  - [Changed](#changed)
- [[1.4.3] - 2026-07-06](#143-2026-07-06)
  - [Fixed](#fixed)
- [[1.4.2] - 2026-07-05](#142-2026-07-05)
  - [Added](#added)
  - [Fixed](#fixed)
- [[1.4.1] - 2026-07-05](#141-2026-07-05)
  - [Fixed](#fixed)
  - [Changed](#changed)
- [[1.4.0] - 2026-07-05](#140-2026-07-05)
  - [Added](#added)
  - [Changed](#changed)
- [[1.3.0] - 2026-07-03](#130-2026-07-03)
  - [Added](#added)
  - [Changed](#changed)
- [[1.2.0] - 2026-07-03](#120-2026-07-03)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.1.1] - 2026-06-18](#111-2026-06-18)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.1.0] - 2026-06-18](#110-2026-06-18)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.0.0] - 2026-06-18](#100-2026-06-18)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
  - [Removed](#removed)

## [Unreleased]

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
