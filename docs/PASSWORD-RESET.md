# Password reset

Auth Kit exposes a **request → deliver credential → complete** flow aligned with login and registration.

## Configuration

```yaml
nowo_auth_kit:
    password_reset:
        mode: enabled          # disabled | enabled
        delivery: link         # link | code | both
        token_ttl: 3600
        token_field: passwordResetToken
        token_expires_field: passwordResetExpiresAt
```

| Delivery | Use case |
|----------|----------|
| `link` | Email magic link, deep link, QR with URL |
| `code` | SMS OTP, email numeric code, authenticator |
| `both` | Email with link **and** backup code |

## User entity

Add nullable fields (names configurable via `token_field` / `token_expires_field`):

```php
private ?string $passwordResetToken = null;
private ?\DateTimeImmutable $passwordResetExpiresAt = null;
```

The bundle stores **hashed** credentials only.

## Implement delivery (`PasswordResetNotifierInterface`)

```php
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;

final class AppPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        // $context->resetUrl — absolute URL (link / both)
        // $token->linkToken() — path segment for link delivery
        // $token->code() — OTP for code / both
        // $context->maskedIdentifier — safe for logs/UI
    }
}
```

Register in `config/services.yaml`:

```yaml
Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface:
    alias: App\Security\AppPasswordResetNotifier
```

### Bundled samples

- `NullPasswordResetNotifier` — default (no delivery)
- `LoggingPasswordResetNotifier` — logs link/code (dev)
- `demo/*/src/Security/DemoPasswordResetNotifier.php` — demo wiring

## Events

Subscribe to `PasswordResetRequestedEvent` to add webhooks, rate limits, or audit logs without implementing the full notifier.

## Security

Run `php bin/console nowo:auth-kit:configure-security` to add public `access_control` paths for reset routes.

The request step **never reveals** whether the identifier exists (anti-enumeration).

## Templates

Override under `templates/bundles/NowoAuthKitBundle/security/`:

- `reset_request.html.twig`
- `reset_password.html.twig` (link flow)
- `reset_password_code.html.twig` (code flow)
