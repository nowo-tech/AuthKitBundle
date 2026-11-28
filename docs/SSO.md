# Enterprise SSO (OIDC)

AuthKit exposes **organization / SSO** sign-in by reusing the existing OAuth/OIDC social-login pipeline with an `enterprise_sso` flag on `SocialLoginCredential`.

**Status (v1.12.0):** OIDC SSO via social credentials is supported. **2.10.0:** native SAML 2.0 Service Provider sign-in (opt-in, SP-initiated) through the same pipeline: [SAML.md](SAML.md).

## Table of contents

- [How it works](#how-it-works)
- [Seed an enterprise IdP](#seed-an-enterprise-idp)
- [Login UI](#login-ui)
- [Enterprise OIDC per organization (2.7.0)](#enterprise-oidc-per-organization-270)
  - [Unlisted credentials and login_hint](#unlisted-credentials-and-login_hint)
  - [link_existing_account per credential](#link_existing_account-per-credential)
  - [MFA first factor per credential](#mfa-first-factor-per-credential)
  - [Validated ID token claims](#validated-id-token-claims)
  - [Test sign-in](#test-sign-in)
  - [Client secrets at rest](#client-secrets-at-rest)
- [RP-initiated logout (2.8.0)](#rp-initiated-logout-280)
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
    ->setLinkExistingAccount('deny') // 2.7.0: an IdP you do not control never takes over a local account by e-mail
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

## Enterprise OIDC per organization (2.7.0)

For applications where each organization connects its own identity provider (Entra ID, Okta, Google Workspace…):
the host owns the organization side (which credential belongs to which organization, verified domains, home-realm
discovery, provisioning); AuthKit owns the protocol. Use an OpenID Connect credential (`setIssuer()`: discovery, JWKS,
`iss` / `aud` / `exp` / `iat` / `nonce` checks, see [OIDC-PROVIDERS.md](OIDC-PROVIDERS.md)) and the four per-credential
settings below. Every new column is nullable: null keeps the behaviour of 2.6.0.

| Entity method | Column | Null means |
|---------------|--------|-----------|
| `setListed(?bool)` / `isListed()` | `listed BOOLEAN NULL` | listed |
| `setLinkExistingAccount(?string)` / `getLinkExistingAccount()` | `link_existing_account VARCHAR(16) NULL` | the profile's `social_login.link_existing_account` |
| `setMfaFirstFactorPolicy(?string $policy, array $acceptedAmr = [])` / `getMfaFirstFactor()` / `getMfaAcceptedAmr()` | `mfa_first_factor VARCHAR(16) NULL`, `mfa_accepted_amr JSON NULL` | the profile's `mfa.first_factors.social` |

### Unlisted credentials and login_hint

A credential for an organization's own IdP (note the `deny`, see below):

```php
$credential = (new SocialLoginCredential())
    ->setProvider('org-7f3a9c')            // a non-guessable key if the organization's IdP is confidential
    ->setLabel('Acme')
    ->setClientId($clientId)
    ->setClientSecret($clientSecret)       // encrypted at rest only with DoctrineEncrypt configured
    ->setIssuer('https://login.microsoftonline.com/<tenant>/v2.0')
    ->setEnterpriseSso(true)
    ->setListed(false)
    ->setLinkExistingAccount('deny')       // the tenant IdP must never take over a local account by e-mail
    ->setMfaFirstFactorPolicy('exempt_when_amr', ['mfa'])
    ->setEnabled(true);
```

`setListed(false)` keeps a credential out of every provider button list (`social_links`, `sso_links`, the invitation
accept page). It still starts by name, from a host page (for example after home-realm discovery on the e-mail domain):

```php
return $this->redirectToRoute('nowo_auth_kit_social_login_start', [
    'provider'   => 'org-acme',
    'login_hint' => $email, // optional
]);
```

`login_hint` is forwarded to the provider's authorization request only when it is a plain dot-atom e-mail address (no
quoted local part, no IP literal) that `FILTER_VALIDATE_EMAIL` accepts, of at most 254 bytes without white space or
control characters; anything else is dropped silently (never echoed). It
replaces a `login_hint` set in `authorization_params`. The hint is advisory: the address the provider asserts is checked
as for any sign-in and is not compared with the hint.

### link_existing_account per credential

**Set `deny` on every credential whose IdP you do not control.** NULL inherits `social_login.link_existing_account` (default `reset_password`); an IdP run by a tenant can assert any verified e-mail, so with anything but `deny` it can take over any local account with that address (administrators included).

With `setLinkExistingAccount('deny')` a first sign-in whose verified e-mail matches an existing local account is refused
(the account is never linked by e-mail alone; the host runs its own linking challenge, e.g. through a callback handler).
Public providers can keep the profile's `reset_password`. Accepted values: `reset_password`,
`deny`, `keep`. The linker reads the credential's value for every caller (`linkAndSignIn()` and `linkOrCreate()`, host
handlers included); a stored value that is not one of them is read as `deny`.

`invitations.social.accept_page` with a profile-wide `deny` is still refused when the container is built (KL-L3). A
credential's own `deny` is checked at run time instead: the accept page neither offers nor starts that credential.

### MFA first factor per credential

`setMfaFirstFactorPolicy()` decides whether a sign-in with this credential is followed by the user's second step:

- `required`: as any first factor, the second step is asked when the user has MFA;
- `exempt`: never asked after this credential (the provider enforces it); forced enrolment still applies;
- `exempt_when_amr`: exempt only when the `amr` claim of the **validated ID token** names one of the accepted values,
  e.g. `setMfaFirstFactorPolicy('exempt_when_amr', ['mfa', 'hwk'])`; otherwise required.

`amr` is read from the ID token claims only (signature, issuer, audience, expiry and nonce checked). The userinfo answer
completes the profile (`raw`) but is never read for this decision: a userinfo `amr` does not exempt anyone. An OAuth2
credential (no ID token) under `exempt_when_amr` always gets `required`. The policy applies with `mfa.enabled: true`
and to AuthKit's own sign-in as well as to a `SocialCallbackStepInterface` answer that signs in; a value that is not one
of the three is read as `required`. The accepted list holds 1 to 16 printable ASCII tokens of at most 64 characters.

`amr` says how the IdP session authenticated, not when: there is no `auth_time` / `max_age` check, so the exemption
lasts as long as the IdP session. Some IdPs leave `amr` out of their ID tokens (then the second step is always
required). Deciding who may set `exempt` / `exempt_when_amr` (platform or organization administrators) is the host's
call: an exemption trusts that IdP's own MFA.

### Validated ID token claims

`SocialUserProfile::$idTokenClaims` (on `SocialCallbackContext::$socialProfile`) holds the claims of the validated ID
token on their own (`sub`, `email`, `email_verified`, `amr`, `acr`, `hd`, `tid`… when present; `nonce` removed), null for
an OAuth2 provider. `raw` stays the merged profile (userinfo completes it). Read organization claims such as `hd` / `tid`
from `idTokenClaims`, never from `raw`.

### Test sign-in

An administrator's *Test sign-in* checks a credential without signing in. The host mints it; there is no URL flag:

```php
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestStart;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/sso/{provider}/test', methods: ['POST'])] // with a CSRF token
public function test(Request $request, string $provider, SocialLoginTestStart $tests): Response
{
    return $this->redirect($tests->start($request, $provider, $request->request->getString('login_hint') ?: null));
}
```

`start()` stores a one-shot test marker with the OAuth state in the session and returns the provider's authorization
URL. The callback of that state runs every check of a real sign-in, then only the host's
`SocialLoginTestHandlerInterface` (autoconfigured, tag `nowo_auth_kit.social_test_handler`):

```php
final class SsoTestHandler implements SocialLoginTestHandlerInterface
{
    public function handle(AuthRequestContext $request, SocialCallbackContext $context): SocialCallbackResult
    {
        // $context->socialProfile->idTokenClaims: show them to the administrator
        return SocialCallbackResult::redirect('/admin/sso/test-result');
    }

    public function handleFailure(AuthRequestContext $request, string $provider, SocialCallbackFailure $reason): ?SocialCallbackResult
    {
        return SocialCallbackResult::redirect('/admin/sso/test-failed');
    }
}
```

Guarantees: no `SocialCallbackStepInterface` runs, nothing is linked, no account is created and nobody is signed in;
a `SocialCallbackResult::signIn()` answer is refused (failure path). The marker is burned with the state (replay refused).
A disabled credential can be tested (before you enable it); the profile's `social_login.mode` must be `enabled`.
`start()` throws `SocialLoginTestException` (`reason`: `no_handler`, `social_disabled`, `unknown_provider`, `provider`);
`hasHandler()` tells whether a handler is registered. Starting a test replaces a sign-in pending in the same session.

### Client secrets at rest

`client_secret` carries DoctrineEncrypt's `#[Encrypted]`: it is encrypted at rest **only when
`nowo-tech/doctrine-encrypt-bundle` is installed and configured**. Since 2.8.0 an enterprise credential
(`enterprise_sso` true) with a client secret is never written otherwise: the flush throws
`Doctrine\EnterpriseSecretNotEncryptedException` and writes nothing (`doctrine.require_enterprise_secret_encryption`,
default `true`; `false` only for tests and demos). Consumer credentials keep the 2.7.0 behaviour. Keep the secret
write-only in your forms and out of exports and logs (AuthKit never logs it).

```php
try {
    $em->flush();
} catch (EnterpriseSecretNotEncryptedException) {
    // DoctrineEncrypt is not encrypting this entity manager: nothing was written. Configure it, never retry in clear.
}
```

## RP-initiated logout (2.8.0)

OpenID Connect RP-Initiated Logout 1.0, opt-in per credential: signing out of the application also ends the user's
session at the provider.

```php
$credential->setRpInitiatedLogout(true); // null / false (default): local sign-out only
```

```yaml
nowo_auth_kit:
    social_login:
        rp_logout:
            # Register the same URIs at the IdP as post-logout redirect URIs.
            post_logout_redirect_uris: ['/', 'https://app.example.com/signed-out']
```

1. **Sign-in.** After an OIDC sign-in with that credential (AuthKit's linker, or a host callback handler that signs the
   user in), the session keeps a sealed hint: provider key, client id, the signed-in user, the provider's
   `end_session_endpoint` (from its discovery document) and the ID token. A provider whose discovery publishes no
   `end_session_endpoint` (or an unsafe one) gets no hint; an ID token larger than 8 KiB is not kept (the sign-out then
   sends `client_id` only). A new social sign-in replaces or removes the hint. A handler that signs nobody in (a
   linking step) keeps no new hint (a previous one stays bound to its own user): a user the host signs in later, after its own linking challenge (another request), gets a
   **local-only sign-out** in 2.8.0. A public helper to record the hint for such deferred sign-ins may come later.
2. **Sign-out.** The firewall's logout runs as usual; on its `LogoutEvent` (priority 16: after the default target and
   the multi-account sign-out, whose chooser redirect it replaces; before the session is invalidated) AuthKit removes the hint and, when the signed-in user is the one it was issued for and the credential
   is still enabled, opted in and has the same client id, answers a redirect to
   `end_session_endpoint?id_token_hint=…&client_id=…&post_logout_redirect_uri=…`. The local session and token are gone
   either way, so a provider that is down only changes the page the browser lands on.
3. **`post_logout_redirect_uri`** is the sign-out's own target when it is on the allow-list, else the first entry, else
   omitted (the provider shows its own signed-out page). It is never read from the request. No `state` is sent: the
   application's session is already gone when the provider redirects back, so there is nothing to compare it with.

Who may opt a credential in is the host's decision: the `end_session_endpoint` comes from whoever controls the issuer.
Let only platform administrators, or organization administrators of IdPs you trust, set `rp_initiated_logout`
([SECURITY.md](SECURITY.md#enterprise-oidc-270)).

Recommendations:

- Turn on `logout.enable_csrf` on the firewall: with RP-initiated logout a forged sign-out link also ends the IdP
  session, and with `id_token_hint` many IdPs skip their own confirmation.
- Relative allow-list entries (`/signed-out`) are resolved from the request's host: set `framework.trusted_hosts` (the
  IdP still matches the value against the URIs registered for the client).
- The sealed hint is up to about 15 KiB per session: fine for file, Redis or database session storage, too large for
  cookie-based session handlers (do not opt credentials in there).

Local-only sign-outs (no hint in that session): sessions restored by remember-me, impersonation (`switch_user`: the
impersonated user is not the one the hint was issued for), the other accounts of a multi-account
`logout_scope: all`, and deferred sign-ins after a host linking challenge.

Not covered: deferred sign-ins after a host linking challenge (local-only sign-out, see 1.), OAuth2-only credentials (no ID token), front- or back-channel logout (the provider calling the
application), and sign-outs answered with something other than a redirect (an API logout keeps its own answer).

## Security notes

- Prefer `create_user_if_missing: false` for enterprise tenants so only pre-provisioned users can sign in.
- Keep `require_verified_email: true` unless the IdP always asserts a trusted subject that you map another way.
- Register the absolute OAuth redirect URI at the IdP (including locale prefix when used).

## Roadmap

- SAML 2.0 SP (metadata, ACS, signed assertions): done in 2.10.0 ([SAML.md](SAML.md)); IdP-initiated sign-in and Single Logout are not planned yet
- Per-tenant IdP discovery stays in the host (2.7.0 adds unlisted credentials and `login_hint` for it)
- RP-initiated logout: done in 2.8.0 ([above](#rp-initiated-logout-280)); front- and back-channel logout are not planned yet
- Just-in-time role/group mapping from claims
