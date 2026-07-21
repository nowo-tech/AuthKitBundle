# Magic login (passwordless)

Auth Kit can expose a **passwordless** sign-in flow: the user enters their identifier (email), receives a one-time URL, and clicking it authenticates them via Symfony `login_link`.

This is **not** the same as password-reset delivery links.

## Configuration

```yaml
nowo_auth_kit:
    magic_login:
        mode: enabled          # disabled | enabled
        lifetime: 600          # seconds (Symfony login_link lifetime)
        max_uses: 1
```

Routes (defaults):

| Key | Path | Name |
|-----|------|------|
| `magic_login_request` | `/magic-login` | `nowo_auth_kit_magic_login_request` |
| `magic_login_check` | `/magic-login/check` | `nowo_auth_kit_magic_login_check` |

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
                default_target_path: homepage   # optional
```

Keep `magic_login_check` as a **public** `access_control` path.

`signature_properties` is **required** by Symfony (typically your user identifier field, e.g. `email`). `nowo:auth-kit:configure-security` writes it from `user_identifier_field`.

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

Bundled samples: `NullMagicLoginNotifier` (default), `LoggingMagicLoginNotifier`.

## Events

Subscribe to `MagicLoginRequestedEvent` for audit or rate limiting.

## Security notes

- The request step **never reveals** whether the identifier exists (anti-enumeration).
- Tokens are signed by Symfony `login_link` (not stored on the user entity).
- No extra entity fields are required.

## Templates

Override `templates/bundles/NowoAuthKitBundle/security/magic_login_request.html.twig`.

The login page shows a magic-login link when `magic_login.mode: enabled`.
