# Auth Kit template overrides (demo)

This demo replaces the bundle defaults with Bootstrap 5 layouts.

## Override path (Symfony convention)

Files here take precedence over `@NowoAuthKitBundle/…`:

```
templates/bundles/NowoAuthKitBundle/
├── layout.html.twig          # Bootstrap shell + locale switcher
└── security/
    ├── login.html.twig       # Card layout + bootstrap_5 form theme
    └── register.html.twig
```

No `nowo_auth_kit.templates` config is required when using this folder structure.

## Alternative: explicit config

You can also point the bundle to app templates:

```yaml
# config/packages/nowo_auth_kit.yaml
nowo_auth_kit:
    templates:
        layout: 'demo/layout/auth_kit.html.twig'
        login: 'demo/security/login.html.twig'
        register: 'demo/security/register.html.twig'
```

## Forms

Login and register templates stack Symfony’s Bootstrap 5 form theme with the password toggle widget:

```twig
{% form_theme login_form [
    'bootstrap_5_layout.html.twig',
    '@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig',
] %}
```

## Locales

- Switcher: `templates/demo/_locale_switcher.html.twig`
- Route: `app_set_locale` → `LocaleController`
- Session locale: `App\EventSubscriber\LocaleSubscriber`
- Demo strings: `translations/demo.en.yaml`, `translations/demo.es.yaml`
- Bundle strings: override `translations/NowoAuthKitBundle.<locale>.yaml`

See [USAGE.md](../../../docs/USAGE.md) in the bundle for full override documentation.
