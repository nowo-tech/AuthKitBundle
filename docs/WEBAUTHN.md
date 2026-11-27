# WebAuthn / passkeys

Passkeys (WebAuthn Level 3, platform and roaming authenticators) as a **passwordless first factor** and as a
**second factor** next to TOTP.

**Status (2.4.0):** implemented. The design locked in 1.12.0 is kept, with the changes listed under
[Differences from the 1.12 design](#differences-from-the-112-design).

## Table of contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Configuration](#configuration)
- [Routes](#routes)
- [Storage](#storage)
  - [SQL scripts](#sql-scripts)
- [Ceremonies and security checks](#ceremonies-and-security-checks)
- [Relying party at runtime (2.6.0)](#relying-party-at-runtime-260)
- [Passkeys and MFA](#passkeys-and-mfa)
- [Sign-in page, conditional UI and the phone QR code](#sign-in-page-conditional-ui-and-the-phone-qr-code)
- [Account page](#account-page)
- [Templates and the script](#templates-and-the-script)
- [Events](#events)
- [PHP API](#php-api)
- [Personal data](#personal-data)
- [Worker mode and concurrency](#worker-mode-and-concurrency)
- [Differences from the 1.12 design](#differences-from-the-112-design)
- [Host integration (Beacon and others)](#host-integration-beacon-and-others)

## What it does

- A signed-in user adds passkeys on `/account/webauthn` (name, browser ceremony), renames and removes them.
- The login page offers **Sign in with a passkey**, and the identifier field carries
  `autocomplete="username webauthn"`, so the browser proposes the site's passkeys (conditional UI) as soon as the field
  gets the focus.
- With `mfa.enabled`, a passkey is a **second factor**: it satisfies a required second factor (`mfa.required_roles`,
  `mfa.policy`) and the challenge page offers **Use a passkey** next to (or instead of) the code form.
- Every ceremony is handled by [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-framework) 5.3 (CBOR,
  COSE keys, attestation statements, signature, origin and RP id checks). AuthKit adds the session-bound single-use
  challenges, storage, limits, events and pages; it never parses CBOR or verifies a signature itself.

## Requirements

- `nowo_auth_kit.doctrine.register_mapping` true or `auto` (the table is an AuthKit entity).
- **symfony/lock** (`framework.lock`): the build fails without a `lock.factory` service while a profile enables
  passkeys. Challenges are claimed once and the passkey limit is enforced under a lock; on several hosts use a shared
  store (Redis, a database), exactly as for MFA.
- HTTPS in production: browsers only offer WebAuthn on secure origins (`http://localhost` is the exception).
- The firewall must allow a programmatic sign-in (`Security::login()`), like QR login: one authenticator, or
  `form_login` / `login_link` among several.

## Configuration

```yaml
nowo_auth_kit:
    profiles:
        default:
            webauthn:
                mode: enabled              # disabled (default) | enabled
                rp_id: null                # registrable domain, e.g. example.com; null = request host
                allowed_origins: []        # exact https://host[:port], compared lower-cased; empty = exactly https://<rp_id> (see below)
                timeout_ms: 60000          # browser timeout; the server challenge lives 30 s longer
                attestation: none          # none | direct
                max_credentials: 10        # passkeys per account (Beacon: 10)
                recent_sign_in: 900        # seconds after a full sign-in to add / rename / remove
                passwordless: true         # login page button + conditional UI
                mfa: true                  # a passkey is a second factor (with mfa.enabled)
                hash_secret: null          # HMAC key of the opaque user handle (null: %kernel.secret%)
                allow_http_localhost: null # 2.6.0: accept http://localhost origins; null = kernel.debug
                rate_limit:
                    options:  { limit: 30, window: 300 }   # per client IP (sign-in and second step)
                    assert:   { limit: 20, window: 300 }   # per client IP; a success gives its slot back
                    register: { limit: 10, window: 900 }   # registration options per account
                routes: { … }              # path and name of every route below
```

- `rp_id` is fixed for the life of the passkeys: changing it (or the host when it is null) makes every registered
  passkey unusable. Set it explicitly in production, to the host that serves the login page (`login.example.com`).
  A parent domain (`example.com` for `login.example.com`) lets the passkeys work on other sub-domains too, but every
  sub-domain can then ask the user's passkeys for an assertion: use it only when you control every sub-domain, and
  then list the origins in `allowed_origins`.
- `allowed_origins`: the exact origins (`https://host[:port]`) a ceremony may come from; sub-domains are refused.
  Entries are compared lower-cased (2.4.1, audits ZBX-v-I3 and ZCC-L1): a browser always sends a lower-case origin,
  so `https://Login.Example.com` matches `https://login.example.com`. The lower-casing happens at runtime, after env
  resolution, so an env placeholder inside an entry keeps resolving (for example `https://%env(LOGIN_HOST)%`; write
  the scheme itself as `https://`).
  Empty means exactly `https://<rp_id>` (audit ZBX-M1). With an explicit `rp_id`, the expected origin is **never**
  taken from the request's `Host` header, which a client can forge, so an attacker's sub-domain under a parent `rp_id`
  cannot make its own origin "expected". With `rp_id: null` (the default) the RP id itself is the request host, so the
  expected origin is `https://<request host>`: that is safe, because the authenticator's `rpIdHash` is bound to that
  same host (a forged `Host` changes the RP id too, and no registered passkey answers for it), but set `rp_id` in
  production anyway (see above). The empty default only covers the default HTTPS port: a login page served on another
  port (`https://login.example.com:8443`) must be listed in `allowed_origins`, even when `rp_id` is its host. The only
  exception is `rp_id: localhost` served on localhost (development: http and any port). `http://` entries are accepted
  for `localhost` only, and since 2.6.0 only in development (`kernel.debug`, or `allow_http_localhost: true`); the
  build still accepts `http://127.0.0.1`, but no ceremony runs on it (an IP is never a valid RP id). Configure
  `framework.trusted_hosts` as well.
- 2.6.0: both values are what the default relying party provider answers; a host can keep them elsewhere (see
  [Relying party at runtime](#relying-party-at-runtime-260)).
- `attestation: none` accepts whatever statement the browser returns and verifies it (a security key may keep a
  packed self attestation). `direct` asks for an attestation statement and refuses `none`; there are no trust anchors,
  so it proves a genuine statement, not an approved model.
- User verification is **always required** (no option): a passkey is then two factors on its own.
- `hash_secret`: the WebAuthn user handle given to new passkeys is `HMAC-SHA-256(hash_secret, profile|owner)`. It is
  never an e-mail or an id. Each row stores the handle it was registered with, and every ceremony checks that stored
  handle (audit ZBX-L2), so rotating the secret (or `%kernel.secret%`) keeps every registered passkey working, for the
  sign-in and for the second step. An explicit empty secret is refused.
- The per-IP limits are only as good as `framework.trusted_proxies`: behind a proxy that is not trusted every client
  shares one address, and a spoofable `X-Forwarded-For` lets a client pick its own.

## Routes

| Method | Path (default) | Name | Purpose |
|--------|----------------|------|---------|
| `GET` | `/login/webauthn/options` | `nowo_auth_kit_webauthn_login_options` | Request options of a discoverable sign-in (JSON) |
| `POST` | `/login/webauthn/assert` | `nowo_auth_kit_webauthn_login_assert` | Verify the assertion, `Security::login()` (first factor `webauthn`) |
| `GET` | `/login/mfa/webauthn/options` | `nowo_auth_kit_webauthn_mfa_options` | Options of the second step (the pending user's passkeys) |
| `POST` | `/login/mfa/webauthn/assert` | `nowo_auth_kit_webauthn_mfa_assert` | Verify, complete the pending sign-in |
| `GET` | `/account/webauthn` | `nowo_auth_kit_webauthn_account` | Account page |
| `GET` | `/account/webauthn/register/options` | `nowo_auth_kit_webauthn_register_options` | Creation options (JSON) |
| `POST` | `/account/webauthn/register` | `nowo_auth_kit_webauthn_register` | Verify and store a passkey |
| `POST` | `/account/webauthn/{id}/rename` | `nowo_auth_kit_webauthn_rename` | Rename |
| `POST` | `/account/webauthn/{id}/delete` | `nowo_auth_kit_webauthn_delete` | Remove |

The `login_*` routes exist only with `passwordless: true`, the `mfa_*` routes only with `mfa: true` and
`mfa.enabled`. The JSON answers are `private, no-store`. Every POST is an ordinary FormKit form with a CSRF token (the
script puts the credential JSON in a hidden field). These are internal UI endpoints of the bundle's own pages, not a
public API (no `/v1` path). Give `/login/webauthn/*` the same `PUBLIC_ACCESS` rule as the login page;
`/login/mfa/webauthn/*` is reachable while MFA contains a pending sign-in.

## Storage

Table `auth_kit_webauthn_credential` (takes `doctrine.table_prefix`):

| Column | Notes |
|--------|-------|
| `id` | integer surrogate key |
| `public_id` | 32 hex characters, unique: the id of the rename / remove routes and of the events |
| `profile` | AuthKit profile |
| `user_type` / `user_id` | owner: Doctrine root class and identifier (the MFA owner key), never the e-mail |
| `credential_id_hash` | SHA-256 (hex) of the raw credential id, **unique**: the lookup key |
| `credential_id` | base64url credential id, compared byte for byte in PHP after the lookup |
| `user_handle` | base64url opaque user handle (HMAC), checked against the assertion |
| `public_key` | base64url COSE public key |
| `sign_count` | signature counter (clone / replay detection) |
| `transports` | JSON list |
| `aaguid` | authenticator model id (all zeros on most platforms with attestation `none`) |
| `backup_eligible` / `backup_state` | synced passkey flags |
| `label` | user-facing name (≤ 100 characters, control characters removed) |
| `created_at` / `last_used_at` | audit |
| `rp_id` | 2.6.0, nullable: the RP id the passkey was created for (`null`: created before 2.6.0, for the RP id configured then) |

`user_type`, `user_id`, `credential_id_hash`, `user_handle` and `public_id` are exact keys (REQ-SEC-006): binary NO PAD
collation on MySQL (`utf8mb4_0900_bin`) and MariaDB (`utf8mb4_nopad_bin`), set by the schema listener and declared to
`nowo:migrations:check-key-collations` (`AuthKitExactKeyColumns`). PostgreSQL and SQLite compare exactly.

### SQL scripts

For hosts that do not use `doctrine:migrations:diff`, the bundle ships up / down scripts that create exactly the
mapped table (checked against the ORM schema on every platform in CI):

| Platform | Up | Down |
|----------|----|------|
| MySQL 8 | `src/Resources/sql/webauthn/mysql.up.sql` | `src/Resources/sql/webauthn/mysql.down.sql` |
| MariaDB 10.5+ | `src/Resources/sql/webauthn/mariadb.up.sql` | `src/Resources/sql/webauthn/mariadb.down.sql` |
| PostgreSQL | `src/Resources/sql/webauthn/postgresql.up.sql` | `src/Resources/sql/webauthn/postgresql.down.sql` |
| SQLite | `src/Resources/sql/webauthn/sqlite.up.sql` | `src/Resources/sql/webauthn/sqlite.down.sql` |

With a table prefix, replace `auth_kit_` in the table and index names. The scripts are the DBAL 4 schema; on DBAL 3
`doctrine:migrations:diff` then only proposes DBAL 3's own `(DC2Type:datetime_immutable)` column comments, which are
harmless either way. The PostgreSQL identity column also creates the sequence `auth_kit_webauthn_credential_id_seq`
that DBAL 3 hosts draw ids from. A Doctrine Migrations class can run them:

```php
public function up(Schema $schema): void
{
    foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(
        dirname(__DIR__) . '/vendor/nowo-tech/auth-kit-bundle/src/Resources/sql/webauthn/postgresql.up.sql',
    )))) as $statement) {
        $this->addSql($statement);
    }
}
```

The demo has its own migration (`demo/symfony8/migrations/Version20261125000000.php`).

## Ceremonies and security checks

| Check | How |
|-------|-----|
| Challenge | 32 random bytes per ceremony, kept server-side in the user's session (one per profile and ceremony), valid `timeout_ms` + 30 s |
| Single use | taken out of the session before the answer is verified, and claimed once in the attempt limiter's store under its per-key lock, so two parallel requests of one session cannot both use it |
| Origin | exact match against `allowed_origins` (compared lower-cased, at runtime), else exactly `https://<rp_id>` on the default port (with an explicit `rp_id` never the `Host` header; with `rp_id: null` the RP id, and so the origin, is the request host); sub-domains refused |
| RP id | the `rpIdHash` of the authenticator data must match the RP id of the ceremony's options, which must still be the current RP id (else `rp_id_mismatch`); a passkey whose stored `rp_id` differs from the current one is refused (`rp_id_mismatch`) |
| Relying party | 2.6.0: RP id and origins come from `WebAuthnRelyingPartyProviderInterface`, validated on every call; invalid = no options and no verification (`relying_party`) |
| User verification | required on every ceremony (`UV` flag), user presence too |
| Signature | COSE ES256, EdDSA (Ed25519) or RS256, by the library |
| Credential | looked up by the digest of its id in the profile; on the second step it must belong to the pending user and carry that user's handle |
| Counter | the library refuses a counter that does not increase (unless both are zero), then `UPDATE … WHERE sign_count < :new` records it: a replay, a parallel assertion with the same counter or a cloned authenticator fails (`reason: counter`, logged as a warning) |
| Attestation | every statement the browser returns is verified; `direct` refuses `none` |
| Limits | `rate_limit.*`, `max_credentials` (under a per-owner lock), the MFA pending attempts (`mfa.max_attempts`) for the second step |
| Recent sign-in | adding, renaming and removing need a full sign-in within `recent_sign_in` (remember-me and impersonation refused) |

One challenge is pending per profile and ceremony in a session: asking for new options (a second tab, the button
after the conditional UI started) replaces the previous challenge, and the older ceremony then fails with
`reason: challenge` (audit ZBX-I3; the user tries again).

**Deny by default.** An unknown credential, another profile's credential and another account's credential fail the
same way: the browser gets the same redirect and the same flash for every failure of a sign-in. Accounts cannot be
enumerated: the sign-in options carry no credential list (discoverable credentials), and the second-step options only
list the pending user's own passkeys.

A cloned authenticator is not disabled automatically (whoever copies a counter could otherwise lock the owner out);
the `WebauthnAuthenticationFailedEvent` with `reason: counter` is the signal for the host's audit.

## Relying party at runtime (2.6.0)

The RP id and the allowed origins of every ceremony come from
`Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface`:

```php
public function rpId(WebAuthnRelyingPartyContext $context): string;
/** @return list<string> */
public function allowedOrigins(WebAuthnRelyingPartyContext $context): array;
```

`WebAuthnRelyingPartyContext` (`Nowo\AuthKitBundle\ValueObject`) carries `profile`, `requestHost`, `requestOrigin`,
`configuredRpId` (`webauthn.rp_id`) and `configuredOrigins` (`webauthn.allowed_origins`). The default service,
`Nowo\AuthKitBundle\Webauthn\RelyingParty\ConfiguredWebAuthnRelyingPartyProvider`, answers the configuration as
described above. Alias the interface to your own service, or decorate it to fall back to the configuration (the
UPGRADING "To 2.6.0" section has a complete example). The provider is asked on every options and verification call:
read the current value each time and keep nothing between requests (FrankenPHP worker mode with the kernel reset off),
or implement `ResetInterface`.

**Validation (fail closed).** `Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyValidator` checks every answer:

- RP id: lower-case LDH host name (labels of 1 to 63 characters, 253 in all), no scheme, port, path or trailing dot;
  not an IP literal; at least two labels, except `localhost`; the last label is not numeric. A public suffix
  (`co.uk`, `github.io`) is **not** detected: that needs the Public Suffix List (a dependency AuthKit does not take);
  browsers refuse such an RP id themselves, so the ceremony fails in the browser.
- Origins: 1 to 50; each exactly `https://host[:port]` (no path, query, fragment or user info), or
  `http://localhost[:port]` with `allow_http_localhost` (default `kernel.debug`); each host is the RP id or a sub-domain
  of it (`login.example.com` for `example.com`, never `badexample.com`).

The origins are asked after the RP id, with the resolved RP id in `$context->resolvedRpId`; the default derives
`https://<rp id>` from it, never from the request `Host` (audit ZCQ-M1). An origin that cannot serve the RP id
(another domain, a malformed entry, an explicit default port) is ignored with a warning; passkeys stay on while one
usable origin remains (BC with 2.5 multi-domain lists). Messages JSON-encode the values.

The values are lower-cased before the check. An invalid RP id, no usable origin, or an exception thrown by the
provider:

- options are not issued: `503 {"error": "unavailable"}`; the script sets the state `unavailable` ("Passkeys are not
  available right now. Sign in with your password."), also from the conditional UI, and disables the button;
- a registration, sign-in or second step in progress is refused (`reason: relying_party`), with the flash
  `webauthn.flash.unavailable`; nothing is verified against a half-valid relying party;
- the account page still lists, renames and removes passkeys, without the Signal API data;
- the failure is logged at error level (`Passkeys are disabled: …`, with the RP id and the violations; for a provider
  exception only its class, code and a digest of its message: keep secrets out of exception messages).

Hosts validate in their settings form with `WebAuthnRelyingPartyValidator::violations($rpId, $origins,
$allowHttpLocalhost)` (empty list = valid; `$allowHttpLocalhost` = the profile's `webauthn.allow_http_localhost`, else
`kernel.debug`). It is strict: it reports every unusable origin.

**Changing the RP id.** Every passkey is bound to the RP id it was created for (stored in `rp_id`). After a change:
a passkey of the former RP id is refused with `reason: rp_id_mismatch` (logged as a warning), and so is an answer to
options issued under the former RP id. Before saving a new RP id, warn with
`WebauthnManager::countCredentialsOutsideRpId($newRpId, ?$profile)` ("N passkeys will stop working": those of another
RP id and those whose `rp_id` is null); `countCredentialsForRpId($rpId, ?$profile)` counts the passkeys of one RP id.
Both compare in PHP, byte for byte after lower-casing, and read every row's `rp_id`: use them for an administrator's
warning, not on every request.

## Passkeys and MFA

- First factor `webauthn` (`mfa.first_factors.webauthn`) defaults to **exempt**: a passkey with user verification is
  already possession plus knowledge or inherence. Set it to `required` to ask for a second step anyway. The exemption
  applies only with `webauthn.mfa: true` (audit ZBX-L1): with `webauthn.mfa: false` the profile says a passkey is not a
  second factor, so a passkey sign-in of a user with TOTP is always challenged.
- `MfaManager::hasSecondFactor($user)`: TOTP on, or at least one passkey with `webauthn.mfa`. A required user with a
  passkey is challenged, never forced into TOTP enrolment; `isEnabled()` still means TOTP only.
- The challenge page shows the code form only when TOTP is on, and **Use a passkey** when the user has one. A passkey
  step uses one attempt of the pending sign-in, like a code. `MfaChallengePassedEvent::$method` is `webauthn`.
- **Last-factor guard:** while a second factor is required (`MfaManager::requirement()`) and TOTP is off, the last
  passkey cannot be removed from the account page (`webauthn.flash.last_factor`). `WebauthnManager::remove($user, $id,
  keepOne: true)` gives hosts the same guard.
- The forced enrolment page (`mfa_setup`) still enrols TOTP; a required user can add a passkey on the account page
  during the grace period instead.

## Sign-in page, conditional UI and the phone QR code

The login page renders a small form after the password form (block `nowo_auth_kit_webauthn_login`). The bundled script
starts the conditional UI on load, so the browser lists the passkeys of the site under the identifier field; the
button runs the modal ceremony. Choosing **use a phone or tablet** in that dialog shows the browser's QR code: this is
WebAuthn's hybrid transport, handled by the browser and the operating system. The phone approves with its own passkey;
AuthKit only sees an ordinary assertion.

## Account page

`/account/webauthn` (template key `webauthn_account`, UiKit admin layout): the list (name, added, last used, synced),
rename and remove per passkey, and **Add a passkey** with a name field while the account has fewer than
`max_credentials`. Without a recent sign-in the page asks to sign in again instead. On load the script hands the
passkeys the server still accepts to the browser's Signal API (`PublicKeyCredential.signalAllAcceptedCredentials()`,
where available; failures only warn), so a passkey removed here also leaves the user's password manager.

## Templates and the script

| Template | Override |
|----------|----------|
| `@NowoAuthKitBundle/webauthn/account.html.twig` | `web_ui.templates.webauthn_account`, or the Twig path |
| `@NowoAuthKitBundle/webauthn/_ceremony.html.twig` | Twig path (shared by the login, second-step and registration forms) |
| `@NowoAuthKitBundle/webauthn/_assets.html.twig` | Twig path (the script tag) |

Blocks: `nowo_auth_kit_webauthn_login` (login page), `nowo_auth_kit_webauthn_mfa` (challenge page),
`nowo_auth_kit_webauthn_account_before` (account page navigation).

`public/js/nowo-auth-kit-webauthn.js` is hand-written, CSP safe (no inline code, no eval) and carries the request
attribute `csp_nonce` as its `nonce`, like every AuthKit script. Roots
`[data-nowo-auth-kit-webauthn="login|mfa|register"]` get `data-nowo-auth-kit-webauthn-state` (`idle`, `working`,
`unsupported`, `failed`, `cancelled`, and since 2.6.0 `unavailable`: the server disabled passkeys) and dispatch a bubbling `nowo-auth-kit:webauthn-state` event
(`{detail: {mode, state}}`). It uses `PublicKeyCredential.parse*OptionsFromJSON()` and `toJSON()` where the browser has
them, and an equivalent fallback otherwise; the conditional UI starts only when `PublicKeyCredential.getClientCapabilities()` (or
`isConditionalMediationAvailable()`) reports it. Without JavaScript the forms say that passkeys need it. Front-end
checked against Chrome Modern Web Guidance (`passkeys`, `passkey-authentication`, `passkey-registration`,
`passkey-management`).

State texts (2.8.0): each root carries `data-label-<state>` from the translation key `webauthn.<mode>.state.<state>`
(domain `NowoAuthKitBundle`, mode `login`, `mfa` or `register`) when it exists, else `webauthn.state.<state>`. A host
can override one mode without touching the others. AuthKit ships `webauthn.login.state.cancelled` ("No passkey was
used. If this device has no passkey for this site yet, sign in with your password and add one in your account's
security settings. A passkey made on another site or server doesn't work here.") and `webauthn.mfa.state.cancelled`
(another verification method) in its 7 locales; registration keeps "Cancelled". The text deliberately covers both a
cancelled prompt and a device without a passkey for this site: the browser reports both as `NotAllowedError` and does
not let a page tell them apart (privacy), so AuthKit does not try.

## Events

All framework-free (profile, owner type and id, passkey public id), dispatched after the write:

| Event | When |
|-------|------|
| `Webauthn\Event\WebauthnCredentialAddedEvent` | a passkey was registered (`label`, `synced`) |
| `Webauthn\Event\WebauthnCredentialUsedEvent` | a passkey signed in (`context: login`) or passed a second step (`context: mfa`) |
| `Webauthn\Event\WebauthnCredentialRenamedEvent` | renamed by its owner (2.5.0: `oldLabel`, `newLabel`; not for the same label) |
| `Webauthn\Event\WebauthnCredentialRemovedEvent` | removed by its owner (`reason: user`) or by a personal-data erasure (`reason: erasure`) |
| `Webauthn\Event\WebauthnAuthenticationFailedEvent` | a refused ceremony (`context`: `login`, `mfa`, `register`; `reason`: `malformed`, `challenge`, `unknown_credential`, `invalid`, `counter`, `duplicate`, `limit`, `rate_limited`, `user`, and since 2.6.0 `relying_party`, `rp_id_mismatch`) |

## PHP API

`Nowo\AuthKitBundle\Webauthn\WebauthnManager` (public service): `profileFor()`, `passkeys()` (list of
`WebauthnPasskey`: public id, label, dates, transports, synced, AAGUID; never key material), `hasSecondFactor()`,
`registrationOptions()` / `register()`, `loginOptions()` / `login()`, `mfaOptions()` / `verifyMfa()`, `rename()`,
`remove()` (`WebauthnRemoval::Removed | Unknown | LastFactor`), and since 2.6.0 `countCredentialsForRpId()` /
`countCredentialsOutsideRpId()`. Refusals throw `WebauthnFailure` with a `reason`.

## Personal data

With UserKit installed and the entities mapped (also while no profile enables passkeys today):

- eraser `auth_kit_webauthn` (priority 200, before host identity erasers): deletes the user's passkeys in every
  profile, by DBAL statements inside UserKit's erasure transaction, also under the identifier captured when the
  erasure started (same rule as MFA);
- export section `passkeys`: profile, label, dates, transports, synced, AAGUID per passkey.

Passkey rows reference the user by owner type and id without a foreign key. A host that deletes users without
UserKit's erasure calls `WebauthnManager::forgetUser($user)` (or `MfaManager::forgetUser($user)`, which calls it since
2.4.0) in its deletion flow (audit ZBX-L3): a passkey left behind would sign in a later account that gets the same id
(an e-mail natural key, a reused auto-increment id). Since 2.4.1 (audit ZBX-v-I2) this works **whatever the passkey
mode is today**: `MfaManager::forgetUser()` deletes stored passkeys also when every profile has turned passkeys off
(no `WebauthnManager` then), and the public service `Nowo\AuthKitBundle\Webauthn\WebauthnErasure` (always
registered; `forgetUser($user): int`) does the same for hosts without MFA. Without a mapped entity or a table there is
nothing to erase.

## Worker mode and concurrency

- Every service is stateless (`readonly`); the library's serializer and validators are built per call; reads return
  detached values, never managed entities. `WebauthnWorkerKernelTest` runs 300 alternating passkey sign-ins of two
  users in one kernel that is never reset, with a refused cross-user assertion every third round, and asserts flat
  memory. `WebauthnRelyingPartyWorkerKernelTest` (2.6.0) changes the host's relying party setting between requests of
  one kept-alive kernel and checks each request gets the value of that moment.
- Concurrency (REQ-RUNTIME-003): challenge claim under the limiter lock; counter by conditional UPDATE; registration
  limit and last-factor guard under a per-owner lock; a credential id registered twice hits the unique key
  (`duplicate`). See [CONCURRENCY.md](CONCURRENCY.md).

## Differences from the 1.12 design

- `user_verification` is not configurable: always `required` (Beacon request 2026-11-25).
- `rp_name` is gone: WebAuthn Level 3 deprecates `rp.name` and web-auth/webauthn-lib 5.3 fills it with the RP id.
- The `id` column is an integer with a separate `public_id` (as the other AuthKit tables), and the credential id is
  found by a unique digest (`credential_id_hash`): a 1,023-byte credential id does not fit a MySQL unique index.
- New: the second-step routes, the account page, rename, `max_credentials`, `recent_sign_in`, `allowed_origins`,
  `passwordless` / `mfa` switches, `hash_secret`.

## Host integration (Beacon and others)

1. `composer update nowo-tech/auth-kit-bundle` (2.4.0 adds `web-auth/webauthn-lib` ^5.3.17, `symfony/serializer`,
   `symfony/uid`; see UPGRADING for the transitive packages), keep `symfony/lock`.
2. Create the table: `doctrine:migrations:diff`, or one of the [SQL scripts](#sql-scripts).
3. Enable `webauthn.mode: enabled` with an explicit `rp_id` equal to the login host (for example `login.example.com`;
   the default origin is then `https://login.example.com`), `max_credentials: 10`. With a parent-domain `rp_id`, set
   `allowed_origins` to the exact login origins. Set `framework.trusted_hosts`.
4. Access control: `^/login/webauthn` public like `/login`; `/account/webauthn` for signed-in users.
5. Link **Account → Security → Passkeys** to `nowo_auth_kit_webauthn_account` (or render your own page with
   `WebauthnManager::passkeys()` and the routes above, extending `webauthn/account.html.twig`).
6. MFA policies (`mfa.policy`) need no change: a passkey counts through `MfaManager::hasSecondFactor()`. A host that
   removes passkeys itself passes `keepOne: true` when the user would be left without a required factor.
7. 2.6.0, optional: keep the RP id and the origins in your database with `WebAuthnRelyingPartyProviderInterface`
   ([Relying party at runtime](#relying-party-at-runtime-260); adoption steps in UPGRADING "To 2.6.0").
8. Audit: listen to the five events (`WebauthnCredentialRenamedEvent` since 2.5.0); `WebauthnAuthenticationFailedEvent` with `reason: counter` may be a cloned key.
   Notify the user on `WebauthnCredentialAddedEvent` and `WebauthnCredentialRemovedEvent` (Beacon: NotifyKit): the
   bundle does not mail, and a hijacked fresh session could otherwise bind a passkey silently (audit ZBX-I7).
8. Account deletion without UserKit: call `WebauthnErasure::forgetUser()` (any mode, 2.4.1), `MfaManager::forgetUser()` or
   `WebauthnManager::forgetUser()` (ZBX-L3).
