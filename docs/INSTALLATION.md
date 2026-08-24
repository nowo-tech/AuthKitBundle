# Installation

## Table of contents

- [Requirements](#requirements)
- [Composer](#composer)
- [Enable the bundle](#enable-the-bundle)
  - [With Symfony Flex](#with-symfony-flex)
  - [Without Flex](#without-flex)
- [Routes](#routes)
- [Security configuration (required)](#security-configuration-required)
  - [Option A — CLI helper](#option-a--cli-helper)
  - [Option B — Manual security.yaml](#option-b--manual-securityyaml)
- [Login throttling (required in production)](#login-throttling-required-in-production)
- [User entity](#user-entity)
- [Locales](#locales)
- [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
- [Next steps](#next-steps)

## Requirements

- PHP `>=8.2` (<8.6)
- Symfony **7.4** or **8.x**
- Doctrine ORM (user entity persistence)
- `symfony/security-bundle`, `symfony/form`, `symfony/twig-bundle`, `symfony/translation`
- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — auth Symfony forms (`FormOptionsTrait`, profile `auth_kit`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.
- **Twig Extra** (`twig/extra-bundle` + `twig/string-extra` ^3.12) — required for shipped Twig templates (REQ-TWIG-004); see [Twig Extra Bundle](#twig-extra-bundle-req-twig-004)
- **Recommended for password fields** (installed by the Flex recipe):
  - `nowo-tech/password-toggle-bundle` ^1.2.8
  - `symfony/ux-icons` ^2.0 || ^3.0
  - `symfony/http-client` (same major as your Symfony version)
- **Required for production login forms** (installed by the Flex recipe):
  - `nowo-tech/login-throttle-bundle` ^3.1 — credential stuffing protection on `form_login`

Without the password-toggle stack, login/register still work using Symfony’s default `PasswordType`.

Optional: install `nowo-tech/password-strength-bundle` and set `nowo_auth_kit.password_strength.enabled: true` for strength policies on registration and password-reset fields — see [CONFIGURATION.md](CONFIGURATION.md#password-strength-optional).

Optional: install `nowo-tech/slide-to-confirm-bundle` and set `nowo_auth_kit.slide_to_confirm.enabled: true` for a slide-to-confirm gesture on registration consent and QR login approve — see [CONFIGURATION.md](CONFIGURATION.md#slide-to-confirm-optional). The swipe is confirmation UX, not authorization.

Optional: install `nowo-tech/device-intelligence-bundle` (**PHP 8.3+**) and set `nowo_auth_kit.device_intelligence.enabled: true` for device observation on auth pages, optional new-device notify, extra device-keyed rate limits, and QR trusted-device step-up — see [CONFIGURATION.md](CONFIGURATION.md#device-intelligence-optional). Device ID is not a credential. AuthKit does **not** `require` that package (PHP 8.2 hosts stay compatible).

## Composer

```bash
composer require nowo-tech/auth-kit-bundle
```

The Flex recipe also requires `nowo-tech/password-toggle-bundle`, `symfony/ux-icons`, and `symfony/http-client`, copies `config/packages/ux_icons.yaml`, and adds locked Tabler icons under `assets/icons/tabler/`.

After install:

```bash
php bin/console ux:icons:lock
php bin/console nowo:auth-kit:configure-security
php bin/console nowo:login-throttle:configure-security
```

The Flex recipe also requires `nowo-tech/login-throttle-bundle`, copies `config/packages/nowo_login_throttle.yaml`, and enables `NowoLoginThrottleBundle`.

## Enable the bundle

### With Symfony Flex

The recipe enables the bundle, creates `config/packages/nowo_auth_kit.yaml`, and imports routes.

### Without Flex

```php
// config/bundles.php
Nowo\AuthKitBundle\NowoAuthKitBundle::class => ['all' => true],
```

## Routes

```yaml
# config/routes/nowo_auth_kit.yaml
nowo_auth_kit:
    resource: '@NowoAuthKitBundle/Resources/config/routing.yaml'
```

This registers login (`/login`), logout (`/logout`), and register (`/register`) with configurable paths and route names.

## Security configuration (required)

AuthKit provides controllers and forms, but **Symfony Security** still owns authentication. You must configure `config/packages/security.yaml`.

### Option A — CLI helper

After configuring `nowo_auth_kit.yaml`:

```bash
php bin/console nowo:auth-kit:configure-security
```

This merges `form_login`, `logout`, entity provider, and `access_control` for public login/register paths.

### Option B — Manual `security.yaml`

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: auto

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User          # must match nowo_auth_kit.user_class
                property: email                   # must match nowo_auth_kit.user_identifier_field

    firewalls:
        dev:
            pattern: ^/(_profiler|wdt|css|images|js)/
            security: false
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: nowo_auth_kit_login   # route name from nowo_auth_kit.routes.login.name
                check_path: nowo_auth_kit_login
                default_target_path: homepage
                enable_csrf: true
                username_parameter: login_form[_username]
                password_parameter: login_form[_password]
                csrf_parameter: login_form[_csrf_token]
                csrf_token_id: authenticate
            # Optional — enable when nowo_auth_kit.remember_me.enabled is true:
            # remember_me:
            #     secret: '%kernel.secret%'
            #     lifetime: 604800
            #     path: /
            #     remember_me_parameter: login_form[_remember_me]
            logout:
                path: nowo_auth_kit_logout
                target: nowo_auth_kit_login
                invalidate_session: true

    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/register, roles: PUBLIC_ACCESS }
```

**Important:** `login_path` and `check_path` must use the **route name** (not the URL path) and must match `nowo_auth_kit.routes.login.name`.

## Login throttling (required in production)

Auth Kit login forms are public HTTP endpoints. **You must** pair them with `nowo-tech/login-throttle-bundle` so Symfony `login_throttling` is applied to the same firewall as `form_login` (typically `main`).

The Flex recipe installs the bundle and copies `config/packages/nowo_login_throttle.yaml` (3 attempts / 10 minutes, cache storage). After `nowo:auth-kit:configure-security`, run:

```bash
php bin/console nowo:login-throttle:configure-security
```

This merges `login_throttling` into `config/packages/security.yaml` for the configured firewall. Use `--force` to refresh limits after changing `nowo_login_throttle.yaml`.

Manual equivalent:

```yaml
security:
    firewalls:
        main:
            login_throttling:
                max_attempts: 3
                interval: '10 minutes'
```

See [Login Throttle Bundle](https://github.com/nowo-tech/LoginThrottleBundle) docs for database storage and multi-firewall setups.

## User entity

Your entity must implement `UserInterface` and `PasswordAuthenticatedUserInterface`. Example:

```php
#[ORM\Entity]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Column(unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    // setEmail(), getPassword(), setPassword(), getRoles(), setRoles(), eraseCredentials()
}
```

Property names must align with `registration_fields` in bundle configuration.

## Locales

Set Symfony default locale and enabled locales to match bundle config:

```yaml
# config/packages/translation.yaml
framework:
    default_locale: en
    enabled_locales: ['en', 'es']
```

Override bundle strings in `translations/NowoAuthKitBundle.es.yaml` (see [USAGE.md](USAGE.md)).

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.

## Next steps

- [Configuration](CONFIGURATION.md) — registration modes, roles, fields, templates, password reset, embed
- [USAGE.md](USAGE.md) — Twig and translation overrides, embed dropdown, locale paths
- [PASSWORD-RESET.md](PASSWORD-RESET.md) — reset flow and notifier wiring
- [MAGIC-LOGIN.md](MAGIC-LOGIN.md) — passwordless login link
- [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md) — OAuth social login (optional)
- [QR-LOGIN.md](QR-LOGIN.md) — QR phone login (opt-in; shipped since v1.12)
