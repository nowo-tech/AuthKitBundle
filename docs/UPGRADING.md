# Upgrading

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
