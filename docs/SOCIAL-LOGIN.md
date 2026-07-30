# Social login (OAuth)

AuthKit can expose **Continue with …** buttons on the login page. Provider **client id / client secret** and per-user **access/refresh tokens** are stored in Doctrine tables owned by the bundle.

## Tables

| Entity | Table | Purpose |
|--------|-------|---------|
| `SocialLoginCredential` | `auth_kit_social_credential` | OAuth app credentials per provider (`google`, `github`, `microsoft`, or custom) |
| `SocialLoginAccount` | `auth_kit_social_account` | Linked local user + tokens + provider subject |

Run migrations / `doctrine:schema:update` after enabling the feature so both tables exist.

## Configuration

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                mode: enabled
                create_user_if_missing: true
                require_verified_email: true
```

- **`mode: enabled`** alone is not enough: the login UI only shows buttons when at least one **enabled** row exists in `auth_kit_social_credential` (start/check handlers redirect to login otherwise).
- **`create_user_if_missing`**: create a local user from the social email when no account matches; otherwise require a pre-existing user (matched by `user_identifier_field`).
- **`require_verified_email`**: when true (default), linking or creating a local user requires the IdP to assert a verified email (`email_verified` / GitHub `verified`). Prevents account takeover via unverified provider emails.

## Seed credentials (example)

```php
$credential = (new \Nowo\AuthKitBundle\Entity\SocialLoginCredential())
    ->setProvider('google')
    ->setLabel('Google')
    ->setClientId($clientId)
    ->setClientSecret($clientSecret)
    ->setEnabled(true);
// Optional: setAuthorizeUrl / tokenUrl / userinfoUrl / scopes for custom IdPs.
$em->persist($credential);
$em->flush();
```

Built-in endpoint defaults exist for `google`, `github`, and `microsoft`. Custom providers must set the three URLs on the credential.

## Security / routes

Public routes (also locale-prefixed when `locale.in_path` is `always`/`both`):

- `GET /login/social/{provider}` → start
- `GET /login/social/{provider}/check` → callback

Add matching `access_control` entries (`PUBLIC_ACCESS`), same pattern as magic login / password reset. The OAuth redirect URI registered at the IdP must match the **absolute** check URL (including locale prefix if you use one).

## Login template variables

| Variable | Meaning |
|----------|---------|
| `social_login_enabled` | Mode enabled **and** enabled credentials in DB |
| `social_login_providers` | List of `SocialLoginCredential` |
| `social_login_route` | Start route name |

Override `templates/bundles/NowoAuthKitBundle/security/login.html.twig` to restyle the buttons.
