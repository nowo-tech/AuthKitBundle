# Usage

## Table of contents

- [Twig template overrides (REQ-TWIG-001)](#twig-template-overrides-req-twig-001)
  - [Override in the application](#override-in-the-application)
  - [Available variables](#available-variables)
  - [Custom layout](#custom-layout)
- [Translation overrides (REQ-I18N-001)](#translation-overrides-req-i18n-001)
  - [Override in the application](#override-in-the-application-1)
- [Registration flow](#registration-flow)
- [Login flow](#login-flow)
- [Embedded login/register (dropdown)](#embedded-loginregister-dropdown)
- [Locale in URL paths](#locale-in-url-paths)
- [Disabling registration link on login page](#disabling-registration-link-on-login-page)

## Twig template overrides (REQ-TWIG-001)

Bundle templates use the `@NowoAuthKitBundle` namespace.

### Override in the application

Create files under:

```
templates/bundles/NowoAuthKitBundle/
├── layout.html.twig
└── security/
    ├── login.html.twig
    └── register.html.twig
```

Symfony resolves app overrides before bundle defaults.

### Available variables

**Login** (`security/login.html.twig`):

| Variable | Description |
|----------|-------------|
| `login_form` | Login form view |
| `error` | Last authentication error |
| `register_route` | Route name for registration link |
| `layout_template` | Parent layout template |

**Register** (`security/register.html.twig`):

| Variable | Description |
|----------|-------------|
| `registration_form` | Registration form view |
| `login_route` | Route name for login link |
| `layout_template` | Parent layout |

### Custom layout

Extend your app layout in an override:

```twig
{# templates/bundles/NowoAuthKitBundle/security/login.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {{ include('@NowoAuthKitBundle/security/_login_form.html.twig', { login_form: login_form }) }}
{% endblock %}
```

Or set `nowo_auth_kit.templates.layout` to your layout and override only the inner templates.

### Bootstrap 5 and password toggle (demo reference)

The demos under `demo/symfony7` and `demo/symfony8` show a full override with Bootstrap 5:

1. Copy or adapt `templates/bundles/NowoAuthKitBundle/` (layout, login, register).
2. Use a single combined form theme:

   ```twig
   {# templates/form/auth_kit_theme.html.twig #}
   {% use "bootstrap_5_layout.html.twig" %}
   {% use "@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig" %}

   {% block toggle_password_widget %}
       {% set attr = attr|merge({class: (attr.class|default('') ~ ' form-control')|trim}) %}
       {{ parent() }}
   {% endblock %}
   ```

3. In login/register overrides: `{% form_theme login_form 'form/auth_kit_theme.html.twig' %}`.

See `demo/README.md` for locale switching and template paths.

### Password field type without password-toggle bundle

If `nowo-tech/password-toggle-bundle` is not installed, the bundle falls back to Symfony’s core `PasswordType` automatically (`PasswordFieldTypeResolver`).

## Translation overrides (REQ-I18N-001)

Domain: **`NowoAuthKitBundle`**

Bundle ships `en` and `es` in `src/Resources/translations/`.

### Override in the application

```yaml
# translations/NowoAuthKitBundle.es.yaml
login:
    heading: Acceso al panel
register:
    submit: Crear mi cuenta
```

Symfony uses app translations first; missing keys fall back to the bundle.

## Registration flow

1. Guest opens `/register` (or configured path).
2. `RegistrationGate` checks `registration_mode`.
3. On valid submit, `UserRegistrar` creates the entity, hashes password fields, assigns `registration_role`, persists.
4. User is logged in on the configured firewall and redirected to `login_success_route` or login.

## Login flow

1. Guest opens `/login`.
2. Controller renders the form; POST is handled by Symfony `form_login` on the firewall.
3. CSRF token id: `authenticate` (Symfony default).

## Embedded login/register (dropdown)

Embed login and/or registration in any Twig layout (navbar, header, etc.) without duplicating forms.

### Enable

```yaml
# config/packages/nowo_auth_kit.yaml
nowo_auth_kit:
    embed:
        mode: dropdown          # disabled | dropdown
        show_login: true
        show_register: true
        template: '@NowoAuthKitBundle/embed/dropdown.html.twig'
        login_panel: '@NowoAuthKitBundle/embed/_login_panel.html.twig'
        register_panel: '@NowoAuthKitBundle/embed/_register_panel.html.twig'
        authenticated: '@NowoAuthKitBundle/embed/_authenticated.html.twig'
```

When `mode` is `disabled`, `auth_kit_dropdown()` returns an empty string.

### Render in Twig

```twig
{# optional form_theme for Bootstrap or password toggle #}
{{ auth_kit_dropdown({form_theme: 'form/auth_kit_theme.html.twig'}) }}
```

Forms POST to the same routes as full-page login/register (`form_login` on the firewall). After a failed login, Symfony redirects to the login page by default.

### Authenticated state

When a user is logged in, the bundle renders the `authenticated` template (default: user identifier + logout link). Override `nowo_auth_kit.embed.authenticated` for a custom menu.

### Demo reference

`demo/symfony7` and `demo/symfony8` enable `embed.mode: dropdown` and show the component in `templates/base.html.twig` on the public welcome page (`/{locale}`).

## Locale in URL paths

Prefix login, register, logout, and password-reset routes with `/{_locale}`:

```yaml
nowo_auth_kit:
    default_locale: en
    enabled_locales: [en, es]
    locale_in_path: true
```

Routes become `/en/login`, `/es/register`, `/en/reset-password`, etc. Symfony `form_login` still uses route **names** in `security.yaml`; only URL paths change.

### Twig links

Use the helper so links keep the current locale:

```twig
<a href="{{ path('nowo_auth_kit_login', auth_kit_route_params()) }}">Sign in</a>
<a href="{{ path('nowo_auth_kit_register', auth_kit_route_params({foo: 'bar'})) }}">Register</a>
```

When `locale_in_path` is `false`, `auth_kit_route_params()` returns an empty array (backward compatible).

### access_control

Run `php bin/console nowo:auth-kit:configure-security` after enabling `locale_in_path`, or add patterns such as `^/(en|es)/login` manually.

## Disabling registration link on login page

When `registration_mode` is `disabled`, registration still has a URL but redirects. Hide the link in a template override:

```twig
{# omit the register link block #}
```

Or use custom templates without the footer link.
