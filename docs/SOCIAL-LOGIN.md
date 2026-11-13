# Social login (OAuth)

AuthKit can expose **Continue with …** buttons on the login page. Provider **client id / client secret** and per-user **access/refresh tokens** are stored in Doctrine tables owned by the bundle.

## Table of contents

- [Tables](#tables)
- [Configuration](#configuration)
- [Brand marks on the buttons](#brand-marks-on-the-buttons)
- [Extra authorization parameters](#extra-authorization-parameters)
  - [`prompt=select_account` on google and microsoft](#promptselect_account-on-google-and-microsoft)
- [Seed credentials (example)](#seed-credentials-example)
- [Security / routes](#security--routes)
- [Callback handlers](#callback-handlers)
- [Login template variables](#login-template-variables)

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
                create_user_if_missing: true      # default false since 1.31
                require_verified_email: true
                link_existing_account: reset_password
                pkce:                       # optional per-provider overrides (keys = credential provider)
                    google: true
                    my_idp: false
                provider_marks:             # 1.48: brand mark per provider (unset = the mark the bundle ships)
                    github: false           # false = no mark
                    my_idp: 'images/idp.svg'
                authorization_params:       # 1.48: extra parameters of the authorization request
                    google:
                        access_type: offline
                    my_idp:
                        prompt: consent
```

- **`mode: enabled`** alone is not enough: the login UI only shows buttons when at least one **enabled** row exists in `auth_kit_social_credential` (start/check handlers redirect to login otherwise).
- **`create_user_if_missing`** (default `false` since 1.31): create a local user from the social email when no account matches, only while `registration_mode` allows registration; otherwise require a pre-existing user (matched by `user_identifier_field`, exact stored value).
- **`link_existing_account`** (1.31): first social sign-in whose verified e-mail matches an existing local account. `reset_password` (default) links it and replaces the local password with a random one (a password set by whoever registered the address, and its sessions, stop working); `deny` refuses (sign in locally); `keep` links and keeps the password (pre-1.31; only when the application verifies e-mail ownership at registration).
- **Profiles:** a link belongs to the user class of the profile that made it (unique `provider + provider_user_id + user_class`); see UPGRADING for the 1.31 index change.
- **`require_verified_email`**: when true (default), linking or creating a local user requires the IdP to assert a verified email (`email_verified` / GitHub `verified`). Prevents account takeover via unverified provider emails.
- **`pkce`**: map of provider → bool for [PKCE](https://datatracker.ietf.org/doc/html/rfc7636) (`S256`). When enabled, the start handler generates a `code_verifier`, keeps it in the session next to the OAuth `state`, sends `code_challenge` + `code_challenge_method=S256` to the authorize endpoint, and sends `code_verifier` in the token exchange (the client secret is still sent). Providers without an entry default to **enabled** for the built-in `google`, `github` and `microsoft` (all accept PKCE for confidential web clients) and **disabled** for custom providers, so an IdP that rejects unknown parameters keeps working until you opt in with `pkce: { my_idp: true }`. Keys are matched case-insensitively.

## Brand marks on the buttons

Since **1.48** every provider button carries the official brand mark of the provider, with no host template work:
`google`, `github` and `microsoft` default to the artwork the bundle ships, any other provider to no mark. Google's
branding guidelines *require* its "G" on the button, so this is a compliance matter, not decoration.

`social_login.provider_marks` replaces or removes a mark per provider (`false` = no mark, a string = an asset path, a
map = `path` / `package` / `on_white`). Which marks are shipped, where each file comes from, the trademark position of
each one, why no Apple mark is shipped, and how the markup keeps the clear space, the colours and the accessible label
are all in [BRAND-MARKS.md](BRAND-MARKS.md).

## Extra authorization parameters

`authorize_url`, `token_url`, `userinfo_url` and `scopes` of a credential describe *where* the IdP is. Anything else the
authorization request needs (`prompt`, `access_type`, `login_hint`, `hd`, `ui_locales` …) goes through
**`authorization_params`** (1.48) — never by pasting a query string into `authorize_url`, which is for custom IdPs and
would leave you concatenating `?` or `&` yourself. The client assembles and percent-encodes the whole query, and a
parameter it sends is removed from the authorize URL's own query rather than repeated (RFC 6749 §3.1 forbids repeating
one, and Google and Entra answer `invalid_request`); the value from `authorization_params` wins.

Three layers set them, lowest precedence first:

1. the per-provider defaults of a built-in provider (see [`prompt=select_account`](#promptselect_account-on-google-and-microsoft) below);
2. `social_login.authorization_params` of the profile, keyed by provider (case-insensitive);
3. the credential's own map: `$credential->setAuthorizationParams(['access_type' => 'offline'])`.

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                authorization_params:
                    google:
                        access_type: offline     # ask for a refresh token
                        login_hint: '%env(SUPPORT_MAILBOX)%'
                    my_idp:
                        prompt: consent
```

**A `null` value removes the parameter** instead of sending it empty, which is how a host opts out of a default:

```yaml
                authorization_params:
                    google:
                        prompt: ~                # do not send prompt at all
```

Values may be strings, numbers or booleans (`true` / `false` are sent as `true` / `false`); anything else is refused.
Names are trimmed and lower-cased on the wire.

### `prompt=select_account` on google and microsoft

Since **1.48** the built-in `google` and `microsoft` providers send **`prompt=select_account`** by default, so the
provider always asks which account to use.

Why it is the default: without it, Google silently reuses whichever Google account the browser has open. Someone with a
personal Gmail and a work account signed in at the same time gets bound to whichever one the browser happened to have
open, with no chance to choose — and the provider's subsequent error page carries no account switcher either, so the
only way out is unlinking the wrong identity and signing out of the other account in that browser. That is an
identity-correctness bug, not a nicety.

To restore the pre-1.48 behaviour, remove the parameter with a `null` value:

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                authorization_params:
                    google:
                        prompt: ~        # or on the credential: setAuthorizationParams(['prompt' => null])
                    microsoft:
                        prompt: ~
```

Any other value simply replaces it (`prompt: consent`, `prompt: login`, …). Custom providers get no default: an IdP
that rejects unknown parameters is unaffected.

Two consequences worth knowing before you keep the default:

- **`login_hint` stops pre-selecting the account.** Both Google and Microsoft Entra show the chooser instead of
  honouring the hint when `prompt=select_account` is sent, so a flow that pre-fills the account wants `prompt: ~`.
- **Seamless / silent SSO is suppressed.** `prompt=select_account` is mutually exclusive with `prompt=none`, so a host
  relying on a silent re-authentication (or on Entra's seamless SSO) sets `prompt: ~` for that provider, or sends
  `prompt: none` itself.

The default follows the **provider key**, not the endpoint: a *custom* provider keyed `google` or `microsoft` behind an
overridden `authorize_url` also gets `prompt=select_account`. Key it something else, or set `prompt: ~`, if its IdP
does not understand the parameter.

### What the bundle never lets a host set

The protocol parameters the bundle owns — `state`, `redirect_uri`, `scope`, `client_id`, `client_secret`,
`response_type`, `code_challenge`, `code_challenge_method` — **always** win. `state` and `redirect_uri` especially:
moving either would break the CSRF / PKCE binding of the callback, and an attacker-chosen `redirect_uri` is an open
redirect that leaks the authorization code.

Also reserved, because each would carry a request of its own and could put back what the list above protects: `request`
and `request_uri` (at a JAR-capable server, RFC 9101, the parameters inside the request object *replace* the query
parameters, `redirect_uri` and `state` included), `response_mode`, and `nonce`, `claims`, `client_assertion`,
`client_assertion_type`, `code` and `grant_type`.

- setting one in `authorization_params` fails container compilation with a clear message;
- `setAuthorizationParams()` throws an `InvalidArgumentException` naming the parameter;
- a value that reaches the client anyway (a row written by raw SQL or a fixture) is dropped, in any letter case, and the
  bundle writes its own parameters last.

## Seed credentials (example)

```php
$credential = (new \Nowo\AuthKitBundle\Entity\SocialLoginCredential())
    ->setProvider('google')
    ->setLabel('Google')
    ->setClientId($clientId)
    ->setClientSecret($clientSecret)
    ->setEnabled(true);
// Optional: setAuthorizeUrl / tokenUrl / userinfoUrl / scopes for custom IdPs.
// Optional (1.48): setAuthorizationParams(['access_type' => 'offline']) — extra authorize-request parameters.
$em->persist($credential);
$em->flush();
```

Built-in endpoint defaults exist for `google`, `github`, and `microsoft`. Custom providers must set the three URLs on the credential.

For **organization / enterprise OIDC** IdPs, set `enterpriseSso: true` so the login page lists them under the SSO heading instead of consumer social buttons. See [SSO.md](SSO.md).

## Security / routes

Public routes (also locale-prefixed when `locale.in_path` is `always`/`both`):

- `GET /login/social/{provider}` → start
- `GET /login/social/{provider}/check` → callback

Add matching `access_control` entries (`PUBLIC_ACCESS`), same pattern as magic login / password reset. The OAuth redirect URI registered at the IdP must match the **absolute** check URL (including locale prefix if you use one). With `absolute_url.base_uri` (1.24+) that URL is `{base_uri}` + check path for start and code exchange alike, independent of the request `Host` — register exactly that.

## Callback handlers

Accepting an invitation with a provider needs no handler of your own since 1.42: set
`invitations.social.accept_page: true` ([INVITATIONS.md](INVITATIONS.md#host-follow-ups-142)) and the accept page
starts and completes it (its controller is the handler, tagged only then).

A host with its own pending step (accepting an invitation with Google, for example) keeps the one callback URL
registered at the provider and implements `Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface` (1.37). Autoconfiguration adds the tag `nowo_auth_kit.social_callback_handler`; a `priority` attribute on a manual tag orders several handlers, and the first whose `supports()` is true owns the callback. Without a handler, or when none supports the request, the default flow runs unchanged.

```php
final class InvitationSocialHandler implements SocialCallbackHandlerInterface
{
    public function supports(Request $request, string $provider): bool
    {
        // Server-side state, never the query string. Compare the provider you stored.
        return ($request->getSession()->get(self::SESSION_KEY)['provider'] ?? null) === $provider;
    }

    public function handle(Request $request, SocialCallbackContext $context): Response
    {
        $request->getSession()->remove(self::SESSION_KEY);
        $email = $context->verifiedEmail(); // null unless the provider asserts it is verified
        // ... your step, then, last of all:
        $response = $this->security->login($user, 'form_login', $context->profile->firewall);

        return $response ?? new RedirectResponse($this->urls->generate('dashboard'));
    }

    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response
    {
        $request->getSession()->remove(self::SESSION_KEY);

        return null; // null keeps the flash and the redirect to the sign-in page
    }
}
```

What AuthKit still does before `handle()`: the social gate and credential check, the provider `error` answer, the
single-use OAuth `state` bound to the session, PKCE, the code exchange and the profile fetch. A handler cannot skip
them and never reads `code` or `state`.

A callback that does not prove its state never reaches `handleFailure()`: forged or missing `state`, or a crafted
`?error=` link, gets the default flash and redirect, and neither the pending OAuth state nor your pending entry is
touched. `handleFailure()` runs for `Unavailable`, `ProviderDenied` and `Failed` after the state matched.

What the handler takes over, because `handle()` replaces `SocialAccountLinker` and `Security::login()`:

**Prefer `SocialAccountLinker::linkAndSignIn()` in the handler** (1.38; `linkOrCreate()` writes at once): it enforces the rules below and rolls the link and any password replacement back if the sign-in fails, including a `LoginSuccessEvent` listener failing. A `password_changed` listener runs after the commit, so its failure undoes nothing (1.39). After a failure every entity of the request is detached: fetch again if your handler goes on. If you do not
call it, each one is yours:

- `social_login.require_verified_email`: use `$context->verifiedEmail()`, not `$context->socialProfile->email`, which
  can be any unverified address.
- The stored-identifier match (`UserIdentifierMatcher`, no look-alike addresses).
- `social_login.link_existing_account`: a first link to an existing account must not leave a pre-registered password
  usable (`reset_password` by default).
- `create_user_if_missing` and the `registration_mode` gate.
- The per-user-class lookup: a link belongs to `provider + subject + user_class`, so never match across profiles.
- The provider of your pending entry: compare it with `$provider`, in `supports()` or at the start of `handle()`.

And in every case:

- **Sign in last, with `Security::login()`,** never by writing a token. If the handler throws after signing in, AuthKit
  undoes that sign-in: an earlier session goes on (its token and session attributes restored, session id changed, the
  failed account out of this browser's switcher, its remember-me cookie never sent), and a visitor who was anonymous
  stays anonymous with the browser's other accounts still in the switcher. Still not undone: side effects you made outside the session before the exception, and what `LoginSuccessEvent` listeners already did outside the session (a new-device mail, an audit row, `evict_oldest` signing out that account's oldest sessions). Session attributes you changed in `handle()` are reverted with the rest. An earlier session UserKit already reports revoked or expired is not brought back: the visitor ends anonymous. The same happens to an impersonating session (`switch_user`).
- **Return the response of `Security::login()` if it has one, otherwise a redirect.** A page answered directly stays on
  the URL holding `code` and `state` (already burned), and with MFA the next page goes to the challenge anyway.
- **Clear your pending entry** in both `handle()` and `handleFailure()`, and **expire an abandoned one yourself**
  (store a timestamp): crafted links no longer clear it, and while it is there the next social sign-in in that session
  reaches your handler.
- **Keep `supports()` cheap and session-only.** It is host code that runs first, on every callback, without
  authentication. If it throws, the visitor gets a 500 (nothing is burned and no provider is called).
- **Never log `$context->tokens`.**

Exceptions from `handle()`: every one undoes a sign-in made by the handler and is logged by class. An HTTP exception
(`HttpExceptionInterface`: your 403, 404, 429) is rethrown with its status. Anything else, including
`AuthenticationException`, `LogicException` and `TypeError`, becomes `SocialCallbackFailure::Failed` and reaches
`handleFailure()`.

`handleFailure()` does not run for an HTTP exception, so clear your pending entry before you throw one, or remove it first thing in `handle()` as in the example; otherwise the next social sign-in in that session reaches your handler.

Do not add the callback route to `mfa.allowed_routes`: with a pending MFA session behind it, a rollback leaves the switcher's admin-link flag and UserKit's pending flag removed.

Handlers are shared services: no per-request state on properties (FrankenPHP worker mode).

## Login template variables

| Variable | Meaning |
|----------|---------|
| `social_login_enabled` | Mode enabled **and** enabled credentials in DB |
| `social_login_providers` | List of `SocialLoginCredential` |
| `social_login_route` | Start route name |
| `social_links` / `sso_links` | List of `View\ProviderLink` (1.48: `provider`, `label`, `href`, `mark`) |

Override `templates/bundles/NowoAuthKitBundle/security/login.html.twig` to restyle the buttons, or — since 1.48 — only
the block `nowo_auth_kit_provider_link` to decorate a single button without copying the providers loop (see
[TEMPLATES.md](TEMPLATES.md)).
