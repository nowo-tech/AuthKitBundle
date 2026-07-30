# Configuration

## Table of contents

- [Reference](#reference)
- [Registration modes](#registration-modes)
- [Field configuration](#field-configuration)
  - [Login](#login)
  - [Registration](#registration)
  - [Password strength (optional)](#password-strength-optional)
- [Password reset](#password-reset)
- [Magic login (passwordless)](#magic-login-passwordless)
- [Social login (OAuth)](#social-login-oauth)
- [Embedded auth UI](#embedded-auth-ui)
- [Locale in paths](#locale-in-paths)
- [Templates](#templates)
- [Routes](#routes)
- [Security.yaml checklist](#securityyaml-checklist)

All options live under the `nowo_auth_kit` root key in `config/packages/nowo_auth_kit.yaml`.

## Profiles

Each **profile** maps a `user_class` to its own auth settings (registration, login fields, password reset, routes, templates, firewall, embed). Use multiple profiles when the application has more than one authenticated user entity (for example `App\Entity\User` and `App\Entity\Admin`).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | first profile key | Profile used when no route context or explicit name is available. |
| `profiles` | map | `default` | Named profile definitions (at least one required). |
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

    # Registration form fields (string shorthand or expanded config)
    registration_fields:
        - email
        - password
        # name:
        #     type: text
        #     property: fullName

    templates:
        layout: '@NowoAuthKitBundle/layout.html.twig'
        login: '@NowoAuthKitBundle/security/login.html.twig'
        register: '@NowoAuthKitBundle/security/register.html.twig'
        reset_request: '@NowoAuthKitBundle/security/reset_request.html.twig'
        reset_password: '@NowoAuthKitBundle/security/reset_password.html.twig'
        reset_password_code: '@NowoAuthKitBundle/security/reset_password_code.html.twig'
        magic_login_request: '@NowoAuthKitBundle/security/magic_login_request.html.twig'

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
        token_field: passwordResetToken
        token_expires_field: passwordResetExpiresAt

    magic_login:
        mode: disabled              # disabled | enabled
        lifetime: 600
        max_uses: 1

    social_login:
        mode: disabled              # disabled | enabled
        create_user_if_missing: true

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
        social_login_start:
            path: /login/social/{provider}
            name: nowo_auth_kit_social_login_start
        social_login_check:
            path: /login/social/{provider}/check
            name: nowo_auth_kit_social_login_check

    # Documented for security.yaml (see INSTALLATION.md)
    firewall: main
    login_success_route: null   # route name after login/register; null uses firewall default

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
- an array with `name`, `type` (`text`, `email`, `password`, `checkbox`), `property`, `hash` (default `true` for password), `required`.

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

## Password reset

When `password_reset.mode` is `enabled`, the bundle registers request and completion routes. Implement `PasswordResetNotifierInterface` for delivery.

See [PASSWORD-RESET.md](PASSWORD-RESET.md) for entity fields, notifier wiring, and events.

## Magic login (passwordless)

When `magic_login.mode` is `enabled`, users can request a one-time sign-in link. Requires Symfony firewall `login_link` with **`signature_properties`** (synced by `nowo:auth-kit:configure-security` from `user_identifier_field`). Implement `MagicLoginNotifierInterface` to email the URL.

See [MAGIC-LOGIN.md](MAGIC-LOGIN.md).

## Social login (OAuth)

When `social_login.mode` is `enabled` **and** at least one enabled `SocialLoginCredential` exists in the database, the login page shows provider buttons. Client secrets and linked-user tokens live in Doctrine tables owned by the bundle (`auth_kit_social_credential`, `auth_kit_social_account`).

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
- `unlocalized: serve` — bare URL renders with `_locale = locale.default`.

Legacy keys `default_locale`, `enabled_locales`, and `locale_in_path` (bool) still work and map into `locale.*`.

Update `access_control` (or run `nowo:auth-kit:configure-security`). Use `auth_kit_route_params()` in Twig. See [USAGE.md](USAGE.md#locale-in-url-paths).

## Templates

Override bundle templates by copying to:

```
templates/bundles/NowoAuthKitBundle/security/login.html.twig
templates/bundles/NowoAuthKitBundle/security/register.html.twig
templates/bundles/NowoAuthKitBundle/security/reset_request.html.twig
templates/bundles/NowoAuthKitBundle/security/reset_password.html.twig
templates/bundles/NowoAuthKitBundle/security/reset_password_code.html.twig
templates/bundles/NowoAuthKitBundle/layout.html.twig
templates/bundles/NowoAuthKitBundle/embed/
```

Or point `templates.*` and `embed.*` to your own Twig paths.

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
