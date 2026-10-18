# Configuration

## Table of contents

- [Reference](#reference)
- [Registration modes](#registration-modes)
- [Field configuration](#field-configuration)
  - [Login](#login)
  - [Registration](#registration)
  - [Password strength (optional)](#password-strength-optional)
  - [Slide to confirm (optional)](#slide-to-confirm-optional)
  - [Device intelligence (optional)](#device-intelligence-optional)
  - [OTP input (optional)](#otp-input-optional)
- [Password reset](#password-reset)
- [Magic login (passwordless)](#magic-login-passwordless)
- [Social login (OAuth)](#social-login-oauth)
- [Embedded auth UI](#embedded-auth-ui)
- [Locale in paths](#locale-in-paths)
- [Recipient locale (notifications)](#recipient-locale-notifications)
- [Absolute URL base (mailed / QR links)](#absolute-url-base-mailed--qr-links)
- [Flash messages](#flash-messages)
- [Admin CLI (create-admin)](#admin-cli-create-admin)
- [Templates](#templates)
- [Routes](#routes)
- [Security.yaml checklist](#securityyaml-checklist)

All options live under the `nowo_auth_kit` root key in `config/packages/nowo_auth_kit.yaml`.

The config can be split across several blocks (`config/packages/<env>/nowo_auth_kit.yaml`, `when@<env>:`): Symfony merges them in load order and a later block only overrides the keys it sets. `default_profile` (first profile key when unset) and the deprecated `default_locale` / `enabled_locales` / `locale_in_path` mirrors are derived from the merged result.

## Profiles

Each **profile** maps a `user_class` to its own auth settings (registration, login fields, password reset, routes, templates, firewall, embed). Use multiple profiles when the application has more than one authenticated user entity (for example `App\Entity\User` and `App\Entity\Admin`).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | first profile key | Profile used when no route context or explicit name is available. |
| `profiles` | map | `default` | Named profile definitions (at least one required). |
| `outbound_mail_ready_checker` | string\|null | `null` | Optional service id implementing `OutboundMailReadyCheckerInterface` for UI gating. |
| `locale` | map | see below | Preferred locale / path configuration. |
| `default_locale` | string | `en` | **Deprecated** — use `locale.default`. |
| `enabled_locales` | list | `[en, es]` | **Deprecated** — use `locale.enabled`. |
| `locale_in_path` | bool\|string | `false` | **Deprecated** — use `locale.in_path` (`never`\|`always`\|`both`). Bool `true` ≡ `always`. |

Each profile supports all keys documented below (`user_class`, `registration_mode`, `routes`, `templates`, etc.). Every profile must use a **unique** `user_class` and **unique route names** across profiles.

Example with two profiles:

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
            firewall: admin
            routes:
                login:
                    path: /admin/login
                    name: nowo_auth_kit_admin_login
```

Routes set `_auth_kit_profile` automatically so controllers, forms, and services resolve the correct profile per request.

### Resolving profiles at runtime

- **HTTP requests:** resolved from the matched route (`RequestProfileResolver`).
- **Entity objects:** `ProfileRegistry::resolveForObject($user)` (O(1) lookup, inheritance supported).
- **Explicit name:** pass a profile name to `RegistrationGate::isRegistrationAllowed('admin')`, `UserRegistrar::register($data, 'admin')`, or embed Twig options `auth_kit_dropdown({ profile: 'admin' })`.

## Legacy flat configuration

The previous flat layout remains supported and is normalized internally to `profiles.default`:

```yaml
nowo_auth_kit:
    user_class: App\Entity\User
    registration_mode: first_user_only
```

## Reference (per profile)

```yaml
nowo_auth_kit:
    # Required: FQCN of your user entity
    user_class: App\Entity\User

    # Entity property used as Symfony user identifier (form_login username)
    user_identifier_field: email

    # Role stored on registration (entity should expose setRoles() or writable roles property)
    registration_role: ROLE_USER

    # Registration policy:
    #   disabled        — register route redirects to login
    #   first_user_only — allowed only while user table is empty (bootstrap admin)
    #   always          — open self-service registration
    registration_mode: first_user_only

    # Login form fields (identifier maps to user_identifier_field)
    login_fields:
        - identifier
        - password
        # - remember_me

    # Persistent login cookie (requires matching firewall remember_me — use configure-security)
    remember_me:
        enabled: false
        lifetime: 604800
        path: /

    # Optional: nowo-tech/password-strength-bundle for registration/reset password fields
    password_strength:
        enabled: false
        level: medium
        policy_mode: level

    # Optional: nowo-tech/slide-to-confirm-bundle (registration consent + QR approve)
    slide_to_confirm:
        enabled: false
        registration_consent: gate    # profile when a field sets slide_to_confirm: true
        qr_login_approve: false       # danger | gate | … or false

    # Optional: nowo-tech/device-intelligence-bundle (PHP 8.3+; Device ID is not a credential)
    device_intelligence:
        enabled: false
        collect_on_auth_pages: true
        collect_endpoint: /_device/collect
        new_device_notify: false
        device_rate_limit: false
        qr_login:
            approve_require_trusted: false

    # Optional: nowo-tech/otp-input-bundle (password-reset code field; UX only)
    otp_input:
        enabled: false
        password_reset_code: true

    # Registration form fields (string shorthand or expanded config)
    registration_fields:
        - email
        - password
        # name:
        #     type: text
        #     property: fullName

    # Page contract (REQ-UI-003): the auth pages extend @NowoUiKit/page/auth on the UiKit guest layout.
    web_ui:
        layout_template: null       # null = nowo_ui_kit.layouts.guest (layout rendering the UiKit canonical blocks)
        templates:                  # per-page overrides (thin extends of @!NowoAuthKit/security/<page>.html.twig)
            login: '@NowoAuthKitBundle/security/login.html.twig'
            register: '@NowoAuthKitBundle/security/register.html.twig'
            reset_request: '@NowoAuthKitBundle/security/reset_request.html.twig'
            reset_password: '@NowoAuthKitBundle/security/reset_password.html.twig'
            reset_password_code: '@NowoAuthKitBundle/security/reset_password_code.html.twig'
            magic_login_request: '@NowoAuthKitBundle/security/magic_login_request.html.twig'
            magic_login_confirm: '@NowoAuthKitBundle/security/magic_login_confirm.html.twig'
            qr_login_show: '@NowoAuthKitBundle/security/qr_login_show.html.twig'
            qr_login_approve: '@NowoAuthKitBundle/security/qr_login_approve.html.twig'
        form_themes: null           # null = UiKit form theme (+ AuthKit PasswordToggle theme when that bundle is registered)
        brand: null                 # plain-text brand line above the panel heading (AuthPage::brand)
        qr_login:
            stimulus_controller: null   # e.g. qr-login: Stimulus values/targets on the QR challenge, no bundled poller

    # Deprecated since 1.27 (mapped to web_ui / the UiKit theme; see UPGRADING "Template contract (1.27)")
    # templates: { layout, login, register, …, form_theme }
    # css: { button_class, secondary_button_class }

    embed:
        mode: disabled              # disabled | dropdown
        show_login: true
        show_register: true
        template: '@NowoAuthKitBundle/embed/dropdown.html.twig'
        login_panel: '@NowoAuthKitBundle/embed/_login_panel.html.twig'
        register_panel: '@NowoAuthKitBundle/embed/_register_panel.html.twig'
        authenticated: '@NowoAuthKitBundle/embed/_authenticated.html.twig'

    password_reset:
        mode: disabled              # disabled | enabled
        delivery: link              # link | code | both
        token_ttl: 3600
        max_code_attempts: 5        # 0 = disabled; clears reset credential when exceeded
        request_rate_limit: 5       # 0 = disabled
        request_rate_window: 900
        token_field: passwordResetToken
        token_expires_field: passwordResetExpiresAt

    magic_login:
        mode: disabled              # disabled | enabled
        lifetime: 600
        max_uses: 1
        request_rate_limit: 5       # 0 = disabled
        request_rate_window: 900
        confirm_interstitial: false # true + login_link.check_post_only → GET confirm form

    social_login:
        mode: disabled              # disabled | enabled
        create_user_if_missing: true
        require_verified_email: true
        pkce: {}                    # provider → bool; unset = on for google/github/microsoft, off for custom IdPs

    registration_rate_limit: 5      # 0 = disabled
    registration_rate_window: 900

    routes:
        login:
            path: /login
            name: nowo_auth_kit_login
        logout:
            path: /logout
            name: nowo_auth_kit_logout
        register:
            path: /register
            name: nowo_auth_kit_register
        reset_request:
            path: /reset-password
            name: nowo_auth_kit_reset_password_request
        reset_password:
            path: /reset-password/reset/{token}
            name: nowo_auth_kit_reset_password
        reset_password_code:
            path: /reset-password/complete
            name: nowo_auth_kit_reset_password_code
        magic_login_request:
            path: /magic-login
            name: nowo_auth_kit_magic_login_request
        magic_login_check:
            path: /magic-login/check
            name: nowo_auth_kit_magic_login_check
        magic_login_confirm:
            path: /magic-login/confirm
            name: nowo_auth_kit_magic_login_confirm
        social_login_start:
            path: /login/social/{provider}
            name: nowo_auth_kit_social_login_start
        social_login_check:
            path: /login/social/{provider}/check
            name: nowo_auth_kit_social_login_check

    # Documented for security.yaml (see INSTALLATION.md)
    firewall: main
    login_success_route: null   # route name after login/register; null uses firewall default

    outbound_mail_ready_checker: null   # optional service id

    locale:
        in_path: never          # never | always | both
        default: en
        enabled: [en, es]
        unlocalized: redirect   # serve | redirect (only when in_path: both)
```

## Registration modes

| Mode | Behaviour |
|------|-----------|
| `disabled` | `/register` redirects to login; no new users via bundle |
| `first_user_only` | Registration works until the first user exists |
| `always` | Registration always available to guests |

## Field configuration

### Login

Supported tokens: `identifier`, `password`, `remember_me`.

AuthKit renders login fields through Symfony Form with block prefix `login_form`, so POST keys are nested (`login_form[_username]`, not bare `_username`). Configure `security.yaml` accordingly, or run:

```bash
php bin/console nowo:auth-kit:configure-security --force
```

See `Nowo\AuthKitBundle\Security\AuthKitFormLoginParameters` for the exact parameter names.

### Remember me

Enable the checkbox and persistent cookie in one place:

```yaml
nowo_auth_kit:
    remember_me:
        enabled: true      # adds remember_me to login_fields and firewall remember_me
        lifetime: 604800   # 7 days
        path: /
```

Alternatively, add `remember_me` to `login_fields` only — the configure-security command will still add the firewall block when it detects that field.

`nowo:auth-kit:configure-security` **always** adds or removes the firewall `remember_me` block to match bundle config, even when `form_login` is left unchanged (no `--force`). Use `--force` only to refresh `form_login` and logout settings.

Manual `security.yaml` snippet:

```yaml
form_login:
    username_parameter: login_form[_username]
    password_parameter: login_form[_password]
    csrf_parameter: login_form[_csrf_token]
    csrf_token_id: authenticate
remember_me:
    secret: '%kernel.secret%'
    lifetime: 604800
    path: /
    remember_me_parameter: login_form[_remember_me]
```

### Registration

Each field can be:

- a string (property name equals field name), or
- an array with `name`, `type` (`text`, `email`, `password`, `checkbox`), `property`, `hash` (default `true` for password), `required`, `mapped` (default `true`; `false` when `slide_to_confirm` is set), `slide_to_confirm` (`true` uses `slide_to_confirm.registration_consent`, or a profile name such as `gate` / `legal`).

Password fields use `RepeatedType` with minimum length validation. When `nowo-tech/password-toggle-bundle` is present, the toggle `PasswordType` is used; otherwise Symfony’s default `PasswordType` is used (no hard dependency in the bundle library).

When `password_strength.enabled` is `true` and `nowo-tech/password-strength-bundle` is installed, the primary password field uses `PasswordStrengthType` with the `PasswordStrength` validator. The confirmation field uses the basic toggle/Symfony password type and validates match only (not strength twice). Login fields are unchanged.

### Password strength (optional)

```yaml
nowo_auth_kit:
    password_strength:
        enabled: true
        level: medium        # weak | medium | strong | custom (see PasswordStrengthBundle)
        policy_mode: level   # level | conditions
```

Install the bundle and include its client script in your layout:

```bash
composer require nowo-tech/password-strength-bundle
php bin/console assets:install
```

```twig
<script src="{{ asset('bundles/passwordstrength/password-strength.js') }}" defer></script>
```

Policy details (`levels`, `form_theme`, live feedback) are configured in `nowo_password_strength.yaml` — see [PasswordStrengthBundle](https://github.com/nowo-tech/PasswordStrengthBundle). When both PasswordStrength and PasswordToggle bundles are installed, strength fields automatically use the toggle parent.

### Slide to confirm (optional)

```yaml
nowo_auth_kit:
    slide_to_confirm:
        enabled: true
        registration_consent: gate   # unlocks the Register button
        qr_login_approve: danger     # the slide is the QR approve submit

    registration_fields:
        - email
        - password
        - terms:
            type: checkbox
            required: true
            slide_to_confirm: true   # or legal / gate
```

```bash
composer require nowo-tech/slide-to-confirm-bundle
php bin/console assets:install
```

The bundle is **optional**. If it is not installed, AuthKit keeps a normal checkbox / QR submit button. The default layout loads CSS/JS only when `slide_to_confirm.enabled` is true **and** the package is present.

- **Registration:** `gate` keeps the Register button (disabled until the slide completes). `legal` (and other auto-submit profiles) hide the extra submit — finishing the slide posts the form. Unmapped consent fields are not written to the user entity.
- **QR approve:** `qr_login_approve: danger` replaces the Approve button with `SwipeToSubmitType`. CSRF is enforced on that form. Step-up (`QrLoginStepUpInterface`) still runs after a valid slide.
- The gesture is **confirmation UX**, not authorization. Keep CSRF, authentication, and server checks.

See [SlideToConfirmBundle](https://github.com/nowo-tech/SlideToConfirmBundle).

### Device intelligence (optional)

```yaml
nowo_auth_kit:
    device_intelligence:
        enabled: true
        collect_on_auth_pages: true
        collect_endpoint: /_device/collect
        new_device_notify: true
        device_rate_limit: true
        qr_login:
            approve_require_trusted: true
```

```bash
composer require nowo-tech/device-intelligence-bundle
php bin/console assets:install
```

The bundle is **optional** (`composer suggest` only). It needs **PHP 8.3+**; AuthKit itself stays on PHP 8.2. If it is not installed, or `enabled` is false, AuthKit behaviour is unchanged. Device ID is **not** a credential: it never authenticates a user, never replaces LoginThrottle / CSRF / remember-me, and AuthKit never auto-`trust()`s a device after login.

| Key | Default | Effect when `enabled` is true **and** Device Intelligence is installed |
| --- | --- | --- |
| `collect_on_auth_pages` | `true` | Layout loads `device-intelligence.min.js` and calls `collect()` so `_device` is present on the next POST |
| `collect_endpoint` | `/_device/collect` | POST path expected by that bundle (Flex recipe registers the route) |
| `new_device_notify` | `false` | After `LoginSuccess`, sets session `nowo_auth_kit.new_device` and calls `NewDeviceLoginNotifierInterface` when `isNew()` |
| `device_rate_limit` | `false` | Extra `AuthKitAttemptLimiter` consume keyed by device ULID on register / reset request / magic-login request. Missing observation is a no-op (first HTML hit is not locked out) |

**Rate-limit atomicity:** `AuthKitAttemptLimiter` (registration / reset / magic-login / QR / device limits, OTP lockout) stores counters in `cache.app`, which has no atomic increment. When `symfony/lock` is installed (FrameworkBundle then registers `lock.factory`), the check-and-increment runs under a per-key lock so concurrent requests cannot overshoot the limit. Without it the counter is best-effort (parallel requests can slip a few attempts past the limit). On several hosts, point `framework.lock` at a shared store (Redis, PDO, …); the default `flock` store only serializes one host.
| `qr_login.approve_require_trusted` | `false` | QR `session_step_up` requires an observed **trusted** device. Default `NullQrLoginStepUp` is skipped so it does not throw; a custom `QrLoginStepUpInterface` still runs after the trusted-device check |

Alias `NewDeviceLoginNotifierInterface` to your mailer/SMS service if you enable `new_device_notify` (default: `NullNewDeviceLoginNotifier`).

See [DeviceIntelligenceBundle](https://github.com/nowo-tech/DeviceIntelligenceBundle) and [QR-LOGIN.md](QR-LOGIN.md#device-intelligence-optional).

### OTP input (optional)

```yaml
nowo_auth_kit:
    otp_input:
        enabled: true
        password_reset_code: true
```

```bash
composer require nowo-tech/otp-input-bundle
php bin/console assets:install
```

The bundle is **optional**. If it is not installed, or `enabled` is false, AuthKit keeps a single `TextType` on the password-reset code field. The default layout loads `otp-input.js` only when `otp_input.enabled` is true, `password_reset_code` is true, **and** the package is present.

| Key | Default | Effect when `enabled` is true **and** OTP Input is installed |
| --- | --- | --- |
| `password_reset_code` | `true` | Replaces the reset OTP `TextType` with `OtpType`. `length` comes from `password_reset.code_length`; `numeric_only` / `uppercase` follow `password_reset.code_charset` |

The widget is **UX only**. Server verification (`hash_equals`, `max_code_attempts`) stays mandatory. Login, register, magic-login confirm, and QR `phone_otp` are unchanged.

See [OTPInputBundle](https://github.com/nowo-tech/OTPInputBundle) and [PASSWORD-RESET.md](PASSWORD-RESET.md#otp-input-optional).

## Password reset

When `password_reset.mode` is `enabled`, the bundle registers request and completion routes. Implement `PasswordResetNotifierInterface` for delivery.

See [PASSWORD-RESET.md](PASSWORD-RESET.md) for entity fields, notifier wiring, and events.

## Magic login (passwordless)

When `magic_login.mode` is `enabled`, users can request a one-time sign-in link. Requires Symfony firewall `login_link` with **`signature_properties`** (synced by `nowo:auth-kit:configure-security` from `user_identifier_field`). Implement `MagicLoginNotifierInterface` to email the URL.

Set `magic_login.confirm_interstitial: true` when the firewall uses `login_link.check_post_only`: GET `magic_login_check` renders `templates.magic_login_confirm` (Form CSRF); POST authenticates via `magic_login_confirm`. `nowo:auth-kit:configure-security` writes `check_post_only: true` and public access for both routes in that case.

See [MAGIC-LOGIN.md](MAGIC-LOGIN.md).

## Social login (OAuth)

When `social_login.mode` is `enabled` **and** at least one enabled `SocialLoginCredential` exists in the database, the login page shows provider buttons. Client secrets and linked-user tokens live in Doctrine tables owned by the bundle (`auth_kit_social_credential`, `auth_kit_social_account`).

`social_login.pkce` toggles PKCE (RFC 7636, `S256` only) per provider key. Built-in `google`, `github` and `microsoft` default to enabled (verifier stored in the session with the OAuth `state`, `code_challenge` on the authorize redirect, `code_verifier` on the token exchange); custom providers default to disabled. Example: `pkce: { github: false, my_idp: true }`.

See [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md).

## Embedded auth UI

When `embed.mode` is `dropdown`, render `{{ auth_kit_dropdown() }}` in Twig. Forms POST to the same routes as full-page login/register.

See [USAGE.md](USAGE.md#embedded-loginregister-dropdown).

## Locale in paths

```yaml
nowo_auth_kit:
    locale:
        in_path: always          # never | always | both
        default: en
        enabled: [en, es]
        unlocalized: redirect    # serve | redirect (when in_path: both)
```

| `in_path` | Behaviour |
|-----------|-----------|
| `never` | Only `/login`, `/register`, … |
| `always` | Only `/{_locale}/login`, … |
| `both` | Localized routes (canonical names) **and** bare `/login` as `{name}_unlocalized` |

When `in_path: both`:

- `unlocalized: redirect` — bare URL redirects to `/{default}/…` (or current request locale via `auth_kit_route_params()`).
- `unlocalized: serve` — bare URL renders with `_locale = locale.default`. `UnlocalizedDefaultLocaleSubscriber` (priority `14`) re-applies `locale.default` on each request so a stale warmed route cache cannot stick a wrong locale on `/login` (image build vs runtime `DEFAULT_LOCALE`).

Legacy keys `default_locale`, `enabled_locales`, and `locale_in_path` (bool) still work and map into `locale.*`.

Update `access_control` (or run `nowo:auth-kit:configure-security`). Use `auth_kit_route_params()` in Twig. See [USAGE.md](USAGE.md#locale-in-url-paths).

## Recipient locale (notifications)

Since 1.23. Password-reset, magic-login and new-device notifications are prepared in the **recipient's** locale instead of the requesting page's.

```yaml
nowo_auth_kit:
    recipient_locale:
        enabled: true        # default; no-op for users without a preference
        resolver: ~          # optional service id implementing RecipientLocaleResolverInterface
```

The default resolver reads `Nowo\AuthKitBundle\Locale\PreferredLocaleAwareInterface::getPreferredLocale(): ?string` on the user entity. The resolved locale is used only when it is listed in `locale.enabled`. Then AuthKit:

1. generates the reset link (`AuthKitUrlGenerator`, explicit `_locale`) and the magic link (Symfony `LoginLinkHandler` with a copy of the request in that locale) for localized routes (`locale.in_path: always|both`); with `in_path: never` the URL is unchanged;
2. sets `recipientLocale` on `PasswordResetNotificationContext`, `MagicLoginNotificationContext` and `NewDeviceLoginNotificationContext` (null = no preference: use the request locale);
3. dispatches `PasswordResetRequestedEvent` / `MagicLoginRequestedEvent` and calls the notifier inside Symfony's `LocaleSwitcher::runWithLocale()` — translator, router context `_locale` and ICU default locale are switched and restored in a `finally` (worker-safe).

Unknown identifiers never reach a notifier (anti-enumeration), so no locale is needed for them. A custom resolver:

```php
use Nowo\AuthKitBundle\Locale\RecipientLocaleResolverInterface;

final class UserPreferredLocaleResolver implements RecipientLocaleResolverInterface
{
    public function resolve(object $user): ?string
    {
        return $user instanceof \App\Entity\User ? $user->getPreferredLocaleRaw() : null;
    }
}
```

## Absolute URL base (mailed / QR links)

Since 1.24. Links that leave the request are absolute URLs. By default the router builds them from the incoming request (scheme, `Host` header, port, base path). Behind a proxy that forwards any `Host` — and without `framework.trusted_hosts` — an attacker can request a password reset for a victim with `Host: evil.example` and the victim receives a reset link to `evil.example` (**password-reset poisoning**). Pin the origin:

```yaml
nowo_auth_kit:
    absolute_url:
        base_uri: '%env(DEFAULT_URI)%'   # e.g. https://app.example.com or https://example.com/portal
```

- Value: absolute `http`/`https` URL, optional port and path prefix (trailing `/` ignored); no user info, query or fragment. Invalid literals fail at container compile; `%env()%` values are checked when the service is built (invalid → `InvalidArgumentException`; empty → disabled).
- Covered URLs (all built by `Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator`):
  - password-reset link (`reset_password` route with token) and, in code-only delivery, the reset-code page link (`reset_password_code`);
  - magic-login link (Symfony `LoginLinkHandler`, `{_locale}` = recipient locale, else request locale);
  - QR login approve URL (QR payload scanned by the phone);
  - social login OAuth `redirect_uri` (start **and** code exchange) — register `{base_uri}{check path}` as the callback URL at each provider; with `base_uri` set the request host no longer influences it, which is what providers expect;
  - any other `AuthKitUrlGenerator::generate(..., UrlGeneratorInterface::ABSOLUTE_URL|NETWORK_PATH)` call.
- Relative links (`ABSOLUTE_PATH`, redirects, form actions) are unchanged.
- The router context is swapped only during the generation and restored in `finally` (FrankenPHP worker-safe; no state is kept between requests).
- `null` / empty (default): links are built from the request as before 1.24. In production either set `base_uri` or configure `framework.trusted_hosts` (see [SECURITY.md](SECURITY.md#password-reset-poisoning-host-header)).
- **Host code (notifications, share links, exports):** inject `Nowo\AuthKitBundle\Routing\PublicUrlGenerator` (`nowo_auth_kit.public_url_generator`, a `UrlGeneratorInterface`) instead of the router wherever you build a link that leaves the request — `ABSOLUTE_URL` / `NETWORK_PATH` use `base_uri`, relative paths use the router:

  ```php
  public function __construct(
      #[Autowire(service: PublicUrlGenerator::class)] private UrlGeneratorInterface $urlGenerator,
  ) {}
  ```

## Flash messages

Since 1.23.

```yaml
nowo_auth_kit:
    flash:
        translate: true                                   # store translated text (default)
        twig_filter: true                                 # register the flash_trans Twig filter
        twig_filter_domains: [messages, NowoAuthKitBundle]
```

- `translate: true` — AuthKit controllers translate their flash keys from the `NowoAuthKitBundle` domain in the request locale before adding them, so any flash template (`{{ message }}`, `{{ message|trans }}`, UiKitBundle `partials/_toasts.html.twig`) shows text. `false` keeps the raw keys (pre-1.23).
- `flash_trans` — for hosts whose own controllers flash keys from several domains: `{{ message|flash_trans }}` uses the first domain of `twig_filter_domains` whose catalogue (current locale + fallbacks) has the key; anything else goes through `messages` (already translated text is returned unchanged). Non-string (structured) flashes pass through.

## Admin CLI (create-admin)

Since 1.23.

```yaml
nowo_auth_kit:
    create_admin:
        min_password_length: 12      # min 8
        roles: [ROLE_ADMIN]
```

```bash
php bin/console nowo:auth-kit:create-admin admin@example.com [--auth-profile=default]
```

- Writes the identifier to the profile `user_identifier_field` (lower-cased and validated when it is `email`), the hashed password to the property of the first hashed registration field (default `password`), and `roles` via `setRoles()` / property access.
- The password is prompted twice with hidden input; it cannot be passed as an option and the command refuses non-interactive runs or terminals that cannot hide input.
- Applies `min_password_length` and, when the profile enables `password_strength` and the bundle is installed, the same strength policy as registration.
- Rejects an existing identifier; ignores `registration_mode` (operator provisioning).
- Events: `Nowo\AuthKitBundle\Admin\AdminUserCreatingEvent` (before persist; fill required host fields such as a display name) and `AdminUserCreatedEvent` (after flush; e.g. mark an installation wizard as completed).

## Templates

The auth pages follow the UiKit page contract (REQ-UI-003): theme them once in `nowo_ui_kit` (layouts.guest, theme
classes / tokens) — see [TEMPLATES.md](TEMPLATES.md) for pages, data blocks, view-model fields and the QR challenge
attributes. Per profile:

| Key | Purpose |
| --- | --- |
| `web_ui.layout_template` | layout override (default null = `nowo_ui_kit.layouts.guest`) |
| `web_ui.templates.<page>` | per-page template (`login`, `register`, `reset_request`, `reset_password`, `reset_password_code`, `magic_login_request`, `magic_login_confirm`, `qr_login_show`, `qr_login_approve`) |
| `web_ui.form_themes` | form themes of the auth forms (default UiKit form theme + AuthKit PasswordToggle theme) |
| `web_ui.brand` | brand line of the panel |
| `web_ui.qr_login.stimulus_controller` | Stimulus identifier for the QR challenge (values + targets; the bundled poller is not loaded) |
| `embed.*` | embedded widget templates (`auth_kit_dropdown()`) |

Deprecated aliases (1.27, removed in 2.0): `templates.layout` → `web_ui.layout_template` (the old default
`@NowoAuthKitBundle/layout.html.twig` means null), `templates.<page>` → `web_ui.templates.<page>`,
`templates.form_theme` → `web_ui.form_themes`, `css.button_class` / `css.secondary_button_class` →
`nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary` / `btn_secondary` (bridged automatically).
`templates.*` stays readable (container parameter `nowo_auth_kit.templates`, profile settings) as a resolved mirror.

```yaml
nowo_auth_kit:
    outbound_mail_ready_checker: app.auth_kit.mail_ready_checker
    profiles:
        default:
            web_ui:
                layout_template: 'layouts/auth.html.twig'
                form_themes:
                    - '@NowoUiKit/form/nowo_ui_layout.html.twig'
                    - '@NowoAuthKitBundle/form/password_toggle_widget.html.twig'
                brand: 'Acme Cloud'

nowo_ui_kit:
    css_framework: bootstrap5
    layouts:
        guest: 'layouts/guest.html.twig'
    theme:
        bundles:
            NowoAuthKitBundle:
                classes: { btn_primary: 'w-100' }
```

Twig globals (1.x, kept): `nowo_auth_kit_form_themes` (default profile `web_ui.form_themes`),
`nowo_auth_kit_button_class` / `nowo_auth_kit_secondary_button_class` (deprecated with `css.*`),
`nowo_auth_kit_outbound_mail_ready()`.

The bundle prepends an asset package named `nowo_auth_kit` with base path `/bundles/nowoauthkit` (`css/nowo-auth-kit.css`,
`js/nowo-auth-kit-qr-login.js`).

## Routes

Route **names** must stay in sync with `security.yaml` (`login_path`, `check_path`, `logout.path`). Paths are customizable for URL structure and `access_control` regexes.

When `locale.in_path` is `always` or `both`, localized paths are `/{_locale}/login`, etc.; canonical route **names** stay the same. Bare aliases use the `_unlocalized` suffix when `both`.

## Security.yaml checklist

1. Entity provider with `user_class` and `user_identifier_field`
2. `form_login.login_path` and `check_path` = login route name
3. `logout.path` = logout route name
4. `access_control` for login, register, and (if enabled) password-reset / magic-login / social-login paths → `PUBLIC_ACCESS`
5. Protected areas require `ROLE_USER` (or your roles)

Run `php bin/console nowo:auth-kit:configure-security` to apply steps 1–4 automatically.
