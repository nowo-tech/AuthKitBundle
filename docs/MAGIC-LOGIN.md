# Magic login (passwordless)

Auth Kit can expose a **passwordless** sign-in flow: the user enters their identifier (email), receives a one-time URL, and clicking it authenticates them via Symfony `login_link`.

This is **not** the same as password-reset delivery links.

## Table of contents

- [Configuration](#configuration)
- [Security (`login_link`)](#security-login_link)
- [Delivery (`MagicLoginNotifierInterface`)](#delivery-magicloginnotifierinterface)
- [Events](#events)
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
                signature_properties: [email]  # required; properties that invalidate links when changed
                lifetime: 600
                max_uses: 1
                check_post_only: true          # pair with magic_login.confirm_interstitial: true
                default_target_path: homepage   # optional
```

Keep `magic_login_check` (and `magic_login_confirm` when using the interstitial) as **public** `access_control` paths.

`signature_properties` is **required** by Symfony (typically your user identifier field, e.g. `email`). `nowo:auth-kit:configure-security` writes it from `user_identifier_field`. When `magic_login.confirm_interstitial` is `true`, the command also sets `check_post_only: true`.

## Delivery (`MagicLoginNotifierInterface`)

```php
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;

final class AppMagicLoginNotifier implements MagicLoginNotifierInterface
{
    public function notify(MagicLoginNotificationContext $context): void
    {
        // $context->loginUrl — absolute signed URL
        // $context->expiresAt
        // $context->maskedIdentifier
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

## Security notes

- The request step **never reveals** whether the identifier exists (anti-enumeration).
- Built-in request rate limit via `cache.app` (`request_rate_limit` / `request_rate_window`; `0` disables).
- If firewall `login_link` is missing, the handler skips silently (warning log) instead of returning 500.
- Tokens are signed by Symfony `login_link` (not stored on the user entity).
- No extra entity fields are required.

## Templates

Override:

- `templates/bundles/NowoAuthKitBundle/security/magic_login_request.html.twig`
- `templates/bundles/NowoAuthKitBundle/security/magic_login_confirm.html.twig` (when `confirm_interstitial` is enabled)

Or set `templates.magic_login_confirm` in the profile. The confirm page receives `magic_login_confirm_form`, plus BC keys `action` / `params` (`user` / `expires` / `hash`), `login_route`, and `layout_template`.

The login page shows a magic-login link when `magic_login.mode: enabled`.
