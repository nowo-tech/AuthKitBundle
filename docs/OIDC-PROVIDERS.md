# OpenID Connect providers (2.3.0)

AuthKit 2.3.0 adds a **generic OpenID Connect provider** (any issuer with discovery: Okta, Keycloak, Auth0, a
customer's Microsoft Entra tenant, …) and four presets: **GitLab** (gitlab.com or self-hosted), **Sign in with
Apple**, **LinkedIn** and **Slack**. They live next to the OAuth2 providers (`google`, `github`, `microsoft`) and use
the same table, buttons, routes, state handling and account-linking rules ([SOCIAL-LOGIN.md](SOCIAL-LOGIN.md)).

## Table of contents

- [Which credentials use OpenID Connect](#which-credentials-use-openid-connect)
- [Redirect URI](#redirect-uri)
- [Generic provider (issuer + discovery)](#generic-provider-issuer--discovery)
- [GitLab](#gitlab)
- [Sign in with Apple](#sign-in-with-apple)
- [LinkedIn](#linkedin)
- [Slack](#slack)
- [Configuration (`nowo_auth_kit.oidc`)](#configuration-nowo_auth_kitoidc)
- [What is checked](#what-is-checked)
- [Outbound requests (SSRF guard)](#outbound-requests-ssrf-guard)
- [Account linking](#account-linking)
- [Brand marks](#brand-marks)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## Which credentials use OpenID Connect

| Provider key | Issuer | Notes |
| --- | --- | --- |
| `gitlab` | `https://gitlab.com`, or the credential's `issuer` (self-hosted base URL) | PKCE when the instance advertises S256 (gitlab.com does) |
| `apple` | `https://appleid.apple.com` (fixed) | `response_mode=form_post`, generated client secret, name on the first sign-in only |
| `linkedin` | `https://www.linkedin.com/oauth` (fixed) | no nonce (LinkedIn does not echo it) |
| `slack` | `https://slack.com` (fixed) | |
| any other key with `issuer` set | the credential's `issuer` | generic provider |

A credential without an issuer whose key is not one of the four presets stays on the OAuth2 path, exactly as before.
A preset key whose credential sets its own `authorize_url` / `token_url` / `userinfo_url` (a custom OAuth2 setup made
before 2.3.0) also stays on the OAuth2 path, so nothing changes for it on upgrade. Setting an issuer on a `google` or
`microsoft` credential opts it into the generic provider.

Credentials are rows of `auth_kit_social_credential` (client secret encrypted at rest with DoctrineEncryptBundle), like
the OAuth2 ones; 2.3.0 adds two nullable columns, `issuer` and `provider_settings` (see
[UPGRADING](UPGRADING.md#to-230)). Both are validated when written (`setIssuer()`, `setProviderSettings()` throw an
`InvalidArgumentException`) and again on every sign-in (a row written by raw SQL never skips the checks).

## Redirect URI

Register the absolute check URL at the provider, as for the OAuth2 providers:

```
{base_uri}/login/social/{provider}/check        e.g. https://app.example.com/login/social/okta/check
```

with the locale prefix when `locale.in_path` puts one there, and the profile's own route path when it overrides
`routes.social_login_check`. Apple calls it back with a **POST** (form_post); the route accepts GET and POST.

## Generic provider (issuer + discovery)

1. Create an application ("web", authorization code) at the provider; note the client id and secret.
2. Register the redirect URI above.
3. Store the credential:

```php
$em->persist((new SocialLoginCredential())
    ->setProvider('okta')                       // any slug [a-z0-9_-]
    ->setLabel('Okta')
    ->setIssuer('https://acme.okta.com')        // discovery: <issuer>/.well-known/openid-configuration
    ->setClientId($clientId)
    ->setClientSecret($clientSecret)
    ->setEnterpriseSso(true)                    // optional: list it under the SSO heading
    ->setEnabled(true));
```

- **Issuer**: HTTPS, public host, no query, fragment or credentials. It must be the issuer the provider publishes
  (Auth0's ends with `/`; a trailing slash is the only difference tolerated). Examples: Keycloak
  `https://sso.example.com/realms/acme`, Entra `https://login.microsoftonline.com/<tenant-id>/v2.0` (a tenant, not
  `common`: the multi-tenant document has a templated issuer), Okta `https://acme.okta.com`.
- **Scopes**: `openid email profile` by default; `setScopes()` replaces them (`openid` is always added).
- **PKCE (S256)**: sent when the discovery document lists `S256` in `code_challenge_methods_supported`;
  `social_login.pkce.<provider>` forces it on or off.
- **Token endpoint authentication**: `client_secret_post` when the document lists it, else `client_secret_basic`.
- **Extra authorization parameters** (`acr_values`, `prompt`, …): `social_login.authorization_params.<provider>` or
  the credential's own map, as for the OAuth2 providers.

## GitLab

1. GitLab → *User settings* (or *Group* / *Admin area*) → **Applications** → new application; scopes `openid`,
   `profile`, `email`; *Confidential* on; redirect URI as above with `gitlab` as the provider.
2. Credential with provider `gitlab`. For a self-hosted instance set the issuer to its base URL (including a relative
   URL root, e.g. `https://example.com/gitlab`):

```php
(new SocialLoginCredential())->setProvider('gitlab')->setLabel('GitLab')
    ->setIssuer('https://gitlab.example.org')   // omit for gitlab.com
    ->setClientId($applicationId)->setClientSecret($secret)->setEnabled(true);
```

GitLab's ID token carries `email_verified`.

## Sign in with Apple

1. Apple Developer → *Certificates, Identifiers & Profiles*:
   - an **App ID** with *Sign in with Apple* enabled;
   - a **Services ID** (this is the **client id**, e.g. `com.example.web`), *Sign in with Apple* configured with your
     domain and the redirect URI above (provider `apple`);
   - a **Key** with *Sign in with Apple* enabled: download the `.p8` file once and note its **Key ID**; note your
     **Team ID** (top right of the developer account).
2. Credential:

```php
(new SocialLoginCredential())->setProvider('apple')->setLabel('Apple')
    ->setClientId('com.example.web')                         // the Services ID
    ->setClientSecret(file_get_contents('/secure/AuthKey_ABC123DEFG.p8'))  // the .p8 PEM, encrypted at rest
    ->setProviderSettings(['team_id' => 'TEAM123456', 'key_id' => 'ABC123DEFG'])
    ->setEnabled(true);
```

- The client secret Apple expects is a short-lived **ES256 JWT** the bundle signs with that key for each token request
  (`iss` = team id, `sub` = Services ID, `aud` = `https://appleid.apple.com`, 5 minutes). The `.p8` never leaves the
  server and never reaches a log or an exception message.
- Apple answers with `response_mode=form_post`, a **cross-site POST**. The session cookie (SameSite=Lax by default,
  or Strict) is not sent with it, so the bundle does not touch the session there: it answers a small page whose form
  posts the same fields again from your own origin (automatically with `js/nowo-auth-kit-form-post.js`, or with the
  *Continue* button), and that same-site request is checked like any callback (state, then everything below). The
  relay page sends `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, carries no CSRF token (the OAuth state is
  the CSRF protection), relays only `code`, `state`, `error` and `user` (the front-channel `id_token` is dropped; the
  one from the token endpoint is verified), and a relayed POST the browser marks as cross-site (`Sec-Fetch-Site`) is
  relayed again, never processed. Allow `/bundles/nowoauthkit/js/nowo-auth-kit-form-post.js` in your CSP
  `script-src` (`'self'` covers it); the page honours `csp_nonce` like the other AuthKit pages.
- Apple sends the user's **name only on the first authorization**, in the unsigned `user` field: the bundle uses it as
  the display name of the account it links or creates then, and never its e-mail (the e-mail comes from the signed ID
  token). Later sign-ins keep the stored name.
- Private relay addresses (`…@privaterelay.appleid.com`) are verified addresses for Apple; they only match a local
  account registered with that same relay address.
- Apple ships no mark the bundle may redistribute: give the button your own (Apple's guidelines apply), see
  [Brand marks](#brand-marks).

## LinkedIn

1. LinkedIn Developers → create an app → *Products* → add **Sign In with LinkedIn using OpenID Connect**;
   *Auth* → add the redirect URI above (provider `linkedin`); note client id and primary client secret.
2. Credential with provider `linkedin`, no issuer.

LinkedIn does not echo the `nonce` in its ID token, so none is sent for it (state and the code bound to the client
secret protect the exchange); its JWKS keys carry no `use`, which the bundle accepts.

## Slack

1. api.slack.com → *Your apps* → create an app → *OAuth & Permissions* → redirect URL above (provider `slack`);
   *User Token Scopes*: `openid`, `email`, `profile`.
2. Credential with provider `slack`, no issuer.

## Configuration (`nowo_auth_kit.oidc`)

Global (not per profile); every key is validated when the container is built.

```yaml
nowo_auth_kit:
    oidc:
        allowed_algorithms: [RS256, RS384, RS512, ES256, ES384, ES512]  # subset only; none / HS* are refused
        clock_skew: 60              # s, 0..300 — leeway on exp / iat / nbf
        max_id_token_age: 600       # s, 60..86400 — oldest accepted iat
        metadata_cache_ttl: 3600    # s, 60..604800 — discovery documents
        jwks_cache_ttl: 3600        # s, 60..604800 — key sets
        jwks_refresh_interval: 60   # s, 0..3600 — min. time between refetches on an unknown kid (key rotation)
        max_response_bytes: 524288  # 4 KiB..5 MiB — any discovery / JWKS / token / userinfo answer
        discovery_failure_ttl: 30   # s, 0..300 — a failed discovery is not retried meanwhile (negative cache, per issuer)
        cache_pool: cache.app       # a Symfony\Contracts\Cache\CacheInterface pool (shared by workers; symfony/cache comes with FrameworkBundle)
    http_client:                    # deadlines of every OAuth2 and OIDC request (unchanged)
        timeout: 10
        max_duration: 20
```

Per profile, `social_login.start_rate_limit` (default 20, 0 disables) and `social_login.start_rate_window` (60 s)
bound the sign-in starts per client IP through the attempt limiter (`attempt_limiter.cache_pool`): an OpenID Connect
start fetches the discovery document, so an anonymous loop on the start URL could otherwise keep the workers busy
with outbound requests while a provider is down. Over the limit, the visitor is sent back to the sign-in page with
`social.error.rate_limited`.

Per provider: `social_login.pkce`, `social_login.authorization_params` and `social_login.provider_marks` (per profile,
keyed by provider) apply to OIDC providers as to the OAuth2 ones; the issuer and Apple's identifiers live on the
credential.

## What is checked

On the callback, after the state (and before anything is linked or signed in):

| Check | Failure |
| --- | --- |
| discovery document issuer = configured issuer (mix-up) | refused, not cached |
| every endpoint HTTPS on a public address | refused |
| token response 200 with `access_token` and `id_token` | refused |
| ID token: JWS compact, `alg` on the allow-list (never `none`, never HS*), no `crit` | refused |
| key from the issuer's JWKS only (`jwk` / `jku` / `x5u` headers ignored), fitting `kty` / curve / `use` / `alg` / `key_ops`, RSA ≥ 2048 bits, no private members | refused |
| `iss` = discovered issuer exactly; `aud` holds the client id; several audiences need `azp`; `azp` = client id | refused |
| `exp` future, `iat` not future and ≤ `max_id_token_age`, `nbf` reached (± `clock_skew`) | refused |
| `nonce` = the one stored with the state (constant-time) | refused |
| userinfo (when the provider has one) answers about the same `sub` | refused |

Every refusal takes the existing failure path: flash `social.error.failed`, or the host handler's `handleFailure()`
with `SocialCallbackFailure::Failed`. The log line names the provider, the exception class and the `reason`
(`OidcException::REASON_*`) — never a token, a secret, a code or a claim value.

## Outbound requests (SSRF guard)

Discovery, JWKS, token and userinfo requests all go through `OidcHttpClient`: HTTPS only; the host is resolved first
and **every** address must be public (loopback, RFC 1918, CGNAT, link-local / cloud metadata, multicast, reserved,
IPv4-mapped / NAT64 / 6to4 / Teredo encodings, ULA … are refused); the connection is pinned to a checked address (no
DNS rebinding); **no redirect** is followed; `http_client.timeout` / `max_duration` apply; the size cap is enforced
while downloading. `HostResolverInterface` (default `DnsHostResolver`) can be replaced.

**Outbound proxy.** When the application's `http_client` goes through a proxy (`HTTPS_PROXY`, or a `proxy` default
option), the proxy resolves the host itself: the address checks still run on AuthKit's side, but the pinning to the
checked address does not apply, and anything the proxy can reach is reachable. Give the proxy an egress policy that
refuses private ranges, or let the OIDC requests bypass it (`NO_PROXY`).

## Account linking

Unchanged from the OAuth2 providers: the same `SocialAccountLinker` with `create_user_if_missing`,
`link_existing_account`, `require_verified_email` (the e-mail and its `email_verified` come together from the ID token,
or from userinfo when the token has no e-mail) and `registration_mode: first_user_only` semantics.

### A provider key and its issuer are one subject namespace

A `sub` is only unique within its issuer: user `1234` on gitlab.com and user `1234` on a self-hosted GitLab are two
people (GitLab ids are small sequential integers, so this collision is realistic). Every link made by an OpenID Connect
provider records the issuer (`auth_kit_social_account.issuer`), and an existing link signs in **only while the
credential still has that issuer**. After an issuer change, the old links are refused (the sign-in fails, nobody is
signed in), whatever the new provider asserts.

So do not move a provider key to another issuer while accounts are linked to it. Administration screens should ask
`SocialCredentialIssuerGuard::issuerChangeError($credential, $newIssuer)` before saving and show its message: unlink
the accounts first, or add the new issuer under a **new provider key**. Links made by OAuth2 providers (and every link
made before 2.3.0) have no issuer: setting an issuer on such a credential (for example opting `google` into the generic
provider) makes its old links stop signing in, and users link again.

### Every configured issuer is trusted with e-mail addresses

With `link_existing_account: reset_password` or `keep`, an issuer that asserts `email` with `email_verified: true` signs
in as the local account with that address, **administrators included**. That is what e-mail linking means, and it
holds for every provider; with the generic provider it is one credential away. An issuer run by somebody you do not
fully trust (a customer's own IdP in a B2B application, a test Keycloak, a self-hosted GitLab where anyone can register
and set an unchecked e-mail) can therefore take over any account whose address it can assert.

- Use `link_existing_account: deny` on profiles that offer such issuers: their users then link from their account
  page, or sign up, and never take over an existing account by e-mail;
- keep `require_verified_email: true` (the default);
- keep administrators on a profile without those issuers, or with MFA;
- for a self-hosted GitLab, require e-mail confirmation on the instance.

## Brand marks

The bundle ships marks for google, github and microsoft only. For `gitlab`, `apple`, `linkedin`, `slack` and generic
providers, set `social_login.provider_marks.<provider>` to your own asset (see [BRAND-MARKS.md](BRAND-MARKS.md)); the
buttons render without a mark otherwise.

## FrankenPHP worker mode

No OIDC service keeps state between requests: settings are resolved per call, discovery documents, key sets and the
negative entries of failed discoveries live in the cache pool (`oidc.cache_pool`), the nonce and PKCE verifier in the
session with the state.

Symfony's `CurlHttpClient` keeps every `resolve` pin in the DNS cache of its shared handle, so in a long-lived worker a
later request of the application to the same host name reuses the address AuthKit checked. That is not a security
issue (the address is a checked, public one), but it can delay a DNS change of that host until the worker restarts.
