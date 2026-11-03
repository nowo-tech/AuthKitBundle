# Several accounts at once (account switcher)

Since **1.34**, opt-in per profile (`multi_account.enabled: false` by default: no service, route, entity mapping with
`doctrine.register_mapping: auto`, cookie or listener). Google-style: a browser keeps up to five accounts signed in,
each through its own complete sign-in (password, magic link, SSO, plus [MFA](MFA.md)), and switches between them
without a password. Requires **UserKit ≥ 1.11 sessions** (separate, revocable sessions per account).

## Table of contents

- [How it works](#how-it-works)
- [Enable](#enable)
- [Configuration reference](#configuration-reference)
- [User menu and chooser](#user-menu-and-chooser)
- [Signing out](#signing-out)
- [Remember-me](#remember-me)
- [Events](#events)
- [Host hooks](#host-hooks)
- [Security](#security)
- [Database schema](#database-schema)

## How it works

- Every account keeps **its own session** (its own id, data, CSRF tokens and UserKit row). The active account's session
  is the browser's session cookie; the others are *parked*.
- The browser holds a **vault cookie** with one opaque 256-bit token per account (httpOnly, SameSite=Lax, `__Host-` on
  HTTPS, browser-session by default, no identity inside). The server keeps only the token's keyed HMAC (looked up
  through an index, never compared in PHP) and a blob sealed with XChaCha20-Poly1305 under a key derived from the
  token, bound to the profile, entry and user identifier: the parked session id and its remember-me cookie. The
  database alone opens nothing.
- Tokens rotate on every park, switch and sign-in. During `rotation_grace` the previous token still opens its entry
  (a concurrent tab), under a fresh token; later it counts as **reuse** (a copied cookie): the entry goes and the
  account is signed out everywhere (UserKit `revokeAll`, reason `vault_token_reuse`, remember-me epoch bumped), so a
  thief who switched first loses the session too.
- **Add another account** parks the current account, clears the remember-me cookie and opens an empty session in
  "add" mode; the next complete sign-in (after any second factor) joins the switcher. A plain sign-in on a browser that
  already has accounts never joins silently; admin-issued magic links never join.
- **Switch** (CSRF POST, rate limited) parks the current account, re-opens the target's session in the same request,
  refreshes the user and re-runs the firewall's **user checker** (disabled / locked accounts are refused and leave),
  asks host listeners (`AccountSwitchingEvent`), regenerates the session id (UserKit's row follows), restores the
  target's own remember-me cookie and rotates both tokens. No authentication is created: the target's original token
  (from its own sign-in and MFA) is restored.
- A parked account whose session was **revoked** (UserKit: one session, everywhere, administrator, password change,
  disabled; also once the revoked row was purged, UserKit ≥ 1.11.1) leaves the switcher and never comes back through
  its remember-me cookie; only one whose session **expired** does (`revive_with_remember_me`), else it leaves.
- A switch that fails half-way (a throwing host listener, a storage error) rolls back to the previous session and
  token; the target stays parked.
- An account that is active in another browser holding a copy of the cookie is not listed (nor removed) here.
- Switching away from an account that cannot be parked (incomplete sign-in, browser full) signs it out without a
  confirmation step.

## Enable

```yaml
nowo_auth_kit:
    multi_account:
        enabled: true
nowo_user_kit:
    sessions:
        enabled: true
        remember_me_epoch: { enabled: true }   # recommended
```

Create the table (`doctrine:migrations:diff`), let `/accounts` be reached anonymously (the chooser and switch work for
a browser whose active session ended; `nowo:auth-kit:configure-security` adds the `PUBLIC_ACCESS` rules of the
switcher routes), keep form CSRF on, and the PHP `sodium` extension.

## Configuration reference

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `false` | Turn the switcher on for the profile |
| `max_accounts` | `5` | Accounts per browser (2–10) |
| `max_lifetime` | `2592000` | Seconds an account stays after its last full sign-in in this browser |
| `logout_scope` | `all` | What the firewall sign-out ends: `all` (every account, Google behaviour) or `current` (then the chooser) |
| `revive_with_remember_me` | `true` | An expired parked account comes back through its own remember-me cookie |
| `after_switch` | `null` | Route after a switch (default: the profile `login_success_route`); a same-origin `_target_path` wins |
| `rotation_grace` | `30` | Seconds a rotated token is still accepted (concurrent tabs); later it counts as reuse |
| `hash_secret` | `null` → `%kernel.secret%` | HMAC key of the tokens |
| `cookie.name` / `lifetime` / `samesite` | `nowo_auth_kit_accounts` / `null` / `lax` | `lifetime: null` = browser-session cookie; seconds = persistent; `strict` allowed |
| `cookie.secure` | `auto` | `auto`: `Secure` and `__Host-` on HTTPS requests; `true`: always (TLS terminated by a proxy without `trusted_proxies`); `false`: never (local HTTP) |
| `chooser.on_login_page` | `true` | List the browser's accounts above the sign-in form |
| `chooser.identifier_display` | `masked` | On anonymous pages: `full`, `masked` (`a•••@example.com`) or `none` |
| `chooser.cross_tab_notice` | `false` | Other tabs show "you switched account in another tab" (`BroadcastChannel`, CSP nonce aware) |
| `rate_limit.switch` / `add` / `invalid` | `30/600`, `10/3600`, `20/600` | Per client IP: switches and adds are refused over the limit; unknown tokens are counted and logged only (a shared IP never disables other browsers' vaults) |
| `enrolment_gate` | `null` | Service id of an `EnrolmentGateInterface` (default: complete once AuthKit MFA passed, never while impersonating) |
| `presenter` | `null` | Service id of an `AccountPresenterInterface` (name, identifier, avatar; default: the identifier) |
| `routes.*` | `/accounts`, `/accounts/add`, `/accounts/{account}/switch`, `/accounts/{account}/sign-out`, `/accounts/sign-out-all` | Paths and names |

## User menu and chooser

```twig
{# the avatar menu of your layout #}
{{ include('@NowoAuthKitBundle/accounts/_switcher.html.twig') }}
{# options: {variant: 'list'}, {target_path: app.request.pathInfo} #}
```

`auth_kit_accounts(profile = null, display = null)` returns the `AccountSwitcherView` (accounts with `name`,
`identifier`, `avatarUrl`, `initials`, `state` = `active` / `signed_in` / `signed_out`, their CSRF switch and sign-out
forms, the add form when one more fits, the sign-out-all form, the chooser URL). The function exists only while the
feature is on (include the partial behind the same configuration). `display` defaults to `full` for a signed-in
browser and to `chooser.identifier_display` otherwise. The chooser page `/accounts`
(`web_ui.templates.accounts_chooser`) and the sign-in page list the accounts with masked identifiers. A presenter's
avatar URL is rendered as an `img src`: an external avatar host sees who opens the chooser (prefer same-origin
avatars).

## Signing out

- The firewall sign-out (`/logout`) ends **every** account of the browser with `logout_scope: all`; with `current`
  only the active one, then the chooser when others remain.
- *Sign out* on a parked account ends it (UserKit row deleted, session emptied); on the active account it runs the
  firewall sign-out for that account only.
- *Sign out of all accounts* ends every account and clears the vault cookie.

## Remember-me

The firewall's remember-me cookie always belongs to the active account. Parking seals it into the account's entry and
clears it; switching restores the target's own cookie (or clears it). AuthKit never issues a remember-me cookie: it only
moves the cookies the browser already held. A cookie voided by UserKit's epoch (sign out everywhere, password change)
is refused by the firewall when it would revive an account.

## Events

`MultiAccount\Event\AccountAddedEvent` (`user`), `AccountSwitchingEvent` (`from`, `to`; `refuse($messageKey)`, deny
only), `AccountSwitchedEvent` (`from`, `to`; the session and token are the target's — reset per-account context here),
`AccountRemovedEvent` (`user`, `reason`: `signed_out`, `signed_out_all`, `logout`, `revoked`, `expired`, `refused`,
`token_reuse`, `user_removed`). Every event carries the account's public id in this browser and the request, never a
token, session id or hash.

## Host hooks

```php
#[AsEventListener]
final class ResetOrganisationContext
{
    public function __invoke(AccountSwitchedEvent $event): void
    {
        $event->request->getSession()->set('organization', $event->to->rememberedOrganization());
    }
}
```

`AccountVault::forgetUser($profile, $identifier)` removes a user of a profile from every browser (UserKit's "sign out
everywhere", administrator sign-out, account disabled or changed, password changed and user removal do it
automatically). Users load through the user provider of the profile's firewall.

## Security

| Threat | Mitigation |
| --- | --- |
| Session fixation | Add opens a server-generated session that the sign-in migrates again; a switch migrates the target session (new id) before answering |
| Cross-account CSRF | Every vault action is a CSRF POST bound to its action and account; session-bound CSRF tokens differ per account, so a stale tab of another account fails |
| Vault cookie theft | httpOnly, SameSite, `__Host-` (`cookie.secure`); tokens rotate on every use; a rotated token presented after `rotation_grace` removes the account and signs it out everywhere (reuse); parked sessions keep UserKit's idle expiry and revocation |
| Database leak | Only token HMACs and blobs sealed under the tokens |
| Shared computer | `logout_scope: all` by default, *Sign out of all accounts*, browser-session cookie, masked identifiers on anonymous pages, no silent enrolment |
| Revocation | Active: UserKit's early check; parked: checked on every switch and list (revoked never revives); UserKit sign-outs everywhere remove the user from every browser |
| Failures mid-switch | Any exception before the switch commits restores the previous session and token; the token storage is empty while sessions hop; the target token must belong to the entry's user |
| Disabled accounts | The firewall user checker runs on every switch |
| Impersonation | No user id parameter: targets are entries of this browser's own tokens; add and switch are refused while impersonating; impersonated or half-authenticated tokens are never parked |
| Privacy | Separate sessions; the switcher shows only the presenter's data; responses `private, no-store`, never framed |
| Rate limits | Switch and add limits per client IP (not per vault: users behind one NAT share them); unknown tokens are only counted and logged |

Residuals: the vault token is a bearer credential, not bound to a browser: a thief who copies the whole cookie jar
before the owner uses the browser again holds the parked sessions until they expire or are revoked (as with any
session cookie); hosts with stateless CSRF lose the per-account form binding; only the immediately previous token is
recognised (during `rotation_grace` it is accepted, later it is reuse; an older one is just unknown); when two tabs'
responses cross, the browser may keep the older of two fresh tokens, which works within `rotation_grace`.

## Database schema

`auth_kit_account_vault` (table-prefix aware): `public_id`, `profile`, `user_identifier`, `token_hash`,
`previous_token_hash`, `rotated_at`, `sealed`, `previous_sealed`, `created_at`, `last_used_at`, `expires_at`. No IP, user agent, session
id or cookie in clear. `nowo:auth-kit:accounts:purge` deletes expired entries (they are also removed lazily).
