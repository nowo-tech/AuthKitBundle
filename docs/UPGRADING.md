# Upgrading

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
