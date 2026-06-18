# Configuration

## Table of contents

- [Reference](#reference)
- [Registration modes](#registration-modes)
- [Field configuration](#field-configuration)
  - [Login](#login)
  - [Registration](#registration)
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

    # Documented for security.yaml (see INSTALLATION.md)
    firewall: main
    login_success_route: null   # route name after login/register; null uses firewall default

    default_locale: en
    enabled_locales: [en, es]
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

## Templates

Override bundle templates by copying to:

```
templates/bundles/NowoAuthKitBundle/security/login.html.twig
templates/bundles/NowoAuthKitBundle/security/register.html.twig
templates/bundles/NowoAuthKitBundle/layout.html.twig
```

Or point `templates.login` / `templates.register` to your own Twig paths.

## Routes

Route **names** must stay in sync with `security.yaml` (`login_path`, `check_path`, `logout.path`). Paths are customizable for URL structure and `access_control` regexes.

## Security.yaml checklist

1. Entity provider with `user_class` and `user_identifier_field`
2. `form_login.login_path` and `check_path` = login route name
3. `logout.path` = logout route name
4. `access_control` for login and register paths → `PUBLIC_ACCESS`
5. Protected areas require `ROLE_USER` (or your roles)

Run `php bin/console nowo:auth-kit:configure-security` to apply steps 1–4 automatically.
