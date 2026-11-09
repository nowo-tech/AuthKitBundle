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
  - [Constraint messages](#constraint-messages)
- [Registration flow](#registration-flow)
- [Invitation flow](#invitation-flow)
- [Login flow](#login-flow)
- [Embedded login/register (dropdown)](#embedded-loginregister-dropdown)
  - [Enable](#enable)
  - [Render in Twig](#render-in-twig)
  - [Authenticated state](#authenticated-state)
  - [Demo reference](#demo-reference)
- [Locale in URL paths](#locale-in-url-paths)
  - [Twig links](#twig-links)
  - [access_control](#access_control)
- [Registration link on login page](#registration-link-on-login-page)
- [Account switcher avatars (1.35)](#account-switcher-avatars-135)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)

## Twig template overrides (REQ-TWIG-001)

Bundle templates use the `@NowoAuthKitBundle` namespace. Since 1.27 every auth page is the **UiKit auth scaffold**
(`@NowoUiKit/page/auth.html.twig`, REQ-UI-003) on the host's guest layout — see [TEMPLATES.md](TEMPLATES.md) for the
pages, data blocks and view-model fields.

### Theme once, do not fork pages

1. Point `nowo_ui_kit.layouts.guest` at your guest layout (or a one-file bridge over your base layout that renders the
   UiKit canonical blocks; the demo's `templates/layouts/guest.html.twig`).
2. Theme `nowo_ui_kit.theme` (tokens + class map); per-bundle exceptions in `theme.bundles.NowoAuthKitBundle`.
3. Optionally override `templates/bundles/NowoUiKitBundle/page/auth.html.twig` once for every auth page of every bundle.

Every auth form is a Symfony Form Type that is also a FormKit Type (profile `auth_kit`, see
[CONFIGURATION.md](CONFIGURATION.md#formkit-profile-auth_kit)), rendered with `form_start` + the
`{% if not child.rendered %}{{ form_row(child) }}{% endif %}` child loop + `form_end`. Host overrides must not
reintroduce raw `<form>` / `<input>` markup (REQ-TWIG-005).

### Override in the application

For a genuinely AuthKit-specific need, point `web_ui.templates.<page>` at a thin template that extends the shipped
one with `@!` and overrides a data block:

```twig
{# templates/auth/login.html.twig #}
{% extends '@!NowoAuthKit/security/login.html.twig' %}

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

### Constraint messages

Form constraint messages raised by AuthKit forms (`register.password.mismatch`, `reset.code.code_length`, …) live in the **`NowoAuthKitBundle`** catalogues like every other AuthKit string (REQ-I18N-003: the bundle ships no other domain). The Symfony validator translates violations in the global `validators` domain, so `Form\Extension\ConstraintMessageTranslationExtension` rebuilds the AuthKit errors in the form translation domain once the form is validated. Override them in `translations/NowoAuthKitBundle.<locale>.yaml`.

BC: a key you already override in `translations/validators.<locale>.yaml` (AuthKit <= 1.30) keeps winning; move it to `NowoAuthKitBundle.<locale>.yaml` when convenient.

## Registration flow

1. Guest opens `/register` (or configured path).
2. `RegistrationGate` checks `registration_mode`.
3. On valid submit, `UserRegistrar` creates the entity, hashes password fields, assigns `registration_role`, persists. Fields with `mapped: false` (default for `slide_to_confirm`) are not written to the user entity.
4. Optional slide-to-confirm on a consent checkbox is UX only — see [CONFIGURATION.md](CONFIGURATION.md#slide-to-confirm-optional).
5. Optional device intelligence (`device_rate_limit`) adds an extra limiter key by device ULID after a successful `collect()` — see [CONFIGURATION.md](CONFIGURATION.md#device-intelligence-optional).
6. User is logged in on the configured firewall and redirected to `login_success_route` or login.

## Invitation flow

With `invitations.mode: enabled` (1.32, often with `registration_mode: invite_only`):

1. An administrator invites an e-mail on `/admin/invitations/new` (UiKit form page, FormKit `InvitationFormType`) or
   through `InvitationManager::invite()`; the single-use link is e-mailed (Symfony Mailer) and only its hash stored.
2. The invitee opens `/invitation/{token}`; the token hash moves to the session and the page redirects to
   `/invitation`, the registration form with the invited e-mail pre-filled (locked with `lock_email`).
3. On valid submit the account is created like a registration (fields, hashing, `registration_role` plus the
   invitation roles), the invitation is claimed (single use), the `InvitationGrantInterface` services run, and the
   user is signed in.
4. Host code calling `InvitationManager` directly passes the **signed-in user** as the actor (never a user object
   rebuilt from request data): the role rule trusts that object's roles.
5. `/admin/invitations` lists invitations with Resend (new link) and Revoke (FormKit CSRF-only forms behind the UiKit
   confirm dialog). Every page is a Symfony Form / FormKit type rendered with `form_start` / child loop /
   `form_end` — never raw `<form>` / `<input>` in overrides.

Details: [INVITATIONS.md](INVITATIONS.md).

### Host with its own invitation UI

Since 1.33 a host can keep AuthKit's storage, accept page and rules while issuing invitations from its own pages:

- `invitations.admin_routes: false` — the `/admin/invitations` pages are not registered (the accept route stays).
- `invitations.registration_role` — invitees get this base role instead of `registration_role` (e.g. `ROLE_ADMIN`
  for the first user). Use `registration_mode: invite_only` in production; `disabled` refuses sending and accepting.
- `InvitationRequest(returnLink: true)` and `InvitationManager::resend($actor, $invitation, ?int $ttlDays, bool
  $returnLink)` — no e-mail; the outcome carries the link for the host's own mail (never logged).
- `invitations.merge_key` / `payload_merge: deep` — one open invitation per e-mail and payload key (e.g. an
  organization), payloads merged deeply on re-invite.
- `InvitationAcceptingEvent::refuse(new InvitationRefusal('key', [], 'domain'))` or
  `InvitationRefusedException::because(…)` in a grant — refuse with a translated reason shown to the invitee.
- `InvitationLinkRejectedEvent` — an expired or revoked link was opened (snapshot and reason, no token).
- `InvitationAcceptor::pending()` / `acceptPendingForSocial()` — social-login acceptance of the invitation pending in
  the session (verified, equal e-mail); never read `_nowo_auth_kit_invitation.<profile>` yourself.

Full example: [INVITATIONS.md — Host with its own invitation UI](INVITATIONS.md#host-with-its-own-invitation-ui-133).

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
| `both` | `/en/login` **and** `/login` (`nowo_auth_kit_login_unlocalized`; suffix configurable with `locale.unlocalized_route_suffix`, 1.30+) |
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

## Account switcher avatars (1.35)

With `multi_account.enabled`, the switcher (`@NowoAuthKitBundle/accounts/_switcher.html.twig`) shows the avatar your
`AccountPresenterInterface` returns, through UiKit's avatar partial:

```php
use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountPresenterInterface;

final class AppAccountPresenter implements AccountPresenterInterface
{
    public function present(UserInterface $user): AccountPresentation
    {
        // A stable, access-checked, host-generated URL (same-origin /path or https://), never a user-entered one.
        return new AccountPresentation($user->getDisplayName(), $user->getUserIdentifier(), $this->avatars->urlFor($user, 32));
    }
}
```

```yaml
nowo_auth_kit:
    multi_account:
        enabled: true
        presenter: App\Security\AppAccountPresenter
        # chooser: { other_account_avatars: false }   # default: only the active account shows its picture
```

The picture goes over the initials after UiKit's `AvatarUrlPolicy`; the other accounts of the browser show their
initials unless `chooser.other_account_avatars: true` (signed-in browsers only: anonymous pages never show a picture); a broken picture falls back to the initials (UiKit's
`js/nowo-ui-avatar.js`). Details: [MULTI-ACCOUNT.md](MULTI-ACCOUNT.md#avatars-135).

## Using it from a DDD / hexagonal application

AuthKit is an outer-ring package: Symfony controllers, forms and Twig (presentation) plus adapters for your security
user (infrastructure). It also owns a few auth-only tables (`auth_kit_*`: invitations, MFA, QR login, social accounts,
account vault). Those tables reference your user only by class + id or identifier strings — there are no Doctrine
relations to your entities, no `resolve_target_entities`, no base class or trait to extend. Turn the mapping off or on
per need with `nowo_auth_kit.doctrine.register_mapping` (`true`, `false`, `auto`) and prefix the tables with
`doctrine.table_prefix`.

### Keep the domain free of the bundle

- Point `profiles.<name>.user_class` at your **security user** (the class your user provider returns, implementing
  `UserInterface` and `PasswordAuthenticatedUserInterface`), not at a domain aggregate. Login goes through your
  firewall and user provider.
- **User ports (1.41).** AuthKit creates, stores and finds users only through three interfaces (namespace
  `Nowo\AuthKitBundle\User`). The defaults reproduce the earlier behaviour (Doctrine entity, no-argument constructor,
  PropertyAccessor writes); alias them to your own adapters when the security user is not a Doctrine entity or your
  domain owns persistence:

  | Port | Used by | Default |
  |---|---|---|
  | `UserFactoryInterface::create(ProfileSettings, NewUser)` | registration, invited registration, social sign-up, `nowo:auth-kit:create-admin` | `PropertyAccessUserFactory` |
  | `UserWriterInterface` (`add`, `remove`, `changePassword`, `save`) | the same flows, password reset completion, social `link_existing_account: reset_password` | `Doctrine\DoctrineUserWriter` |
  | `UserLookupInterface` (`findByIdentifier`, `findById`, `countUsers`, `hasCaseVariant`) | password reset, magic login, QR completion, MFA admin, social sign-in, registration gate, `first_user_only`, create-admin | `Doctrine\DoctrineUserLookup` |

  `NewUser` is a plain DTO: the source (`UserCreationSource`: `form`, `invitation`, `social`, `admin`), the roles, the
  submitted registration form (`formData`, keyed by `registration_fields[].name`, values still plain), or the
  identifier / plain password / display name. A writer signals a taken unique value with `DuplicateUserException`.
  `findByIdentifier()` must keep the exact stored-identifier rule (NFC + lower-case, no collation look-alike; see
  `Security\UserIdentifierMatcher`).

  ```yaml
  # config/services.yaml
  Nowo\AuthKitBundle\User\UserFactoryInterface: '@App\Infrastructure\AuthKit\AuthKitUsers'
  Nowo\AuthKitBundle\User\UserWriterInterface: '@App\Infrastructure\AuthKit\AuthKitUsers'
  Nowo\AuthKitBundle\User\UserLookupInterface: '@App\Infrastructure\AuthKit\AuthKitUsers'
  ```

- React to AuthKit in your application layer through events and translate them into your own domain events:
  - `User\Event\UserRegisteredEvent` (1.41): profile, user class, `userId` (`(string) getId()`), identifier, source,
    roles — scalars only. Dispatched once the account is stored: after the flush of the registration page and of
    admin creation, after the commit of an invitation acceptance or of `SocialAccountLinker::linkAndSignIn()`. Not
    dispatched for a rolled-back account. `UserRegistrar::registerInvited()` called directly does not dispatch it.
  - `PasswordReset\PasswordResetCompletedEvent` (1.41): profile, user class, `userId`, identifier, after the new hash
    is stored.
  - `AdminUserCreatedEvent`, `PasswordResetRequestedEvent`, `MagicLoginRequestedEvent`, `AdminMagicLinkIssuedEvent`,
    `Invitation\Event\*` (payload `InvitationSnapshot`, a DTO), `Mfa\Event\*`, `MultiAccount\Event\*`,
    `QrLogin\Event\*`.
- **Credentials changed outside Doctrine (1.41).** Reset credentials and MFA trusted devices are invalidated by Doctrine
  `onFlush` listeners when the identifier or the password property (`password_reset.password_field` and
  `mfa.trusted_devices.password_field`, default `password`) of a user entity changes. When your domain changes the
  password elsewhere, dispatch `User\Event\CredentialsChangedEvent($user)` after your commit: AuthKit forgets the MFA
  trusted devices and clears the pending reset credential through `PasswordResetTokenManagerInterface`.
- Plug your own rules and delivery in through the ports:
  `PasswordResetNotifierInterface`, `MagicLoginNotifierInterface`, `NewDeviceLoginNotifierInterface`,
  `InvitationMailerInterface` (aliases); `InvitationGrantInterface`, `InvitationActionAuthorizerInterface`,
  `SocialCallbackHandlerInterface` (autoconfigured tags); `InvitationsAccessCheckerInterface`,
  `MfaPolicyInterface`, `AccountPresenterInterface`, `EnrolmentGateInterface`, `FeatureAvailabilityCheckerInterface`,
  `OutboundMailReadyCheckerInterface`, `RecipientLocaleResolverInterface` (service ids in config).
  `PasswordResetTokenManagerInterface` can be re-aliased to keep reset credentials out of the user entity.

### Example: a user adapter over your domain

```php
namespace App\Infrastructure\AuthKit;

use App\Domain\User\User;                 // your aggregate (named constructor, no setters)
use App\Domain\User\UserRepository;       // your port
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\{DuplicateUserException, NewUser, UserFactoryInterface, UserLookupInterface, UserWriterInterface};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\{PasswordAuthenticatedUserInterface, UserInterface};

final readonly class AuthKitUsers implements UserFactoryInterface, UserWriterInterface, UserLookupInterface
{
    public function __construct(private UserRepository $users, private UserPasswordHasherInterface $hasher) {}

    public function create(ProfileSettings $profile, NewUser $newUser): UserInterface&PasswordAuthenticatedUserInterface
    {
        $email = $newUser->identifier ?? $newUser->formData['email'];
        $user  = SecurityUser::pending($email, $newUser->roles);             // your security-user adapter
        $user->hash($this->hasher->hashPassword($user, $newUser->plainPassword ?? $newUser->formData['password']));

        return $user;
    }

    public function add(ProfileSettings $profile, UserInterface $user): void
    {
        if ($this->users->emailTaken($user->getUserIdentifier())) {
            throw new DuplicateUserException('E-mail taken.');
        }
        $this->users->add(User::register($this->users->nextId(), $user->getUserIdentifier(), $user->getPassword(), $user->getRoles()));
    }

    // remove(), changePassword(), save(), findByIdentifier(), findById(), countUsers(), hasCaseVariant() …
}
```

### Example: invitation grant as an adapter

```php
namespace App\Infrastructure\AuthKit;

use App\Application\Membership\AddMemberToTeam;   // your use case
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TeamInvitationGrant implements InvitationGrantInterface
{
    public function __construct(private AddMemberToTeam $addMember) {}

    public function grant(InvitationSnapshot $invitation, UserInterface $user): void
    {
        // Translate the bundle DTO into your command; the domain never sees AuthKit types.
        ($this->addMember)($user->getUserIdentifier(), $invitation->payload['team'] ?? null);
    }
}
```

The class is autoconfigured with the `nowo_auth_kit.invitation_grant` tag.

### Known limitations

- Password-reset credentials are stored on the user (`password_reset.token_field`, `token_expires_field`) and found
  with a DQL query on that column by the default `PasswordResetTokenManagerInterface`; with a non-Doctrine user,
  provide your own implementation (the default one, and therefore `CredentialsChangedEvent`, need those columns).
- The invitation acceptance and `SocialAccountLinker::linkAndSignIn()` open their transaction on the bundle's entity
  manager connection: a host writer on another connection is not rolled back with them.
- `UserRegisteredEvent` / `PasswordResetCompletedEvent` follow the bundle's flush; if you call `UserRegistrar` or
  `PasswordResetCompleter` inside your own transaction, they fire before your commit.
- QR login events carry the `QrLoginChallenge` entity, and `InvitationActionAuthorizerInterface` receives the
  `Invitation` entity.
