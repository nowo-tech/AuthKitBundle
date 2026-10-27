# Password reset

Auth Kit exposes a **request → deliver credential → complete** flow aligned with login and registration.

## Table of contents

- [Configuration](#configuration)
- [OTP input (optional)](#otp-input-optional)
- [Check-email page and token in session (optional)](#check-email-page-and-token-in-session-optional)
- [Custom token storage and throttling](#custom-token-storage-and-throttling)
- [User entity](#user-entity)
- [Implement delivery (`PasswordResetNotifierInterface`)](#implement-delivery-passwordresetnotifierinterface)
  - [Bundled samples](#bundled-samples)
- [Events](#events)
- [Security](#security)
- [Templates](#templates)

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

With `code` or `both` the reset request page links the code-entry page ("I already have a code") so a user who
already holds a code can enter it directly.

### OTP input (optional)

When `otp_input.enabled` is true, `otp_input.password_reset_code` is true, and `nowo-tech/otp-input-bundle` is installed, the code-completion form (`ResetPasswordCodeFormType`) uses `OtpType` instead of a single text field. Length and charset still come from `password_reset.code_length` / `code_charset`. The widget is UX only — hashed comparison and `max_code_attempts` are unchanged. See [CONFIGURATION.md](CONFIGURATION.md#otp-input-optional).

## Check-email page and token in session (optional)

Since 1.30. Both options are off by default.

```yaml
nowo_auth_kit:
    password_reset:
        after_request_route: app_check_email   # default null: back to the login page with a flash
        token_in_session: true                 # default false
```

- `after_request_route`: after a valid submit the request page redirects to this host route (generated with the
  request `_locale` when `locale.in_path` uses prefixes, like `login_success_route`) instead of the login page, and
  stores a `Nowo\AuthKitBundle\PasswordReset\PasswordResetCheckEmail` in the session. The payload is the same for
  every submit, whether or not the identifier exists (`profile`, `tokenTtl` in seconds), plus `throttled` /
  `availableAt` (see below). The host page reads it:

  ```php
  $checkEmail = PasswordResetCheckEmail::fromRequest($request); // null when the page is opened directly
  // e.g. "The link expires in {{ checkEmail.tokenTtl / 60 }} minutes."
  ```

- `token_in_session` (link delivery): opening `/reset-password/reset/{token}` stores the token in the session
  (`ResetPasswordController::SESSION_TOKEN_KEY` + profile name) and redirects to `/reset-password/reset` (the route's
  `token` becomes optional), so the token never stays in the address bar, history or `Referer`. The form is rendered
  and submitted on the token-less URL; the session token is removed after a successful reset or when it is invalid.

## Custom token storage and throttling

`PasswordResetTokenManagerInterface` is an extension point: alias it to your own service to keep reset credentials
elsewhere (e.g. a `reset_password_request` table managed by symfonycasts/reset-password-bundle).

When your `createForUser()` refuses to issue a new credential because one was requested too recently, throw
`Nowo\AuthKitBundle\PasswordReset\PasswordResetThrottledException` (optionally with the `DateTimeImmutable` of the
next accepted request). `PasswordResetRequestHandler::handle()` catches it, sends no notification and returns a
`PasswordResetRequestOutcome` with `throttled: true`. The request page still answers as for any other submit (no user
enumeration); with `after_request_route` the check-email payload carries `throttled` and `availableAt`, so the host page
can choose to mention it.

```php
public function createForUser(object $user): PasswordResetTokenResult
{
    try {
        $token = $this->resetPasswordHelper->generateResetToken($user);
    } catch (TooManyPasswordRequestsException $e) {
        throw new PasswordResetThrottledException(\DateTimeImmutable::createFromInterface($e->getAvailableAt()), previous: $e);
    }

    return new PasswordResetTokenResult($user, $token->getToken(), $token->getExpiresAt(), PasswordResetDeliveryMode::Link);
}
```

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
        // Send ONLY to $context->identifier (the identifier STORED on the account, 1.31+) or to a channel read from
        // $context->user (the account) — never to a value taken from the request (SEC-004 H1).
        // $context->resetUrl — absolute URL (link / both)
        // $token->linkToken() — path segment for link delivery
        // $token->code() — OTP for code / both
        // $context->maskedIdentifier — safe for logs/UI
        // $context->recipientLocale — account locale (1.23+, see CONFIGURATION.md#recipient-locale-notifications); resetUrl already uses it
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
- `LoggingPasswordResetNotifier` — logs metadata only (masked identifier, delivery, expiry); never URLs/tokens/codes (dev sample)
- `demo/*/src/Security/DemoPasswordResetNotifier.php` — demo wiring

## Events

Subscribe to `PasswordResetRequestedEvent` to add webhooks, rate limits, or audit logs without implementing the full notifier.

## Security

Run `php bin/console nowo:auth-kit:configure-security` to add public `access_control` paths for reset routes.

The request step **never reveals** whether the identifier exists (anti-enumeration).

Built-in (via `cache.app`): `request_rate_limit` / `request_rate_window` on reset requests, and `max_code_attempts` OTP lockout (clears the stored reset credential when exceeded). Set limits to `0` to disable.

Set `absolute_url.base_uri` (1.24+, e.g. `'%env(DEFAULT_URI)%'`) so the mailed link is built from a fixed origin, not the request `Host` header (password-reset poisoning). See [CONFIGURATION.md](CONFIGURATION.md#absolute-url-base-mailed--qr-links).

## Templates

Override under `templates/bundles/NowoAuthKitBundle/security/`:

- `reset_request.html.twig`
- `reset_password.html.twig` (link flow)
- `reset_password_code.html.twig` (code flow)
