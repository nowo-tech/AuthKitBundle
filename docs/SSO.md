# Enterprise SSO (OIDC)

AuthKit exposes **organization / SSO** sign-in by reusing the existing OAuth/OIDC social-login pipeline with an `enterprise_sso` flag on `SocialLoginCredential`.

**Status (v1.12.0):** OIDC SSO via social credentials is supported. Native SAML 2.0 is **not** implemented yet (see roadmap below).

## Table of contents

- [How it works](#how-it-works)
- [Seed an enterprise IdP](#seed-an-enterprise-idp)
- [Login UI](#login-ui)
- [Security notes](#security-notes)
- [Roadmap](#roadmap)

## How it works

1. Enable `social_login.mode: enabled` (same as consumer social login).
2. Persist a `SocialLoginCredential` with custom authorize/token/userinfo URLs for your IdP (Okta, Azure AD, Keycloak, Auth0, …).
3. Set `enterpriseSso: true` so the login page shows the button under **Sign in with your organization** instead of **Or continue with**.

The start/check routes remain:

| Method | Path | Name |
|--------|------|------|
| `GET` | `/login/social/{provider}` | `nowo_auth_kit_social_login_start` |
| `GET` | `/login/social/{provider}/check` | `nowo_auth_kit_social_login_check` |

## Seed an enterprise IdP

```php
$credential = (new \Nowo\AuthKitBundle\Entity\SocialLoginCredential())
    ->setProvider('acme-oidc')
    ->setLabel('Acme SSO')
    ->setClientId($clientId)
    ->setClientSecret($clientSecret)
    ->setEnabled(true)
    ->setEnterpriseSso(true)
    ->setScopes(['openid', 'profile', 'email'])
    ->setAuthorizeUrl('https://idp.example.com/oauth2/v1/authorize')
    ->setTokenUrl('https://idp.example.com/oauth2/v1/token')
    ->setUserinfoUrl('https://idp.example.com/oauth2/v1/userinfo');
$em->persist($credential);
$em->flush();
```

After upgrading to 1.12.0, update the schema so column `enterprise_sso` exists on `auth_kit_social_credential` (`doctrine:schema:update` or a migration).

## Login UI

| Variable | Meaning |
|----------|---------|
| `sso_login_enabled` | At least one enabled credential with `enterpriseSso=true` |
| `sso_login_providers` | Those credentials |
| `social_login_providers` | Enabled credentials with `enterpriseSso=false` |

Translation keys: `sso.heading`, `sso.continue_with`.

## Security notes

- Prefer `create_user_if_missing: false` for enterprise tenants so only pre-provisioned users can sign in.
- Keep `require_verified_email: true` unless the IdP always asserts a trusted subject that you map another way.
- Register the absolute OAuth redirect URI at the IdP (including locale prefix when used).

## Roadmap

- SAML 2.0 SP (metadata, ACS, signed assertions)
- Per-tenant IdP discovery / domain hint
- Just-in-time role/group mapping from claims
