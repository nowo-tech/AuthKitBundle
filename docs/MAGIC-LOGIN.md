# Magic login (passwordless)

Auth Kit can expose a **passwordless** sign-in flow: the user enters their identifier (email), receives a one-time URL, and clicking it authenticates them via Symfony `login_link`.

This is **not** the same as password-reset delivery links.

## Table of contents

- [Configuration](#configuration)
- [Security (`login_link`)](#security-login_link)
- [Delivery (`MagicLoginNotifierInterface`)](#delivery-magicloginnotifierinterface)
- [Events](#events)
- [Administrator-issued links](#administrator-issued-links)
- [Availability checker](#availability-checker)
- [Turning the feature off](#turning-the-feature-off)
- [Security notes](#security-notes)
- [Templates](#templates)

## Configuration

```yaml
nowo_auth_kit:
    magic_login:
        mode: enabled          # disabled | enabled
        lifetime: 600          # seconds (Symfony login_link lifetime)
        max_uses: 1
        # Recommended with login_link.check_post_only: GET shows a confirm form; POST authenticates.
        confirm_interstitial: false
```

Routes (defaults):

| Key | Path | Name |
|-----|------|------|
| `magic_login_request` | `/magic-login` | `nowo_auth_kit_magic_login_request` |
| `magic_login_check` | `/magic-login/check` | `nowo_auth_kit_magic_login_check` |
| `magic_login_confirm` | `/magic-login/confirm` | `nowo_auth_kit_magic_login_confirm` |

When `confirm_interstitial` is `true`:

1. Email link lands on **GET** `magic_login_check` (with `login_link.check_post_only` so GET does not authenticate).
2. The interstitial renders `MagicLoginConfirmType` (signed hiddens + **Form CSRF**).
3. **POST** goes to `magic_login_confirm`, which validates CSRF, calls `LoginLinkHandlerInterface::consumeLoginLink()`, then `Security::login(..., 'login_link')`.

## Security (`login_link`)

Magic login requires the firewall `login_link` block. Run:

```bash
php bin/console nowo:auth-kit:configure-security
```

Or add manually:

```yaml
security:
    firewalls:
        main:
            login_link:
                check_route: nowo_auth_kit_magic_login_check
                signature_properties: [email, password]  # required; a change of these voids pending links
                lifetime: 3600                 # >= magic_login.lifetime and magic_login.admin_link.lifetime
                max_uses: 1
                check_post_only: true          # pair with magic_login.confirm_interstitial: true
                default_target_path: homepage   # optional
```

Keep `magic_login_check` (and `magic_login_confirm` when using the interstitial) as **public** `access_control` paths.

`signature_properties` is **required** by Symfony. Since 1.31 `nowo:auth-kit:configure-security` writes `[<user_identifier_field>, password]` (a password change voids pending links) and `lifetime: max(magic_login.lifetime, magic_login.admin_link.lifetime)`. Each firewall serving a profile with magic login needs its own `login_link`: links are signed and consumed by the handler of the profile's firewall. When `magic_login.confirm_interstitial` is `true`, the command also sets `check_post_only: true`.

## Delivery (`MagicLoginNotifierInterface`)

```php
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;

final class AppMagicLoginNotifier implements MagicLoginNotifierInterface
{
    public function notify(MagicLoginNotificationContext $context): void
    {
        // Send ONLY to $context->identifier (the identifier STORED on the account, 1.31+) or to an address read from
        // $context->user — never to a value taken from the request (SEC-004 H1).
        // $context->loginUrl — absolute signed URL
        // $context->expiresAt
        // $context->maskedIdentifier
        // $context->recipientLocale — account locale (1.23+, see CONFIGURATION.md#recipient-locale-notifications); loginUrl already uses it
    }
}
```

```yaml
# config/services.yaml
Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface:
    alias: App\Security\AppMagicLoginNotifier
```

Bundled samples: `NullMagicLoginNotifier` (default), `LoggingMagicLoginNotifier` (metadata only — never logs the magic URL).

## Events

Subscribe to `MagicLoginRequestedEvent` for audit or rate limiting.

## Administrator-issued links

1.29+: `Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer` lets an administration screen issue a sign-in link for
another account (support sign-in) — the same signed `login_link`, in the recipient's locale, against
`absolute_url.base_uri`:

```php
$link = $issuer->issue($admin, $member);              // LoginLinkDetails: show it once, never store it
$expires = $issuer->send($admin, $member);            // e-mailed through MagicLoginNotifierInterface
$issuer->canIssue($admin, $member);                   // hide the action when false
```

- **Never for the issuer's own account** nor for an account with a protected role
  (`magic_login.admin_link.protected_roles`, default `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`): `issue()` / `send()` throw
  `AdminMagicLinkRefusedException` (`reason()`: `own_account`, `protected_role`, `feature_disabled` with
  `magic_login.mode: disabled` since 1.37, or `not_configured` without a firewall `login_link`).
- **Lifetime** `magic_login.admin_link.lifetime` (default 3600 s). **Set the firewall `login_link.lifetime` to at least
  this value**: Symfony remembers a used link only for its own `lifetime`, so a shorter firewall lifetime lets a used
  admin link be replayed until the link expires (`configure-security` does this since 1.31). `password` in
  `signature_properties` makes a password change void pending links.
- **Audit:** every link dispatches `AdminMagicLinkIssuedEvent` (`issuer`, `target`, `delivery` `shown` | `email`,
  `expiresAt`; never the URL). Rate-limit the action in the host (e.g. per administrator).

```yaml
nowo_auth_kit:
    profiles:
        default:
            magic_login:
                admin_link:
                    lifetime: 3600
                    protected_roles: [ROLE_ADMIN, ROLE_SUPER_ADMIN]
```

## Availability checker

`magic_login.availability_checker` (1.37, per profile, default null) names a service implementing
`Nowo\AuthKitBundle\Availability\FeatureAvailabilityCheckerInterface::isAvailable(string $profile): bool`, for hosts
where the feature depends on runtime state such as a mail transport stored in the database.

When it answers false, or throws (logged, class only):

- the link leaves the sign-in page and the template flag `magic_login_enabled` is false;
- `/magic-login` (the request route) answers 404, GET and POST.

When it answers true the link shows even if `outbound_mail_ready_checker` says mail is not ready: the checker replaces
that hint for this link. With `mode: disabled` nothing changes (the redirect to the sign-in page stays). A signed-in
visitor is still redirected as before. The container refuses a missing service, or one of another type when its class
is known at compile time; anything else that is not a checker counts as unavailable at runtime (logged).

This is an operational gate, not a kill switch: `magic_login.mode: disabled` is the kill switch (below). Only the request route is gated: a link already issued, including an
administrator's copy-and-send link (which needs no mail), still signs in until it expires, because the firewall's
`login_link` consumes it before any controller runs. While unavailable, a failed confirm goes to the sign-in page.
`AdminMagicLinkIssuer` and `MagicLoginRequestHandler` called from host code are not gated.

## Turning the feature off

Since 1.37 `magic_login.mode: disabled` also stops links already issued, on the AuthKit check route and confirm page,
and `AdminMagicLinkIssuer` refuses with `feature_disabled` (`canIssue()` is false). A `login_link` you run on your own
route, outside AuthKit's routes, is not affected. Before 1.37 an issued link kept working: there, set
`routing.skip_disabled_features: true` or remove `login_link` from the firewall (`nowo:auth-kit:configure-security`
does it). To void links for good, rotate the secret or sign `password` in `signature_properties`.

## Security notes

- The request step **never reveals** whether the identifier exists (anti-enumeration).
- Built-in request rate limit via `cache.app` (`request_rate_limit` / `request_rate_window`; `0` disables).
- If firewall `login_link` is missing, the handler skips silently (warning log) instead of returning 500.
- Tokens are signed by Symfony `login_link` (not stored on the user entity).
- No extra entity fields are required.
- Set `absolute_url.base_uri` (1.24+, e.g. `'%env(DEFAULT_URI)%'`) so the mailed link is built from a fixed origin, not the request `Host` header (password-reset poisoning). See [CONFIGURATION.md](CONFIGURATION.md#absolute-url-base-mailed--qr-links).

## Templates

Override:

- `templates/bundles/NowoAuthKitBundle/security/magic_login_request.html.twig`
- `templates/bundles/NowoAuthKitBundle/security/magic_login_confirm.html.twig` (when `confirm_interstitial` is enabled)

Or set `templates.magic_login_confirm` in the profile. The confirm page receives `magic_login_confirm_form`, plus BC keys `action` / `params` (`user` / `expires` / `hash`), `login_route`, and `layout_template`.

The login page shows a magic-login link when `magic_login.mode: enabled`.
