# Templates

The auth pages of the bundle follow the UiKit 2.x **page contract** (UiKit ^2.2, REQ-UI-003): each page extends the
UiKit **auth scaffold** (`@NowoUiKit/page/auth.html.twig`) through `security/_auth_page.html.twig`, renders on the
**guest layout** (`nowo_ui_kit.layouts.guest`), and the controller passes a UiKit view model (`page`, a
`Nowo\UiKitBundle\View\AuthPage`) built by `Nowo\AuthKitBundle\View\AuthPageFactory`. Panel, brand, heading, intro,
error, form frame, submit button, footer links and flashes come from UiKit and its theme (`nowo_ui_kit.theme`), so a
host themes them **once** for every bundle. References: UiKit `docs/PAGE_CONTRACT.md` (Auth), `docs/THEME_SLOTS.md`.

The embedded sign-in / registration widget (`auth_kit_dropdown()`, `embed/*.html.twig`) is a **widget** rendered inside
the host's own pages, not a page: it uses theme slots only (see [Widgets](#widgets)).

## Table of contents

- [Pages](#pages)
- [Context and data blocks](#context-and-data-blocks)
- [QR challenge page](#qr-challenge-page)
- [Widgets](#widgets)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Layout](#layout)
- [Form themes](#form-themes)
- [Assets and CSP](#assets-and-csp)
- [Theme exception (`theme.bundles.NowoAuthKitBundle`)](#theme-exception-themebundlesnowoauthkitbundle)
- [Allowed exceptions](#allowed-exceptions)

## Pages

| Page | Template (`web_ui.templates.<page>`) | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Sign in (`login`) | `security/login.html.twig` | `page/auth` | `AuthPage` (title `login.heading`, security error of the last attempt as `error`, form = form_login form, submit `login.submit`, links: magic link, QR, forgot password, register — each only when enabled) | `nowo_ui_auth_fields` (`_auth_page`: form errors + UiKit loop), `nowo_ui_auth_footer` (social / SSO providers before the links: `nowo_auth_kit_providers`) |
| Register (`register`) | `security/register.html.twig` | `page/auth` | `AuthPage` (`register.*`, link back to sign in) | `nowo_ui_auth_fields`, `nowo_ui_auth_actions` (`_registration_submit.html.twig`: disabled until the SlideToConfirm consent in `gate` mode) |
| Reset request (`reset_request`) | `security/reset_request.html.twig` | `page/auth` | `AuthPage` (`reset.request.*`, intro = help; links: "I already have a code" when `password_reset.delivery` is `code` or `both`, back to sign in) | `nowo_ui_auth_fields` |
| New password (`reset_password`) | `security/reset_password.html.twig` | `page/auth` | `AuthPage` (`reset.password.*`) | `nowo_ui_auth_fields` — password **and confirmation** (and CSRF) render before the submit button |
| Reset with code (`reset_password_code`) | `security/reset_password_code.html.twig` | `page/auth` | `AuthPage` (`reset.code.*`, links: request a new code, sign in) | `nowo_ui_auth_fields` (identifier, code / OtpType, password + confirmation) |
| Magic link request (`magic_login_request`) | `security/magic_login_request.html.twig` | `page/auth` | `AuthPage` (`magic_login.request.*`) | `nowo_ui_auth_fields` |
| Magic link confirm (`magic_login_confirm`) | `security/magic_login_confirm.html.twig` | `page/auth` | `AuthPage` (`magic_login.confirm.*`, root `data-turbo="false"`) | `nowo_ui_auth_fields` (signed hidden fields + CSRF) |
| QR challenge (`qr_login_show`) | `security/qr_login_show.html.twig` | `page/auth` | `AuthPage` (`qr_login.show.*`; form = field-less GET form to the start route = no-JS retry) | `nowo_ui_auth_fields` (challenge), `nowo_ui_auth_actions` (Continue + Generate a new code) — see [QR challenge page](#qr-challenge-page) |
| QR approve (`qr_login_approve`) | `security/qr_login_approve.html.twig` | `page/auth` | `AuthPage` (`qr_login.approve.*`, intro = warning) | `nowo_ui_auth_fields` (details through the UiKit `detail_list` partial, token / CSRF / slide), `nowo_ui_auth_actions` (Approve), `nowo_ui_auth_footer` (Deny form) |

Root attributes for tests: `data-testid="auth-kit-<page>"` and `data-nowo-auth-kit-page="<page>"` on the scaffold
root (`[data-nowo-ui-page="auth"]`); the form is `[data-nowo-ui-region="form"]`.

`security/_auth_page.html.twig` is the shared base (extends `@NowoUiKit/page/auth.html.twig`); it is not a page.

## Context and data blocks

Every page receives:

| Variable | Content |
| --- | --- |
| `page` | `View\AuthPage`: `title`, `intro`, `brand` (`web_ui.brand`), `error`, `form`, `submitLabel`, `links` (`View\Link`), `bundle` = `NowoAuthKitBundle`, `layout` = `web_ui.layout_template`, `attributes` |
| `document_title` | `Label` of `<page>.title` (the `<title>`; the panel heading is `page.title`) |
| `form_themes` | `web_ui.form_themes`, applied to `page.form` with `form_theme` before the scaffold renders it |
| `auth_kit_page` | the page key (`login`, `register`, …) |
| 1.x variables | `layout_template` (the override, else the deprecated bridge `@NowoAuthKitBundle/layout.html.twig`), `login_form` / `registration_form` / `reset_*_form` / `magic_login_*_form` / `approve_form`, routes (`login_route`, `register_route`, …), flags (`registration_allowed`, `password_reset_enabled`, …) — kept for host templates written against 1.x |

Login also gets `social_links` / `sso_links` (`list<View\Link>`), QR show `status_url`, `complete_url`, `start_url`,
`expires_at`, `expires_in`, `poll_interval_ms`, `public_code`, `qr_data_uri`, `qr_payload`,
`qr_stimulus_controller`, QR approve `approve_details` (`list<View\DetailItem>`), `deny_url`.

Blocks of `_auth_page.html.twig` (besides the UiKit data blocks):

| Block | Where | Default |
| --- | --- | --- |
| `nowo_ui_auth_fields` | inside the form | `form_errors(form)` (CSRF / root errors), then the UiKit child loop (every remaining field before the actions) |
| `nowo_auth_kit_footer_before` | footer, before `page.links` | empty (login: providers; QR approve: Deny form) |
| `auth_footer_extra` | footer, after `page.links` | empty (1.x block, kept) |
| `nowo_auth_kit_providers` | login footer | social + SSO provider buttons (`btn_secondary` slot) |

1.x blocks that no longer exist on the default pages: `auth_panel_heading` (use `page.title` or override the UiKit
auth scaffold), `auth_brand` (`web_ui.brand` / `AuthPage::brand`), `title` (`nowo_ui_document_title`), `auth_panel`
(rendered only by 1.x layouts, see [Layout](#layout)).

## QR challenge page

The challenge root `[data-nowo-auth-kit-qr]` carries everything a script needs, so hosts no longer fork the page for
Stimulus:

| Attribute / part | Content |
| --- | --- |
| `data-nowo-auth-kit-qr-state` | `pending` → `approved` / `denied` / `expired` / `error` (CSS hooks) |
| `data-status-url`, `data-complete-url`, `data-start-url`, `data-poll-interval`, `data-expires-at` | URLs and timing (1.x names kept) |
| `data-label-<state>` | translated status texts (`qr_login.show.state.*`) |
| `[data-nowo-auth-kit-qr-region="image|link|code|status|countdown|continue|retry"]` | the parts (countdown server-rendered as `m:ss`; `link` = the approve link next to the QR image — without an image the link is the `image` region) |
| `web_ui.qr_login.stimulus_controller: <id>` | adds `data-controller="<id>"` and `data-<id>-{status-url,complete-url,start-url,poll-interval,expires-at}-value` on the **auth panel** (`section[data-testid="auth-kit-qr_login_show"]`, the common ancestor of the challenge and of the Continue / retry actions) and `data-<id>-target="<region>"` on every part, and drops the bundled script |

Without a Stimulus controller the bundled `js/nowo-auth-kit-qr-login.js` (asset package `nowo_auth_kit`, CSP nonce,
no inline code) polls the status URL, updates the status text and the countdown, navigates to the complete URL on
approval and dispatches a bubbling `nowo-auth-kit:qr-state` event (`detail: {state, status}`). Without JavaScript the
page still works: **Continue** (complete URL) after approving on the phone, **Generate a new code** (GET form).

Blocks: `nowo_auth_kit_qr_attributes` (extra root attributes), `nowo_auth_kit_qr_code`, `nowo_auth_kit_qr_status`,
`nowo_auth_kit_qr_countdown`, `nowo_auth_kit_qr_continue`, `nowo_auth_kit_qr_retry`.

## Widgets

`auth_kit_dropdown()` renders `embed/dropdown.html.twig` (+ `_login_panel`, `_register_panel`, `_authenticated`):
a native `<details>` disclosure (no framework JavaScript) whose toggle is the `btn_secondary` slot; with both panels,
the tab bar uses the `tabs` / `tab` / `tab_active` slots and the UiKit tabs runtime (`data-nowo-ui-tabs`, loaded by
the UiKit `scripts` partial). Errors use `flash_error`, forms `form`, submits `btn_primary`, links `muted`; layout in
`nowo-auth-kit.css` (`nowo-auth-kit-embed*`). Form themes: the widget's `form_theme` option, else
`nowo_auth_kit_form_themes`. Embed templates stay configurable through `embed.template`, `embed.login_panel`,
`embed.register_panel`, `embed.authenticated`.

## Unique pages

- (none) — every auth page extends the UiKit auth scaffold.

## Per-page overrides

`nowo_auth_kit.profiles.<profile>.web_ui.templates.<page>` with `<page>` = `login`, `register`, `reset_request`,
`reset_password`, `reset_password_code`, `magic_login_request`, `magic_login_confirm`, `qr_login_show`,
`qr_login_approve`: a host template that extends the shipped one and overrides a data block, e.g. a Stimulus
controller on the QR challenge:

```twig
{# templates/auth/qr_login_show.html.twig #}
{% extends '@!NowoAuthKit/security/qr_login_show.html.twig' %}
{% block nowo_auth_kit_qr_attributes %} data-action="nowo-auth-kit:qr-state->analytics#track"{% endblock %}
```

```yaml
nowo_auth_kit:
    profiles:
        default:
            web_ui:
                templates:
                    qr_login_show: 'auth/qr_login_show.html.twig'
```

Prefer theming UiKit (`nowo_ui_kit.theme`, or `templates/bundles/NowoUiKitBundle/page/auth.html.twig` for every auth
page of every bundle) over per-page overrides.

## Layout

`web_ui.layout_template` (per profile, default `null`) is the layout override; null uses `nowo_ui_kit.layouts.guest`
(default `@NowoUiKit/layout/guest.html.twig`). A host layout renders the UiKit canonical blocks
(`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_page_header`, `nowo_ui_flashes`,
`nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`); the demo's `templates/layouts/guest.html.twig` is a one-file
bridge over its `base.html.twig`.

The bundle ships no layout loading a CSS framework. `@NowoAuthKitBundle/layout.html.twig` is **deprecated**: it is now a
bridge to the UiKit guest layout that maps the 1.x blocks (`title`, `stylesheets`, `body`, `auth_brand`, `auth_panel`,
`javascripts`), passed as `layout_template` to 1.x page templates of host applications. A 1.x host layout set as
`web_ui.layout_template` (rendering `auth_panel` instead of the UiKit blocks) still shows the panel: every page defines
`auth_panel` as `{{ block('nowo_ui_content') }}`.

## Form themes

`web_ui.form_themes` (per profile; default: `@NowoUiKit/form/nowo_ui_layout.html.twig`, plus
`@NowoAuthKitBundle/form/password_toggle_widget.html.twig` when NowoPasswordToggleBundle is registered) are applied
to `page.form`. The UiKit form theme paints text inputs with the `input` slot and checkboxes (remember me, terms) as
`<label class="check">` with the `check` / `check_input` slots; the AuthKit PasswordToggle theme adds the `input` slot
to the toggle widget. The seeded FormKit profile `auth_kit` carries no framework classes. Validation messages are
translated in the bundle's `NowoAuthKitBundle` catalogues (7 locales).

## Assets and CSP

`_auth_page.html.twig` stacks `nowo-auth-kit.css` (UiKit tokens; layout of the bundle's own markup), the
PasswordToggle stylesheet (bundle registered) and SlideToConfirm CSS on `nowo_ui_styles`; SlideToConfirm,
Device Intelligence and OTP input scripts on `nowo_ui_scripts` — every `<script>` (external and inline) carries the
request `csp_nonce`. Only local asset packages, never a CDN.

## Theme exception (`theme.bundles.NowoAuthKitBundle`)

Per-bundle classes / attributes go in `nowo_ui_kit.theme.bundles.NowoAuthKitBundle` (and `.pages.auth`). The deprecated
`css.button_class` / `css.secondary_button_class` (any profile; first non-default value) are bridged there as
`classes.btn_primary` / `classes.btn_secondary` (prepended, so a host's own exception wins).

## Allowed exceptions

- (none) — `Tests\Integration\Contract\PageContractTest` runs with an empty `allowedClasses()` for the `none`,
  `bootstrap5` and `tailwind` frameworks.
