# Usage

## Table of contents

- [Twig template overrides (REQ-TWIG-001)](#twig-template-overrides-req-twig-001)
  - [Theme once, do not fork pages](#theme-once-do-not-fork-pages)
  - [Override in the application](#override-in-the-application)
  - [Available variables](#available-variables)
  - [Custom layout](#custom-layout)
  - [Bootstrap 5 and password toggle (demo reference)](#bootstrap-5-and-password-toggle-demo-reference)
  - [Password field type without password-toggle bundle](#password-field-type-without-password-toggle-bundle)
- [Translation overrides (REQ-I18N-001)](#translation-overrides-req-i18n-001)
  - [Override in the application](#override-in-the-application-1)
- [Registration flow](#registration-flow)
- [Login flow](#login-flow)
- [Embedded login/register (dropdown)](#embedded-loginregister-dropdown)
- [Locale in URL paths](#locale-in-url-paths)
- [Disabling registration link on login page](#disabling-registration-link-on-login-page)

## Twig template overrides (REQ-TWIG-001)

Bundle templates use the `@NowoAuthKitBundle` namespace. Since 1.27 every auth page is the **UiKit auth scaffold**
(`@NowoUiKit/page/auth.html.twig`, REQ-UI-003) on the host's guest layout — see [TEMPLATES.md](TEMPLATES.md) for the
pages, data blocks and view-model fields.

### Theme once, do not fork pages

1. Point `nowo_ui_kit.layouts.guest` at your guest layout (or a one-file bridge over your base layout that renders the
   UiKit canonical blocks; the demo's `templates/layouts/guest.html.twig`).
2. Theme `nowo_ui_kit.theme` (tokens + class map); per-bundle exceptions in `theme.bundles.NowoAuthKitBundle`.
3. Optionally override `templates/bundles/NowoUiKitBundle/page/auth.html.twig` once for every auth page of every bundle.

### Override in the application

For a genuinely AuthKit-specific need, point `web_ui.templates.<page>` at a thin template that extends the shipped
one with `@!` and overrides a data block:

```twig
{# templates/auth/login.html.twig #}
{% extends '@!NowoAuthKitBundle/security/login.html.twig' %}

{% block auth_footer_extra %}
    <p class="{{ nowo_ui_class('muted', page.bundle) }}"><a href="{{ path('app_help') }}">Need help?</a></p>
{% endblock %}
```

```yaml
nowo_auth_kit:
    profiles:
        default:
            web_ui:
                templates:
                    login: 'auth/login.html.twig'
```

Files under `templates/bundles/NowoAuthKitBundle/` still take precedence (REQ-TWIG-001), but full page copies are
discouraged: they stop receiving fixes (e.g. the 1.27 field-order fix of the reset pages).

### Available variables

Every page: `page` (`AuthPage`), `document_title`, `form_themes`, `auth_kit_page`, plus the 1.x variables below
(`layout_template` is the deprecated bridge layout unless `web_ui.layout_template` is set).

**Login** (`security/login.html.twig`):

| Variable | Description |
|----------|-------------|
| `login_form` | Login form view (also `page.form`) |
| `error` | Last authentication error (also `page.error`, a `security`-domain label) |
| `register_route` / `registration_allowed` | Registration link route / whether self-registration is allowed |
| `reset_password_route` / `password_reset_enabled` | Password reset request route / flag |
| `magic_login_route` / `magic_login_enabled` | Magic login request route / flag |
| `qr_login_start_route` / `qr_login_enabled` | QR start route / flag |
| `social_login_providers` / `sso_login_providers` | Enabled credentials; rendered from `social_links` / `sso_links` (`View\Link`) |

**Register** (`security/register.html.twig`): `registration_form`, `login_route`, `slide_to_confirm_mode`
(SlideToConfirm profile when registration consent uses a slider, otherwise null).

**Shared full-page globals**:

| Variable / function | Description |
|---------------------|-------------|
| `nowo_auth_kit_form_themes` | Form themes of the default profile (`web_ui.form_themes`) |
| `nowo_auth_kit_button_class` / `nowo_auth_kit_secondary_button_class` | Deprecated (1.27) with `css.*` |
| `nowo_auth_kit_outbound_mail_ready()` | Whether outbound email-dependent UI should be shown |
| `nowo_auth_kit_slide_to_confirm_assets` / `nowo_auth_kit_device_intelligence_assets` / `nowo_auth_kit_otp_input_assets` / `nowo_auth_kit_password_toggle_assets` | Which widget assets the pages load |
| `nowo_auth_kit_device_intelligence_collect_endpoint` | POST path for `collect()` (default `/_device/collect`) |

### Custom layout

Per profile, `web_ui.layout_template` overrides `nowo_ui_kit.layouts.guest` (for example an admin profile with its
own frame). The layout must render the UiKit canonical blocks. `@NowoAuthKitBundle/layout.html.twig` is deprecated
(a bridge to the UiKit guest layout kept for 1.x page templates).

### Bootstrap 5 and password toggle (demo reference)

The demo under `demo/symfony8` themes the pages without template overrides: `nowo_ui_kit.css_framework: bootstrap5`,
`nowo_ui_kit.layouts.guest: layouts/guest.html.twig` (navbar + delivery inbox around the canonical blocks), and the
default AuthKit form themes (UiKit form theme + `@NowoAuthKitBundle/form/password_toggle_widget.html.twig`, the
PasswordToggle widget on the UiKit `input` slot). To keep Symfony's Bootstrap form layout instead:

```yaml
nowo_auth_kit:
    profiles:
        default:
            web_ui:
                form_themes:
                    - 'bootstrap_5_layout.html.twig'
                    - '@NowoAuthKitBundle/form/password_toggle_widget.html.twig'
```

See `demo/README.md` for locale switching and the demo layout.

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
5. Optional device intelligence (`device_rate_limit`) adds an extra limiter key by device ULID after a successful `collect()` — see [CONFIGURATION.md](CONFIGURATION.md#device-intelligence-optional).
6. User is logged in on the configured firewall and redirected to `login_success_route` or login.

## Login flow

1. Guest opens `/login`.
2. Controller renders the form; POST is handled by Symfony `form_login` on the firewall.
3. CSRF token id: `authenticate` (Symfony default).
4. Optional device intelligence: layout `collect()` does not change the login form. After `LoginSuccess`, `new_device_notify` may set session `nowo_auth_kit.new_device` and call `NewDeviceLoginNotifierInterface`. Device ID is not a credential.

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
{{ auth_kit_dropdown() }}
{# optional: a form theme of your own instead of nowo_auth_kit_form_themes #}
{{ auth_kit_dropdown({form_theme: 'form/auth_kit_theme.html.twig'}) }}
```

The widget is a native `<details>` disclosure painted with UiKit theme slots (`btn_secondary` toggle, `tabs` /
`tab` / `tab_active`, `flash_error`, `btn_primary`) and `nowo-auth-kit.css` (`nowo-auth-kit-embed*`); no Bootstrap
dropdown JavaScript. Load on the host pages that show it: `nowo-ui.css`, `{{ asset('css/nowo-auth-kit.css',
'nowo_auth_kit') }}` (plus the PasswordToggle stylesheet when installed) and the UiKit scripts partial (`{{ include(nowo_ui_partial('scripts')) }}`, tab switching between
login and register).

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
