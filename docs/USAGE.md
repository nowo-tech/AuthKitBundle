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
├── _slide_to_confirm_assets.html.twig
├── _registration_submit.html.twig
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
| `registration_allowed` | Whether self-registration is currently allowed (`registration_mode` + user count) |
| `reset_password_route` | Route name for password reset request |
| `password_reset_enabled` | Whether password reset flows are enabled |
| `magic_login_route` | Route name for passwordless magic login request |
| `magic_login_enabled` | Whether magic login is enabled |
| `layout_template` | Parent layout template |

**Shared full-page globals**:

| Variable / function | Description |
|---------------------|-------------|
| `nowo_auth_kit_form_themes` | Default form theme list from profile `templates.form_theme` |
| `nowo_auth_kit_button_class` | Default submit button class from profile `css.button_class` |
| `nowo_auth_kit_secondary_button_class` | Default social button class from profile `css.secondary_button_class` |
| `nowo_auth_kit_outbound_mail_ready()` | Returns whether outbound email-dependent UI should be shown |
| `nowo_auth_kit_slide_to_confirm_assets` / `nowo_auth_kit_slide_to_confirm_assets()` | True when slide-to-confirm assets should be loaded |

**Register** (`security/register.html.twig`):

| Variable | Description |
|----------|-------------|
| `registration_form` | Registration form view |
| `login_route` | Route name for login link |
| `layout_template` | Parent layout |
| `slide_to_confirm_mode` | SlideToConfirm profile name when registration consent uses a slider (`gate`, `legal`, …), otherwise unset/null |

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

For shared branding without forking every page, the bundle layout now exposes:

- `auth_brand` before the panel
- `auth_panel` for the page-specific content
- `auth_panel_heading` inside each security template heading
- `auth_footer_extra` after the default footer/social links

Example:

```twig
{# templates/bundles/NowoAuthKitBundle/layout.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <aside class="marketing-column">
        {% block auth_brand %}
            <h2>Acme Cloud</h2>
            <p>Secure access for your workspace.</p>
        {% endblock %}

        {% block auth_panel %}{% endblock %}
    </aside>
{% endblock %}
```

### Bootstrap 5 and password toggle (demo reference)

The demo under `demo/symfony8` shows a full override with Bootstrap 5:

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

You can also configure the bundle-wide defaults instead of repeating the theme in every page override:

```yaml
nowo_auth_kit:
    profiles:
        default:
            templates:
                form_theme:
                    - 'bootstrap_5_layout.html.twig'
                    - '@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'
```

See `demo/README.md` for locale switching and template paths.

### Password field type without password-toggle bundle

If `nowo-tech/password-toggle-bundle` is not installed, the bundle falls back to Symfony’s core `PasswordType` automatically (`PasswordFieldTypeResolver`).

## Translation overrides (REQ-I18N-001)

Domain: **`NowoAuthKitBundle`**

Bundle ships `de`, `en`, `es`, `fr`, `it`, `nl`, and `pt` in `src/Resources/translations/`.

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
3. On valid submit, `UserRegistrar` creates the entity, hashes password fields, assigns `registration_role`, persists. Fields with `mapped: false` (default for `slide_to_confirm`) are not written to the user entity.
4. Optional slide-to-confirm on a consent checkbox is UX only — see [CONFIGURATION.md](CONFIGURATION.md#slide-to-confirm-optional).
5. User is logged in on the configured firewall and redirected to `login_success_route` or login.

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

`demo/symfony8` enables `embed.mode: dropdown` and shows the component in `templates/base.html.twig` on the public welcome page (`/{locale}`).

## Locale in URL paths

```yaml
nowo_auth_kit:
    locale:
        in_path: always          # never | always | both
        default: en
        enabled: [en, es]
        unlocalized: redirect    # when in_path: both
```

| Mode | Example URLs |
|------|----------------|
| `always` | `/en/login`, `/es/register` |
| `both` | `/en/login` **and** `/login` (`nowo_auth_kit_login_unlocalized`) |
| `never` | `/login` only |

Symfony `form_login` should keep using the **canonical** route names (`nowo_auth_kit_login`, …). With `both` + `redirect`, bare URLs 302 to the localized path. With `both` + `serve`, bare URLs render using `locale.default`.

### Twig links

Use the helper so links keep the current locale when prefixes are enabled:

```twig
<a href="{{ path('nowo_auth_kit_login', auth_kit_route_params()) }}">Sign in</a>
<a href="{{ path('nowo_auth_kit_register', auth_kit_route_params({foo: 'bar'})) }}">Register</a>
```

When `locale.in_path` is `never`, `auth_kit_route_params()` returns an empty array (backward compatible).

### access_control

Run `php bin/console nowo:auth-kit:configure-security` after changing `locale.in_path`. For `both`, it adds localized **and** bare patterns (e.g. `^/(en|es)/login` and `^/login`).

## Registration link on login page

The login template receives `registration_allowed` from `RegistrationGate` (same logic as the register route and embed UI). Hide the link when it is false:

```twig
{% if registration_allowed|default(false) %}
    <a href="{{ path(register_route, auth_kit_route_params()) }}">{{ 'login.register_link'|trans({}, 'NowoAuthKitBundle') }}</a>
{% endif %}
```

With `registration_mode: first_user_only`, the link appears only while the user table is empty. With `disabled`, it stays hidden. Custom template overrides must include this check if they render their own footer links.

If your application conditionally enables outbound email, also gate password-reset and magic-login links with `nowo_auth_kit_outbound_mail_ready()` to match the default template behavior.
