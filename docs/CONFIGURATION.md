# Configuration

## Table of contents

- [Reference](#reference)
- [Registration modes](#registration-modes)
- [Field configuration](#field-configuration)
  - [Login](#login)
  - [Registration](#registration)
- [Password reset](#password-reset)
- [Embedded auth UI](#embedded-auth-ui)
- [Locale in paths](#locale-in-paths)
- [Templates](#templates)
- [Routes](#routes)
- [Security.yaml checklist](#securityyaml-checklist)

All options live under the `nowo_auth_kit` root key in `config/packages/nowo_auth_kit.yaml`.

## Reference

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

    # Documented for security.yaml (see INSTALLATION.md)
    firewall: main
    login_success_route: null   # route name after login/register; null uses firewall default

    default_locale: en
    enabled_locales: [en, es]
    locale_in_path: false       # true → /{locale}/login, /{locale}/register, …
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

The login form uses Symfony Security field names (`_username`, `_password`, `_csrf_token`) so `form_login` works without extra configuration.

### Registration

Each field can be:

- a string (property name equals field name), or
- an array with `name`, `type` (`text`, `email`, `password`, `checkbox`), `property`, `hash` (default `true` for password), `required`.

Password fields use `RepeatedType` with minimum length validation. When `nowo-tech/password-toggle-bundle` is present, the toggle `PasswordType` is used; otherwise Symfony’s default `PasswordType` is used (no hard dependency in the bundle library).

## Password reset

When `password_reset.mode` is `enabled`, the bundle registers request and completion routes. Implement `PasswordResetNotifierInterface` for delivery.

See [PASSWORD-RESET.md](PASSWORD-RESET.md) for entity fields, notifier wiring, and events.

## Embedded auth UI

When `embed.mode` is `dropdown`, render `{{ auth_kit_dropdown() }}` in Twig. Forms POST to the same routes as full-page login/register.

See [USAGE.md](USAGE.md#embedded-loginregister-dropdown).

## Locale in paths

When `locale_in_path` is `true`, Auth Kit routes are prefixed with `/{_locale}`. Update `access_control` patterns accordingly (or run `nowo:auth-kit:configure-security`).

Use `auth_kit_route_params()` in Twig for links. See [USAGE.md](USAGE.md#locale-in-url-paths).

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

When `locale_in_path` is enabled, paths are resolved as `/{_locale}/login`, etc., but route names stay the same.

## Security.yaml checklist

1. Entity provider with `user_class` and `user_identifier_field`
2. `form_login.login_path` and `check_path` = login route name
3. `logout.path` = logout route name
4. `access_control` for login, register, and (if enabled) password-reset paths → `PUBLIC_ACCESS`
5. Protected areas require `ROLE_USER` (or your roles)

Run `php bin/console nowo:auth-kit:configure-security` to apply steps 1–4 automatically.
