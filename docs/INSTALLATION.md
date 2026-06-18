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
- [User entity](#user-entity)
- [Locales](#locales)
- [Next steps](#next-steps)

## Requirements

- PHP `>=8.2` (<8.6)
- Symfony **7.4** or **8.x**
- Doctrine ORM (user entity persistence)
- `symfony/security-bundle`, `symfony/form`, `symfony/twig-bundle`, `symfony/translation`
- **Recommended for password fields** (installed by the Flex recipe):
  - `nowo-tech/password-toggle-bundle` ^1.2.8
  - `symfony/ux-icons` ^2.0 || ^3.0
  - `symfony/http-client` (same major as your Symfony version)

Without the password-toggle stack, login/register still work using Symfony’s default `PasswordType`.

## Composer

```bash
composer require nowo-tech/auth-kit-bundle
```

The Flex recipe also requires `nowo-tech/password-toggle-bundle`, `symfony/ux-icons`, and `symfony/http-client`, copies `config/packages/ux_icons.yaml`, and adds locked Tabler icons under `assets/icons/tabler/`.

After install:

```bash
php bin/console ux:icons:lock
php bin/console nowo:auth-kit:configure-security
```

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
            logout:
                path: nowo_auth_kit_logout
                target: nowo_auth_kit_login

    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/register, roles: PUBLIC_ACCESS }
```

**Important:** `login_path` and `check_path` must use the **route name** (not the URL path) and must match `nowo_auth_kit.routes.login.name`.

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

## Next steps

- [Configuration](CONFIGURATION.md) — registration modes, roles, fields, templates, password reset, embed
- [USAGE.md](USAGE.md) — Twig and translation overrides, embed dropdown, locale paths
- [PASSWORD-RESET.md](PASSWORD-RESET.md) — reset flow and notifier wiring
