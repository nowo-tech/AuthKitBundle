# Upgrading

## Table of contents


- [From 1.17.4 to 1.17.5](#from-1174-to-1175)
- [Unreleased](#unreleased)
- [To 1.17.4](#to-1174)
- [To 1.17.3](#to-1173)
- [To 1.17.2](#to-1172)
- [To 1.17.1](#to-1171)
- [To 1.17.0](#to-1170)
- [To 1.16.0](#to-1160)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
- [To 1.12.2](#to-1122)
- [To 1.12.1](#to-1121)
- [To 1.12.0](#to-1120)
- [To 1.11.0](#to-1110)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [Social login (optional)](#social-login-optional)
- [To 1.8.2](#to-182)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
- [To 1.7.6](#to-176)
- [To 1.7.5](#to-175)
- [To 1.7.4](#to-174)
- [To 1.7.3](#to-173)
- [To 1.7.2](#to-172)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.4](#to-144)
- [To 1.4.3](#to-143)
- [To 1.4.2](#to-142)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
  - [Login template: register link](#login-template-register-link)
  - [Optional: new translations](#optional-new-translations)
- [To 1.3.0](#to-130)
  - [Optional: password strength](#optional-password-strength)
- [To 1.2.0](#to-120)
  - [Optional: remember me](#optional-remember-me)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
  - [Optional: password reset](#optional-password-reset)
  - [Optional: embedded auth dropdown](#optional-embedded-auth-dropdown)
  - [Optional: locale in URL paths](#optional-locale-in-url-paths)
  - [Demo users](#demo-users)
- [To 1.0.0](#to-100)
- [Future upgrades](#future-upgrades)

## Unreleased

## To 1.17.4

From **1.17.3** — Flex `when@prod` sets `nowo_auth_kit.login_throttle_required: true`. Container compilation **fails** if `NowoLoginThrottleBundle` is not registered.

1. New Flex installs already require `nowo-tech/login-throttle-bundle` ^3.1 (since **1.17.3**). Run:

```bash
composer require nowo-tech/login-throttle-bundle:^3.1
php bin/console nowo:login-throttle:configure-security
php bin/console cache:clear --env=prod
```

2. Existing apps that **do not** merge the updated recipe keep `login_throttle_required: false` (default) and still boot — install the throttle bundle anyway for production login forms.
3. Local demos without the throttle bundle may set `login_throttle_required: false` (not for production).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.3

From **1.17.2** — Flex recipe now requires Login Throttle Bundle for new installs. Apps installed before 1.17.3 are **not** broken, but production login forms should add:

```bash
composer require nowo-tech/login-throttle-bundle:^3.1
php bin/console nowo:login-throttle:configure-security
```

See [INSTALLATION.md](INSTALLATION.md) and [SECURITY.md](SECURITY.md).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.2

From **1.17.1** — no application upgrade steps. Spec Kit baseline inventory and user stories catch up with features already shipped in 1.17.x (QR login, social/enterprise SSO, magic login, outbound-mail gate).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.1

From **1.17.0** — No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.0

### Magic login confirm Form CSRF (from 1.16.x)

Breaking for hosts that override `magic_login_confirm` with a raw POST to `magic_login_check`:

- Confirm POST is now **`magic_login_confirm`** (`/magic-login/confirm` by default), not `magic_login_check`.
- Template must render `magic_login_confirm_form` via `form_start` / `form_end` (CSRF enabled). BC vars `action` / `params` remain available.
- Re-run `php bin/console nowo:auth-kit:configure-security` so `access_control` includes the confirm path.
- Wire `LoginLinkHandler` if your firewall is not `main` (same as `MagicLoginRequestHandler`):

```yaml
# config/services.yaml
Nowo\AuthKitBundle\Controller\MagicLoginConfirmController:
    arguments:
        $loginLinkHandler: '@security.authenticator.login_link_handler.<firewall>'
```

`login_link.check_post_only: true` remains required so the email GET does not authenticate.

## To 1.16.0

From **1.15.x** — no breaking changes. Optional: enable a confirm interstitial for `login_link.check_post_only` hosts:

```yaml
nowo_auth_kit:
    profiles:
        default:
            magic_login:
                mode: enabled
                confirm_interstitial: true
            templates:
                magic_login_confirm: '@NowoAuthKitBundle/security/magic_login_confirm.html.twig'
```

Then set `security.firewalls.<name>.login_link.check_post_only: true` (or re-run `php bin/console nowo:auth-kit:configure-security`). Hosts that previously decorated `AuthKitRouteLoader` for GET+POST magic-login check can remove that decorator.

```bash
composer require nowo-tech/auth-kit-bundle:^1.16
php bin/console cache:clear
```

## To 1.13.1

From **1.13.0** — no host upgrade steps. Optional: `composer update nowo-tech/auth-kit-bundle` to pick up the Form service wiring fix.

## To 1.13.0

From **1.12.2** — adds required **FormKitBundle** and **Twig Extra** (REQ-TWIG-004).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

Composer pulls `nowo-tech/form-kit-bundle` `^2.0`, `twig/extra-bundle` `^3.12`, and `twig/string-extra` `^3.12`. Register if Flex did not:

```php
Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
```

Auth form types use profile `auth_kit` via `#[FormKitConfig]`. The bundle prepends that profile (and `css_framework: bootstrap` when unset) under `nowo_form_kit` when the host has not defined them. Host values are not overwritten.

**Maintainers:** `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php`.

No schema changes.

## To 1.12.2

From **1.12.1** — docs TOC fix + CI Docker `ext-gd` for Endroid PNG QR; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.12.1

Optional: `composer require endroid/qr-code:^6` so QR login show pages render an image (`EndroidQrCodeGenerator`). Without it, URL/`public_code` fallback remains. PNG needs `ext-gd`; otherwise SVG is used.

## To 1.12.0

From **1.11.x**.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
php bin/console doctrine:schema:update --force
# or add migrations for:
# - table auth_kit_qr_login_challenge
# - column auth_kit_social_credential.enterprise_sso
```

### Optional: QR phone login

Enable per profile (`qr_login.mode: enabled`) and ensure the user entity exposes configured `phone` / `phone_verified_field` properties. See [QR-LOGIN.md](QR-LOGIN.md). Add `PUBLIC_ACCESS` for `/login/qr` routes (and locale-prefixed variants).

### Optional: enterprise SSO buttons

Set `enterpriseSso: true` on OIDC credentials that should appear under **Sign in with your organization**. See [SSO.md](SSO.md).

### WebAuthn

Not implemented yet; see [WEBAUTHN.md](WEBAUTHN.md).

## To 1.11.0

From **1.10.1** / **1.10.0** or earlier 1.x minors with default templates.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

### Template structure

- Bundle layout now nests security content inside `auth_brand` and `auth_panel`.
- Bundle security pages (`login`, `register`, `reset_*`, `magic_login_request`) now override `auth_panel` instead of `body`.
- If your application override extends `@NowoAuthKitBundle/layout.html.twig` and customizes only the inner panel, rename:

```twig
{% block body %}
    {# old custom panel #}
{% endblock %}
```

to:

```twig
{% block auth_panel %}
    {# custom panel #}
{% endblock %}
```

Overriding the whole `body` block still works for full takeovers, but hosts that want to keep the shared shell should now prefer `auth_brand`, `auth_panel_heading`, and `auth_footer_extra`.

### Optional: outbound mail readiness

Password-reset and magic-login links on the default login page are now hidden unless `nowo_auth_kit_outbound_mail_ready()` returns `true`.

If your app needs an environment-aware check, register a service that implements `Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface` and point the bundle to it:

```yaml
nowo_auth_kit:
    outbound_mail_ready_checker: app.auth_kit.mail_ready_checker
```

### Optional: shared form themes and CSS classes

You can now configure the default full-page form theme list and button classes per profile:

```yaml
nowo_auth_kit:
    profiles:
        default:
            templates:
                form_theme:
                    - 'bootstrap_5_layout.html.twig'
                    - '@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'
            css:
                button_class: 'btn btn-primary w-100'
                secondary_button_class: 'btn btn-outline-secondary w-100'
```

The default layout also loads `{{ asset('css/nowo-auth-kit.css', 'nowo_auth_kit') }}` for minimal design tokens.

## To 1.10.1

From **1.10.0** — test/CI fix only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.10.0

From **1.9.1** — security hardening; defaults are safe. Clear cache after upgrade.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

### Behaviour changes (defaults)

- **Social login:** linking/creating a local user requires a verified email from the IdP (`require_verified_email: true`). Set `social_login.require_verified_email: false` only if you accept account-takeover risk.
- **Rate limits / OTP:** password-reset request, magic-login request, and registration use `cache.app` counters (defaults: 5 / 900s). OTP verification clears the reset credential after `max_code_attempts` (default 5). Set limits to `0` to disable.
- **Custom OAuth endpoints:** must be HTTPS and must not target localhost/private IPs.
- Re-run `php bin/console nowo:auth-kit:configure-security` if social login is enabled so `access_control` includes social routes.
- Ensure the app has a working `cache.app` pool (Symfony default is fine).

## To 1.9.1

From **1.9.0** — documentation only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Adds the [QR-LOGIN.md](QR-LOGIN.md) design (explicitly **in development** / not shipped).

## To 1.9.0

From **1.8.2** — backward compatible; social login is opt-in (default `mode: disabled`).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

New runtime dependency: `symfony/http-client` (^7.4 || ^8.0). No application config required if you leave social login disabled.

### Social login (optional)

1. Enable and migrate schema:

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                mode: enabled
                create_user_if_missing: true
```

```bash
php bin/console doctrine:schema:update --force
# or add a migration for auth_kit_social_credential + auth_kit_social_account
```

2. Insert at least one enabled `SocialLoginCredential` row (client id/secret). Buttons appear only then.
3. Allow public access to `/login/social` (and locale-prefixed variants). See [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md).

## To 1.8.2

From **1.8.1** — maintainer / demo / CI only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Demo `make up` / `demo-smoke` no longer fail when `vendor/` is missing at container start (FrankenPHP worker waits for Composer).

## To 1.8.1

From **1.8.0** — maintainer / CI only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Optional monorepo Makefile includes no longer break standalone CI checkouts; Compose V1 (`docker-compose`) is accepted as a fallback.

## To 1.8.0

From **1.7.6** — backward compatible for typical apps (DI / Flex).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

- New runtime dependency: `psr/clock` (^1.0).
- If you **manually** instantiate `PasswordResetTokenManager`, pass a `Psr\Clock\ClockInterface` as the fifth constructor argument (Symfony Clock is auto-wired when using the container).
- Embed API: prefer `AuthEmbedOptions`; `array` options still accepted for Twig/BC (`auth_kit_dropdown({…})`).
- Logging sample notifiers no longer include secrets in log context (URLs/tokens/codes).

## To 1.7.6

From **1.7.5** — backward compatible for apps; re-run security config if you use the CLI helper.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**Logout CSRF:** `nowo:auth-kit:configure-security` now writes `logout.enable_csrf: true`. Re-run with `--force` if your firewall was generated earlier, or add `enable_csrf: true` under `logout` manually. If you override the embed authenticated partial, include `_csrf_token` / `csrf_token('logout')` on the logout URL.

**Optional:** bump `nowo-tech/password-strength-bundle` to ^2.0 when integrating strength UI (Twig namespace / translation domain rename — see that package’s UPGRADING).

## To 1.7.5

From **1.7.4** — maintainer / demo / CI only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Package QA now includes FrankenPHP PHPStan rulesets; the README shows the worker-friendly banner. Demo Makefiles expose `restart` / `ensure-up` / `update-deps` / `release-check`; root `make down-dev` stops the bundle container without removing volumes. See [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).

## To 1.7.4

From **1.7.3** — CI/maintainers only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime or demo changes. GitHub release sync no longer runs on tag push (use **Create Release** for new tags; run **Sync Missing Releases** manually or wait for the daily schedule if a release is missing). See [`RELEASE.md`](RELEASE.md).

## To 1.7.3

From **1.7.2** — demo-only change; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

If you run the FrankenPHP demo: Caddyfile selection is now driven by **`FRANKENPHP_MODE=worker|classic`** (default `worker`), not by `APP_ENV`. Copy the new key from `.env.example`, then recreate the container (`docker compose up -d` / `make -C demo up-symfony8`). See [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).

## To 1.7.2

From **1.7.1** / **1.7.0** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

If you still set flat `default_locale` / `enabled_locales` / `locale_in_path` **and** a nested `locale` block, Symfony will emit a deprecation; keep only `locale` (nested values already took precedence).

## To 1.7.1

From **1.7.0** — demo-only patch; no application config changes required.

```bash
composer update nowo-tech/auth-kit-bundle
```

If you run the FrankenPHP demo, pull and restart: bare auth URLs (`/login`, …) now redirect to `/{locale}/…` (`locale.in_path: both`). See [`demo/README.md`](../demo/README.md).

## To 1.7.0

From **1.6.1** / **1.6.0** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**Locale routing** is now configured under `locale`:

```yaml
nowo_auth_kit:
    locale:
        in_path: always          # never | always | both
        default: en
        enabled: [en, es]
        unlocalized: redirect    # serve | redirect (only for both)
```

Legacy `default_locale`, `enabled_locales`, and `locale_in_path: true|false` still work (`true` ≡ `always`). Mixing those flat keys with a nested `locale` node triggers a deprecation; prefer only `locale`.

When using `in_path: both`, re-run:

```bash
php bin/console nowo:auth-kit:configure-security
```

so `access_control` covers both `/{locale}/…` and bare paths. Keep `form_login` on the canonical route names (not `*_unlocalized`).

## To 1.6.1

From **1.6.0** — backward-compatible patch.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**If magic login is enabled:** Symfony requires `login_link.signature_properties`. Re-run:

```bash
php bin/console nowo:auth-kit:configure-security
```

Or add manually (use your `user_identifier_field`, typically `email`):

```yaml
security:
    firewalls:
        main:
            login_link:
                check_route: nowo_auth_kit_magic_login_check
                signature_properties: [email]
                # …
```

No other application changes required. Demo-only: try reset / magic login via the session delivery inbox (no mailer).

## To 1.6.0

From **1.5.1** / **1.5.0** — backward compatible when magic login stays disabled (default).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**Optional — enable passwordless magic login:**

```yaml
nowo_auth_kit:
    magic_login:
        mode: enabled
        lifetime: 600
        max_uses: 1
```

```bash
php bin/console nowo:auth-kit:configure-security
```

Prefer **1.6.1+** so `configure-security` also writes `signature_properties` (required by Symfony). If you stay on 1.6.0 and configure `login_link` by hand, include e.g. `signature_properties: [email]`.

Implement `MagicLoginNotifierInterface` (email the `loginUrl`) and alias it in `services.yaml`. See [`MAGIC-LOGIN.md`](MAGIC-LOGIN.md).

No entity fields are required (links are signed by Symfony `login_link`).

## To 1.5.1

From **1.5.0** — backward compatible for application integrators.

```bash
composer update nowo-tech/auth-kit-bundle
```

No configuration or template changes required. Package runtime behavior is unchanged.

**Maintainer / demo notes:**

- The FrankenPHP **Symfony 7.4** demo (`demo/symfony7`, port `:8009`) was removed. Use `demo/symfony8` (`make -C demo up-symfony8`, port `:8010`).
- Contributors: run `make setup-hooks` and see [`GITHUB_CI.md`](GITHUB_CI.md) (**REQ-GIT-001** — no Cursor co-author trailers in commit messages).

## To 1.5.0

From **1.4.4**, **1.4.3**, **1.4.2**, **1.4.1**, **1.4.0**, or earlier 1.x — backward compatible for single-entity setups.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**No migration required** if you keep the flat configuration (`user_class` at root). It is normalized internally to a single `default` profile.

**What is new:**

- Multiple user entities can each have their own login, registration, password reset, routes, templates, and firewall under `nowo_auth_kit.profiles`.
- Routes are registered per profile; each route sets `_auth_kit_profile` so controllers resolve the correct settings automatically.
- `ProfileRegistry::resolveForObject($user)` resolves the profile from the authenticated entity class (cached O(1) lookup).
- Embed dropdown (`auth_kit_dropdown()`) uses the default profile unless you pass `profile` in Twig options.

**Optional migration to profiles layout:**

```yaml
nowo_auth_kit:
    default_profile: app_user
    profiles:
        app_user:
            user_class: App\Entity\User
            registration_mode: first_user_only
            routes:
                login:
                    path: /login
                    name: nowo_auth_kit_login
        admin:
            user_class: App\Entity\Admin
            registration_mode: disabled
            routes:
                login:
                    path: /admin/login
                    name: nowo_auth_kit_admin_login
```

**Behavior note:** each profile must define a unique `user_class` and unique route **names** across all profiles. Route paths may differ per profile.

## To 1.4.4

From **1.4.3** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
```

No configuration or template changes required. This release adds maintainer-only Spec Kit documentation and tooling (`specs/`, `.specify/`, `docs/SPEC-KIT.md`); integrator-facing behavior is unchanged.

## To 1.4.3

From **1.4.2** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No configuration changes required. Password field labels now use the AuthKit translation domain when `password_strength` is enabled.

## To 1.4.2

From **1.4.1** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No configuration changes required.

If you use `password_strength.enabled: true`, the confirmation field now validates match only (fix for double strength UI/validation). Custom form overrides are unaffected unless they duplicated the old `RepeatedType` + `PasswordStrengthType` pattern.

## To 1.4.1

From **1.4.0** — no code or configuration changes required.

```bash
composer update nowo-tech/auth-kit-bundle
```

This release fixes CI coverage checks and bumps GitHub Actions only.

## To 1.4.0

From **1.3.0** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No configuration changes required.

### Login template: register link

The bundle login template now uses `registration_allowed` instead of always showing the register link. If you override `security/login.html.twig`, wrap the register link:

```twig
{% if registration_allowed|default(false) %}
    <a href="{{ path(register_route, auth_kit_route_params()) }}">{{ 'login.register_link'|trans({}, 'NowoAuthKitBundle') }}</a>
{% endif %}
```

See [USAGE.md](USAGE.md#registration-link-on-login-page).

### Optional: new translations

Catalogues **de**, **fr**, **it**, **nl**, and **pt** ship with the bundle. Add locales to Symfony and `nowo_auth_kit.enabled_locales` as needed.

## To 1.3.0

From **1.2.0** — backward compatible; password strength is opt-in (`password_strength.enabled: false` by default).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No changes required unless you want strength policies on registration or password-reset fields.

### Optional: password strength

Requires `nowo-tech/password-strength-bundle` (not installed by default):

```bash
composer require nowo-tech/password-strength-bundle
php bin/console assets:install
```

```yaml
nowo_auth_kit:
    password_strength:
        enabled: true
        level: medium
        policy_mode: level
```

Include the client script in your layout:

```twig
<script src="{{ asset('bundles/passwordstrength/password-strength.js') }}" defer></script>
```

Policy details (`levels`, `form_theme`, live feedback) live in `nowo_password_strength.yaml`. See [CONFIGURATION.md](CONFIGURATION.md#password-strength-optional).

## To 1.2.0

From **1.1.1** — backward compatible; remember-me is opt-in (`remember_me.enabled: false` by default).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console nowo:auth-kit:configure-security --force
php bin/console cache:clear
```

Login forms post nested field names (`login_form[_username]`, not bare `_username`). The command above adds `username_parameter`, `password_parameter`, `csrf_parameter`, and `invalidate_session: true` on logout.

### Optional: remember me

Enable persistent login in `config/packages/nowo_auth_kit.yaml`:

```yaml
nowo_auth_kit:
    remember_me:
        enabled: true
        lifetime: 604800
        path: /
```

Then sync the firewall (remember-me is updated on every run; `--force` only needed to refresh `form_login`):

```bash
php bin/console nowo:auth-kit:configure-security
```

To **disable** remember-me, set `enabled: false` (and remove `remember_me` from `login_fields` if present), then re-run the command above — the `remember_me` firewall block is removed automatically.

See [CONFIGURATION.md](CONFIGURATION.md#remember-me).

## To 1.1.1

From **1.1.0** — no code or configuration changes required.

```bash
composer update nowo-tech/auth-kit-bundle
```

This release updates README screenshots, Composer package metadata URLs, and CI action versions only.

## To 1.1.0

From **1.0.0** — backward compatible; new features are opt-in via configuration defaults.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

### Optional: password reset

1. Add nullable token fields to your user entity (see [PASSWORD-RESET.md](PASSWORD-RESET.md)).
2. Enable in config:

   ```yaml
   nowo_auth_kit:
       password_reset:
           mode: enabled
           delivery: link   # or code | both
   ```

3. Implement and register `PasswordResetNotifierInterface`.
4. Run `php bin/console nowo:auth-kit:configure-security` to add public `access_control` paths.

### Optional: embedded auth dropdown

```yaml
nowo_auth_kit:
    embed:
        mode: dropdown
```

Render in Twig: `{{ auth_kit_dropdown() }}`. See [USAGE.md](USAGE.md#embedded-loginregister-dropdown).

### Optional: locale in URL paths

```yaml
nowo_auth_kit:
    locale_in_path: true
    default_locale: en
    enabled_locales: [en, es]
```

Re-run `php bin/console nowo:auth-kit:configure-security` so `access_control` patterns include `^/(en|es)/login`, etc.

Use `auth_kit_route_params()` in Twig for locale-aware links. See [USAGE.md](USAGE.md#locale-in-url-paths).

### Demo users

If you run the FrankenPHP demos, rebuild the PHP image after pulling (`docker compose build php`) and reset MySQL volumes if Doctrine warns about MySQL &lt; 8 (`docker compose down -v`).

## To 1.0.0

This is the first public release. Install via Composer and follow [INSTALLATION.md](INSTALLATION.md).

```bash
composer require nowo-tech/auth-kit-bundle
```

After Flex installs the recipe (or manual setup):

1. Configure `config/packages/nowo_auth_kit.yaml` (`user_class`, `registration_mode`, etc.).
2. Install password-field dependencies if not added by the recipe:

   ```bash
   composer require nowo-tech/password-toggle-bundle symfony/ux-icons symfony/http-client
   php bin/console ux:icons:lock
   ```

3. Run `php bin/console nowo:auth-kit:configure-security`.
4. Clear cache: `php bin/console cache:clear`.

## Future upgrades

When upgrading between versions:

1. Read [CHANGELOG.md](CHANGELOG.md) for breaking changes.
2. Run `composer update nowo-tech/auth-kit-bundle`.
3. Clear Symfony cache: `php bin/console cache:clear`.
4. Re-run `php bin/console nowo:auth-kit:configure-security` if route names or firewall settings changed.
5. Verify `config/packages/nowo_auth_kit.yaml` against [CONFIGURATION.md](CONFIGURATION.md).
6. If password toggle icons break after an upgrade, run `php bin/console ux:icons:lock` again.
