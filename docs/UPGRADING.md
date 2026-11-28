# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 2.9.0](#to-290)
- [To 2.8.0](#to-280)
  - [Schema: one nullable column](#schema-one-nullable-column)
  - [Enterprise client secrets fail closed](#enterprise-client-secrets-fail-closed)
  - [Behaviour that changes](#behaviour-that-changes)
- [To 2.7.1](#to-271)
- [To 2.7.0](#to-270)
  - [Schema: four nullable columns](#schema-four-nullable-columns)
  - [Behaviour that changes](#behaviour-that-changes-1)
- [To 2.6.0](#to-260)
  - [To 2.6.0](#to-260-1)
- [To 2.5.0](#to-250)
  - [To 2.5.0](#to-250-1)
- [To 2.4.1](#to-241)
- [To 2.4.0](#to-240)
  - [Passkeys (opt-in)](#passkeys-opt-in)
  - [Google mark in the dark theme](#google-mark-in-the-dark-theme)
- [To 2.3.0](#to-230)
  - [Schema: three nullable columns](#schema-three-nullable-columns)
  - [New providers](#new-providers)
  - [Behaviour that changes](#behaviour-that-changes-2)
- [To 2.2.1](#to-221)
- [To 2.2.0](#to-220)
  - [Password reset: one link stores one password (REQ-RUNTIME-003)](#password-reset-one-link-stores-one-password-req-runtime-003)
- [To 2.1.0](#to-210)
  - [The bundle declares its own identity / access-key columns (REQ-SEC-006)](#the-bundle-declares-its-own-identity--access-key-columns-req-sec-006)
- [To 2.0.0](#to-200)
  - [Before you upgrade](#before-you-upgrade)
  - [Default flips](#default-flips)
  - [Private manager only](#private-manager-only)
  - [Port interfaces](#port-interfaces)
  - [Event and result members](#event-and-result-members)
  - [Configuration](#configuration)
  - [Templates, blocks and globals](#templates-blocks-and-globals)
  - [Fixes that come with 2.0](#fixes-that-come-with-20)
  - [Other removals](#other-removals)
  - [1.x support](#1x-support)
- [To 1.56.0](#to-1560)
  - [Preparing for 2.0: the tag and action constants](#preparing-for-20-the-tag-and-action-constants)
- [To 1.55.1](#to-1551)
- [To 1.55.0](#to-1550)
  - [AuthKit rows loaded through your own entity manager](#authkit-rows-loaded-through-your-own-entity-manager)
  - [Preparing for 2.0: read event properties through their getters](#preparing-for-20-read-event-properties-through-their-getters)
- [To 1.54.1](#to-1541)
- [To 1.54.0](#to-1540)
  - [The private entity manager notice](#the-private-entity-manager-notice)
  - [Reading the host-flush probe](#reading-the-host-flush-probe)
  - [Other notices](#other-notices)
  - [Preparing for 2.0](#preparing-for-20)
- [To 1.53.0](#to-1530)
- [To 1.52.0](#to-1520)
- [To 1.52.0](#to-1520-1)
- [To 1.51.1](#to-1511)
  - [To 1.51.1](#to-1511-1)
- [To 1.51.0](#to-1510)
  - [To 1.51.0 (MFA code on OTP boxes)](#to-1510-mfa-code-on-otp-boxes)
- [To 1.50.0](#to-1500)
- [To 1.49.0](#to-1490)
- [To 1.49.0](#to-1490-1)
  - [Identifier normaliser (default unchanged)](#identifier-normaliser-default-unchanged)
  - [Moving the identifier column to a binary collation](#moving-the-identifier-column-to-a-binary-collation)
  - [Throttling, MFA and LoginThrottleBundle](#throttling-mfa-and-loginthrottlebundle)
  - [Writing your own normaliser](#writing-your-own-normaliser)
- [To 1.48.0](#to-1480)
  - [Extra authorization parameters (1.48)](#extra-authorization-parameters-148)
  - [Schema: write the migration by hand (1.48) — required on MySQL / MariaDB](#schema-write-the-migration-by-hand-148--required-on-mysql--mariadb)
  - [An extra parameter embedded in `authorize_url` (1.48)](#an-extra-parameter-embedded-in-authorize_url-148)
  - [The account chooser on google and microsoft (`prompt=select_account`, 1.48)](#the-account-chooser-on-google-and-microsoft-promptselect_account-148)
  - [Provider brand marks on the social / SSO buttons (1.48)](#provider-brand-marks-on-the-social--sso-buttons-148)
- [To 1.47.0](#to-1470)
  - [Identity columns matched byte for byte (MySQL / MariaDB)](#identity-columns-matched-byte-for-byte-mysql--mariadb)
- [To 1.46.0](#to-1460)
  - [Framework-free host ports (optional until 2.0)](#framework-free-host-ports-optional-until-20)
  - [Event accessors](#event-accessors)
  - [Private entity manager (`doctrine.private_entity_manager`)](#private-entity-manager-doctrineprivate_entity_manager)
  - [XML mapping (`doctrine.mapping_type`)](#xml-mapping-doctrinemapping_type)
  - [Reset credential in a bundle table (optional)](#reset-credential-in-a-bundle-table-optional)
  - [Coming in 2.0](#coming-in-20)
- [To 1.45.2](#to-1452)
- [To 1.45.1](#to-1451)
- [To 1.45.0](#to-1450)
- [To 1.44.0](#to-1440)
- [To 1.43.0](#to-1430)
- [To 1.42.0](#to-1420)
- [To 1.41.0](#to-1410)
- [To 1.40.0](#to-1400)
- [To 1.39.0](#to-1390)
- [To 1.38.0](#to-1380)
- [To 1.37.1](#to-1371)
- [To 1.37.0](#to-1370)
- [To 1.36.1](#to-1361)
- [To 1.36.0](#to-1360)
- [To 1.35.0](#to-1350)
- [To 1.34.0](#to-1340)
- [To 1.33.1](#to-1331)
- [To 1.33.0](#to-1330)
- [To 1.32.2](#to-1322)
  - [Logout with locale.in_path (1.31.2+)](#logout-with-localein_path-1312)
- [To 1.32.1](#to-1321)
- [To 1.32.0](#to-1320)
- [To 1.31.2](#to-1312)
- [To 1.31.1](#to-1311)
- [To 1.31.0](#to-1310)
  - [Security hardening (behaviour changes)](#security-hardening-behaviour-changes)
  - [Translation domain and routes](#translation-domain-and-routes)
- [To 1.30.0](#to-1300)
- [To 1.29.0](#to-1290)
- [To 1.28.0](#to-1280)
- [To 1.27.3](#to-1273)
- [To 1.27.2](#to-1272)
- [To 1.27.1](#to-1271)
- [To 1.27.0](#to-1270)
  - [Template contract (1.27)](#template-contract-127)
- [To 1.26.0](#to-1260)
- [To 1.25.0](#to-1250)
- [To 1.24.1](#to-1241)
- [To 1.24.0](#to-1240)
- [To 1.23.1](#to-1231)
- [To 1.23.0](#to-1230)
- [To 1.22.4](#to-1224)
- [To 1.22.3](#to-1223)
- [To 1.22.2](#to-1222)
- [To 1.22.0](#to-1220)
- [To 1.21.1](#to-1211)
- [To 1.21.0](#to-1210)
- [To 1.20.1](#to-1201)
- [To 1.20.0](#to-1200)
- [To 1.19.0](#to-1190)
- [To 1.18.0](#to-1180)
- [From 1.17.4 to 1.17.5](#from-1174-to-1175)
- [To 1.17.4](#to-1174)
- [To 1.17.3](#to-1173)
- [To 1.17.2](#to-1172)
- [To 1.17.1](#to-1171)
- [To 1.17.0](#to-1170)
  - [Magic login confirm Form CSRF (from 1.16.x)](#magic-login-confirm-form-csrf-from-116x)
- [To 1.16.0](#to-1160)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
- [To 1.12.2](#to-1122)
- [To 1.12.1](#to-1121)
- [To 1.12.0](#to-1120)
  - [Optional: QR phone login](#optional-qr-phone-login)
  - [Optional: enterprise SSO buttons](#optional-enterprise-sso-buttons)
  - [WebAuthn](#webauthn)
- [To 1.11.0](#to-1110)
  - [Template structure](#template-structure)
  - [Optional: outbound mail readiness](#optional-outbound-mail-readiness)
  - [Optional: shared form themes and CSS classes](#optional-shared-form-themes-and-css-classes)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
  - [Behaviour changes (defaults)](#behaviour-changes-defaults)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [Social login (optional)](#social-login-optional)
- [To 1.8.2](#to-182)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
- [To 1.7.6](#to-176)
- [To 1.7.5](#to-175)
- [To 1.7.4](#to-174)
- [To 1.7.3](#to-173)
- [To 1.7.2](#to-172)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.4](#to-144)
- [To 1.4.3](#to-143)
- [To 1.4.2](#to-142)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
  - [Login template: register link](#login-template-register-link)
  - [Optional: new translations](#optional-new-translations)
- [To 1.3.0](#to-130)
  - [Optional: password strength](#optional-password-strength)
- [To 1.2.0](#to-120)
  - [Optional: remember me](#optional-remember-me)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
  - [Optional: password reset](#optional-password-reset)
  - [Optional: embedded auth dropdown](#optional-embedded-auth-dropdown)
  - [Optional: locale in URL paths](#optional-locale-in-url-paths)
  - [Demo users](#demo-users)
- [To 1.0.0](#to-100)
- [Future upgrades](#future-upgrades)

## Unreleased

- PHP 8.6 is allowed (`>=8.2 <8.7`). Nothing to change in the host.
- Symfony 8.2: after a second step, the firewall token carries the authentication proofs (`otp` / `hwk` and `mfa`, plus
  the first factor's), so `IS_AUTHENTICATED_RECENTLY` counts the second step. A pending sign-in has no proof.

## To 2.9.0

From **2.8.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 2.8.0

From **2.7.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Planned as **2.8.0** (minor), from **2.7.x**:

```bash
composer update nowo-tech/auth-kit-bundle
```

OpenID Connect RP-initiated logout (opt-in per credential) and enterprise client secrets that are never written
unencrypted. See [SSO.md](SSO.md#rp-initiated-logout-280).

<a id="schema-one-nullable-column-280"></a>
### Schema: one nullable column

`auth_kit_social_credential` gains `rp_initiated_logout` (`BOOLEAN NULL`). Existing rows keep `NULL` (local sign-out
only, as in 2.7.0).

```bash
php bin/console doctrine:migrations:diff   # then review and migrate
```

```sql
-- MySQL / MariaDB
ALTER TABLE auth_kit_social_credential ADD rp_initiated_logout TINYINT(1) DEFAULT NULL;
-- PostgreSQL
ALTER TABLE auth_kit_social_credential ADD rp_initiated_logout BOOLEAN DEFAULT NULL;
-- SQLite
ALTER TABLE auth_kit_social_credential ADD COLUMN rp_initiated_logout BOOLEAN DEFAULT NULL;
```

Use your table prefix (`doctrine.table_prefix`) when you set one. Migrate before switching traffic.

<a id="enterprise-client-secrets-fail-closed-280"></a>
### Enterprise client secrets fail closed

With the default `doctrine.require_enterprise_secret_encryption: true`, inserting or updating a credential with
`enterprise_sso` true **and** a non-empty client secret throws `Doctrine\EnterpriseSecretNotEncryptedException`
(nothing is written) unless nowo-tech/doctrine-encrypt-bundle encrypts on the entity manager that writes
`auth_kit_social_credential`: the bundle registered and configured (a key), its listener on that connection
(`nowo_doctrine_encrypt.connections` empty or listing it). Nothing changes for applications without enterprise
credentials, for consumer credentials (`enterprise_sso` false) or for enterprise credentials without a secret; rows
already stored are only checked when they are written again.

Before upgrading, if you store enterprise secrets without DoctrineEncrypt: install and configure it, then encrypt the
existing rows (`php bin/console doctrine:encrypt:database`). Set the option to `false` only in tests and demos.

<a id="behaviour-that-changes-280"></a>
### Behaviour that changes

- The discovery cache key changes (`nowo_auth_kit.oidc.metadata.v2.`): each issuer's document is fetched once more
  after the upgrade, then cached as before.
- `OidcProviderMetadata` takes an optional 8th constructor argument (`?string $endSessionEndpoint`) and `toArray()`
  returns one more key, `end_session_endpoint`.
- `SocialLoginCheckController` takes an optional last constructor argument (`?RpLogoutSession $rpLogout`); a host that
  builds it itself gets no RP-initiated logout without it.
- The session gains `_nowo_auth_kit_rp_logout` after an OIDC sign-in with an opted-in credential (sealed; removed on
  sign-out, replaced or removed by the next social sign-in).
- Passkeys: the sign-in and MFA pages show a longer `cancelled` text (new keys `webauthn.login.state.cancelled` and
  `webauthn.mfa.state.cancelled`). A host that overrode `webauthn.state.cancelled` and wants its text on those pages
  too overrides the per-mode keys as well (the per-mode key wins; `webauthn.<mode>.state.<state>` for any state and mode
  works the same way). See [WEBAUTHN.md](WEBAUTHN.md#templates-and-the-script).
- Audit ZCX-v-I1 (2.7.0 note): after you delete an `auth_kit_social_account` row with a NULL issuer, the next sign-in
  with that provider links again by e-mail under the credential's `link_existing_account` (or the profile's). With
  `deny` that sign-in is refused: re-link the user through your own linking flow (see
  [SSO.md](SSO.md#link_existing_account-per-credential)).

## To 2.7.1

From **2.7.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

A patch: no schema or configuration change, nothing required. Behaviour that changes (the fix):

- **Remember-me sessions see the login form.** `/login`, `/magic-login`, the reset-password pages and the passkey
  sign-in endpoint no longer redirect a remember-me session (only `IS_AUTHENTICATED_REMEMBERED`) to `login_success_route`: it signs in again and
  returns to the page that needed a full sign-in. A fully authenticated user is still redirected. Hosts that relied on
  `/login` redirecting a remembered user (a "go to the app" link pointing at `/login`) should link to the app instead.
- **Passkey account page.** A remember-me session gets the page (200, "sign in again" state) instead of a redirect to
  the login page. A host override of `webauthn/account.html.twig` keeps working (`webauthn_logout_route` is still
  passed); to send the user to the login page and back, use the new `webauthn_sign_in_again_url` when it is set (see
  the bundled template).
- **Another account from a remembered browser.** Signing in as a different account clears the remembered account's
  remember-me cookie (the new account gets its own when "Remember me" is ticked).
- **Per-user session data.** The session id is migrated on an account switch but its attributes stay (Symfony
  `migrate`): clear your own per-user session keys on `LoginSuccessEvent` when the identifier changes.
- Host login templates can read the new `reauthenticate` variable (`registration_allowed` is false on that form); the bundled one shows `page.intro`
  (`login.reauthenticate`).

## To 2.7.0

From **2.6.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Planned as **2.7.0** (minor), from **2.6.x**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Enterprise OpenID Connect per organization (Beacon spec 221): unlisted credentials, `login_hint`, `link_existing_account`
and the MFA first-factor policy per credential, validated ID token claims for host handlers, and the host-minted test
sign-in. Nothing changes until you set the new values: see [SSO.md](SSO.md#enterprise-oidc-per-organization-270).

### Schema: four nullable columns

`auth_kit_social_credential` gains `listed` (`BOOLEAN NULL`), `link_existing_account` (`VARCHAR(16) NULL`),
`mfa_first_factor` (`VARCHAR(16) NULL`) and `mfa_accepted_amr` (`JSON NULL`). All nullable, no data migration: existing
rows keep `NULL` (listed, the profile's link policy, the profile's MFA rule).

```bash
php bin/console doctrine:migrations:diff   # then review and migrate
```

```sql
-- MySQL / MariaDB
ALTER TABLE auth_kit_social_credential ADD listed TINYINT(1) DEFAULT NULL, ADD link_existing_account VARCHAR(16) DEFAULT NULL, ADD mfa_first_factor VARCHAR(16) DEFAULT NULL, ADD mfa_accepted_amr JSON DEFAULT NULL;
-- PostgreSQL
ALTER TABLE auth_kit_social_credential ADD listed BOOLEAN DEFAULT NULL;
ALTER TABLE auth_kit_social_credential ADD link_existing_account VARCHAR(16) DEFAULT NULL;
ALTER TABLE auth_kit_social_credential ADD mfa_first_factor VARCHAR(16) DEFAULT NULL;
ALTER TABLE auth_kit_social_credential ADD mfa_accepted_amr JSON DEFAULT NULL;
-- SQLite
ALTER TABLE auth_kit_social_credential ADD COLUMN listed BOOLEAN DEFAULT NULL;
ALTER TABLE auth_kit_social_credential ADD COLUMN link_existing_account VARCHAR(16) DEFAULT NULL;
ALTER TABLE auth_kit_social_credential ADD COLUMN mfa_first_factor VARCHAR(16) DEFAULT NULL;
ALTER TABLE auth_kit_social_credential ADD COLUMN mfa_accepted_amr CLOB DEFAULT NULL; -- (DC2Type:json)
```

Use your table prefix (`doctrine.table_prefix`) when you set one. Until the columns exist every page that reads the
credential table fails, as for any new mapped column: migrate before switching traffic.

<a id="behaviour-that-changes-270"></a>
### Behaviour that changes

- `SocialLoginStateStore::consumePending()` returns one more key, `test` (bool). Only code that calls the store
  directly (tests, host start controllers) sees it; an `assertSame` on the whole array needs `'test' => false`.
- `GET /login/social/{provider}` reads `login_hint`. A valid address is now sent to the provider (it may pre-fill its
  sign-in form); an invalid one is ignored as before.
- `SocialAccountLinker` looks the provider credential up (one `findOneByProvider()` per first link of an existing
  account) to read its `link_existing_account`. A host that builds the linker itself can pass `credentials:`; without it
  the profile value applies, as before.
- Fixed (audit ZCX-I4): an invitation accepted with an OpenID Connect provider now stores the link with its issuer.
  Links stored before with a NULL issuer for an OIDC provider (`issuer IS NULL` on `auth_kit_social_account` for a
  provider key that signs in through OIDC) still refuse to sign in: delete those rows, the next sign-in links again.
- Enterprise credentials: call `setLinkExistingAccount('deny')` on every credential whose IdP you do not control; NULL
  inherits the profile's `link_existing_account` (default `reset_password`).
- Client secrets: unchanged. `client_secret` is encrypted at rest only when DoctrineEncrypt is installed and configured;
  configure it before you store enterprise secrets ([SECURITY.md](SECURITY.md#social-login-client-secrets)).

## To 2.6.0

From **2.5.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### To 2.6.0

From **2.5.0** (a minor):

```bash
composer update nowo-tech/auth-kit-bundle
```

Nothing is required when passkeys are off (`webauthn.mode: disabled`). With passkeys on, add the column below. Your
`webauthn.rp_id` / `webauthn.allowed_origins` keep working unchanged: they are now read by the default
`WebAuthnRelyingPartyProviderInterface`.

#### Schema: the RP id of each passkey

`auth_kit_webauthn_credential` gains `rp_id` (`VARCHAR(253) NULL`): the RP id a passkey was created for. `NULL` means
"created before 2.6.0, for the RP id configured then"; such a passkey keeps working. Backfill it with your current RP
id, so a later RP id change is reported as `rp_id_mismatch` and the passkeys it orphans are counted exactly:

```sql
-- MySQL 8.0+ / MariaDB 10.5+
ALTER TABLE auth_kit_webauthn_credential ADD rp_id VARCHAR(253) DEFAULT NULL;
UPDATE auth_kit_webauthn_credential SET rp_id = LOWER('example.com') WHERE rp_id IS NULL;
-- PostgreSQL 12+
ALTER TABLE auth_kit_webauthn_credential ADD rp_id VARCHAR(253) DEFAULT NULL;
UPDATE auth_kit_webauthn_credential SET rp_id = LOWER('example.com') WHERE rp_id IS NULL;
-- SQLite 3
ALTER TABLE auth_kit_webauthn_credential ADD COLUMN rp_id VARCHAR(253) DEFAULT NULL;
UPDATE auth_kit_webauthn_credential SET rp_id = LOWER('example.com') WHERE rp_id IS NULL;
```

Replace `example.com` with exactly your RP id: `webauthn.rp_id`, or, with `rp_id: null`, the host name of your login
page. No scheme, no port, no `www.` you do not use as RP id (AuthKit compares it lower-cased). After the backfill,
`WebauthnManager::countCredentialsOutsideRpId('example.com')` must answer 0: a wrong value turns every passkey into
`rp_id_mismatch`. With several profiles of different RP ids, add `AND profile = '<name>'` per profile. Use your
table prefix (`doctrine.table_prefix`) when you set one. Run the `ALTER` before switching traffic (every passkey query
reads the column); the `UPDATE` may run later. `php bin/console doctrine:migrations:diff` generates the `ALTER` (review
it). The shipped `src/Resources/sql/webauthn/*.up.sql` create the table with the column.

#### What changes in 2.6.0

- **`http://localhost` origins need development.** Accepted with `kernel.debug`, or with
  `webauthn.allow_http_localhost: true`. A production-mode kernel (debug off) that served passkeys on
  `http://localhost` now disables them (options answer 503): set `allow_http_localhost: true` for that local setup.
  Real deployments use https and are not affected.
- **An IP RP id is refused** (e.g. `rp_id: null` with a request on `http://127.0.0.1`): browsers already refused it.
- **Allowed origins that cannot serve the RP id are ignored, with a warning** (another domain, a malformed entry, an
  explicit default port such as `https://example.com:443`): `allowed_origins: [https://example.com,
  https://example.org]` with `rp_id: null` keeps working on both domains, each with its own origin. Passkeys are
  disabled only when no listed origin can serve the RP id. Watch the log for "A WebAuthn allowed origin is ignored".
  `WebAuthnRelyingPartyValidator::violations()` stays strict for settings forms.
- **Invalid relying party = passkeys off, password on.** Options answer `503 {"error": "unavailable"}`; the bundled
  script shows the new `unavailable` state ("Passkeys are not available right now. Sign in with your password.") and
  disables the passkey button; a pending sign-in, second step or registration is refused (`relying_party`) and the page
  flashes `webauthn.flash.unavailable`. The failure is logged at error level with the violations. A custom
  `webauthn/_ceremony.html.twig` override must add `data-label-unavailable`.
- **New failure reasons** on `WebauthnAuthenticationFailedEvent::$reason`: `relying_party` and `rp_id_mismatch` (a
  passkey, or a challenge, of another RP id than the current one). Map them in your audit log if you switch on reasons.
- The account page leaves out `data-nowo-auth-kit-webauthn-signal` while the relying party is invalid
  (`webauthn_signal` is `null` for a custom template).

#### Keeping the relying party in your database (Beacon adoption)

1. Upgrade, add the column and backfill it (above).
2. Implement `Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface`. Read the setting on every call (no
   property cache, no memo across requests: FrankenPHP worker mode with the kernel reset off) and fall back to the
   configuration by decorating the default. The origins are asked after the RP id, with the resolved RP id in
   `$context->resolvedRpId`: the default provider derives `https://<rp id>` from it, never from the request `Host`, so
   storing only the RP id is safe (a forged `Host: evil.example.com` never becomes an expected origin):

   ```php
   final readonly class InstanceWebAuthnRelyingParty implements WebAuthnRelyingPartyProviderInterface
   {
       public function __construct(private InstanceSettings $settings, private WebAuthnRelyingPartyProviderInterface $inner) {}

       public function rpId(WebAuthnRelyingPartyContext $context): string
       {
           return $this->settings->get('webauthn_rp_id') ?? $this->inner->rpId($context);
       }

       public function allowedOrigins(WebAuthnRelyingPartyContext $context): array
       {
           return $this->settings->get('webauthn_allowed_origins') ?? $this->inner->allowedOrigins($context);
       }
   }
   ```

   ```yaml
   App\Security\InstanceWebAuthnRelyingParty:
       decorates: Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface
       arguments: { $inner: '@.inner' }
   ```

   Or replace it outright: `Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface: '@App\Security\InstanceWebAuthnRelyingParty'`.
   An exception thrown by the provider (settings store down) disables passkeys for that request only. AuthKit logs its
   class, code and a digest of its message, never the message itself; still, keep secrets (a DSN, a token) out of
   exception messages.
3. In the settings form, validate before saving with
   `WebAuthnRelyingPartyValidator::violations($rpId, $origins, $allowHttpLocalhost)` (an empty list = valid; pass the
   profile's `webauthn.allow_http_localhost`, else `kernel.debug`) and show the messages. AuthKit validates again on every ceremony and fails closed.
4. Before saving a new RP id, warn with `WebauthnManager::countCredentialsOutsideRpId($newRpId)`: "N passkeys will stop
   working" (their owners sign in with the password and register a new passkey). `countCredentialsForRpId()` gives the
   passkeys of an RP id.
5. Keep `WEBAUTHN_RP_ID` / `allowed_origins` in the config as the fallback, or remove them once the setting is saved.
6. Map the new reasons `relying_party` and `rp_id_mismatch` in the security audit log, and check the login page shows
   the `unavailable` state when the setting is invalid.
7. Local stacks on `http://localhost` with `APP_DEBUG=0`: set `webauthn.allow_http_localhost: true`.

## To 2.5.0

From **2.4.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### To 2.5.0

From **2.4.1** (a minor: no schema change, nothing required).

- **New event `Webauthn\Event\WebauthnCredentialRenamedEvent`.** Dispatched after the owner renamed a passkey (not for
  a rename to the same label). Payload: `profile`, `userType`, `userId`, `publicId` (the passkey's public id, never the
  credential id), `oldLabel`, `newLabel`. Labels are user-chosen text: escape them when you render them. Beacon
  (security audit log) adopts it with a listener:

  ```php
  use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRenamedEvent;
  use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

  #[AsEventListener]
  final readonly class PasskeyRenamedAuditListener
  {
      public function __construct(private SecurityAuditLog $audit)
      {
      }

      public function __invoke(WebauthnCredentialRenamedEvent $event): void
      {
          // From AuthKit's account page the actor is the owner (self-service only, impersonation refused). A host
          // tool that calls WebauthnManager::rename() for another user records its own actor here.
          $this->audit->record('passkey.renamed', $event->userType, $event->userId, [
              'profile' => $event->profile,
              'passkey' => $event->publicId,
              'from'    => $event->oldLabel,
              'to'      => $event->newLabel,
          ]);
      }
  }
  ```

  `WebauthnManager::rename()` is public API and takes any user: the event always names the passkey's owner, so a host
  admin or support tool that renames someone else's passkey adds its own actor to the audit entry.
  The listener runs after the write; when the rename happens inside a transaction the host opened, AuthKit's write is
  in a savepoint and the outer transaction can still roll back (as for the other passkey events). Keep the listener
  stateless (FrankenPHP worker mode without kernel reset). Client IP: the event carries none; read it from the current
  request in the listener if your audit log needs it.
- **Rename conflicts.** A rename whose passkey label keeps changing under it (another writer) gives up after 3
  attempts with a `TransientStorageFailure` (409 `CONFLICT_001` + `Retry-After` on AuthKit's pages). Hosts calling
  `WebauthnManager::rename()` themselves may see it under heavy contention: retry the request.
- **Passkey labels** (register and rename) now also lose line and paragraph separators (U+2028, U+2029), private-use
  and unassigned code points and invisible fillers (U+115F, U+1160, U+2800, U+3164, U+FFA0), replaced by a space.
- **`WebauthnStore::rename()`** (low-level, now marked `@internal`) only updates a row whose label is still `$record->label` and returns
  false otherwise. Code that called it with a record read earlier must read the row again first.

## To 2.4.1

From **2.4.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

From **2.4.0** (a patch: no schema change, nothing required).

- **Passkey erasure whatever the mode (audit ZBX-v-I2).** `MfaManager::forgetUser()` now deletes stored passkeys also
  when no profile enables them. Hosts that delete users without UserKit and without MFA can call the new public
  service `Nowo\AuthKitBundle\Webauthn\WebauthnErasure::forgetUser($user)`. Hosts that construct `MfaManager`
  themselves may pass the new optional last argument `$passkeyErasure`.
- **`allowed_origins` is compared lower-cased (audits ZBX-v-I3, ZCC-L1).** An entry with upper-case letters used to
  fail closed (no browser origin matched it); it now works. The lower-casing runs at runtime, so `%env()%` entries
  resolve as before. Check that you did not rely on such an entry being inert.
- **Non-default port (audit ZBX-v-I1, docs).** With an empty `allowed_origins` the expected origin is
  `https://<rp_id>` on the default port (with `rp_id: null`, the request host). A login page on another port must be
  listed in `allowed_origins`.

## To 2.4.0

From **2.3.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### Passkeys (opt-in)

Nothing changes until a profile sets `webauthn.mode: enabled`, but every host installs the new dependencies:
`web-auth/webauthn-lib` ^5.3.17, `symfony/serializer` and `symfony/uid`, and through webauthn-lib the PHP extension
`ext-openssl` plus `spomky-labs/cbor-php`, `web-auth/cose-lib`, `spomky-labs/pki-framework`,
`phpdocumentor/reflection-docblock`, `symfony/property-info`, `symfony/property-access` and `symfony/clock`. The new entity `WebauthnCredential` is mapped with the other AuthKit entities,
so `doctrine:migrations:diff` proposes the table `auth_kit_webauthn_credential` on every host with the mapping on
(create it, it stays empty; or use the scripts under `src/Resources/sql/webauthn/`).

To turn passkeys on:

1. Keep (or add) `symfony/lock` with `framework.lock`; the build refuses passkeys without it.
2. Create the table (migration or SQL script).
3. `webauthn: { mode: enabled, rp_id: login.example.com }`: set `rp_id` explicitly to the host of the login page; it
   cannot change later. The expected origin is then exactly `https://login.example.com` (never the `Host` header).
   Use a parent domain (`example.com`) only when you control every sub-domain, and then list the exact login origins in
   `allowed_origins`. Set `framework.trusted_hosts`.
4. `access_control`: `^/login/webauthn` public like `/login`.
5. With MFA: a passkey now counts as the second factor of a required user, and a passkey sign-in skips the second step
   (`mfa.first_factors.webauthn: exempt`; set `required` to keep asking). With `webauthn.mfa: false` a passkey sign-in
   is always followed by the user's second factor. Code that read `MfaManager::isEnabled()` as "has a second factor"
   should call `hasSecondFactor()`. `MfaManager::forgetUser()` now also deletes the user's passkeys; hosts that delete
   users without UserKit call it (or `WebauthnManager::forgetUser()`).

The design keys `rp_name` and `user_verification` of the 1.12 draft do not exist: the RP name follows WebAuthn Level 3
(the RP id), and user verification is always required. See [WEBAUTHN.md](WEBAUTHN.md#host-integration-beacon-and-others).

### Google mark in the dark theme

`social_login.provider_marks.<provider>.on_white` accepts `auto` next to `true` / `false`, and the built-in `google`
mark now defaults to `auto`: its white backdrop stays in the light theme and goes in the dark theme
(`data-theme="dark"`, or `prefers-color-scheme: dark` without `data-theme="light"`), where the full-colour "G" sits
directly on the dark button as in Google's own dark button. Nothing changes in a light theme. To keep the white
backdrop in both themes: `provider_marks: { google: { path: img/social/google.svg, package: nowo_auth_kit, on_white:
true } }`. A host template that renders the mark itself adds `nowo-auth-kit-providers__mark--on-white-auto` when
`mark.onWhiteAuto` is true (new `ProviderMark` property, default false).

## To 2.3.0

From **2.2.x**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Minor release: OpenID Connect providers (generic issuer with discovery, GitLab, Sign in with Apple, LinkedIn, Slack).
Nothing changes for the existing `google`, `github`, `microsoft` and custom OAuth2 credentials. The 2.2.1 note on host
social handlers still applies: clean-up and audit work belongs in a `finally` block inside `handle()`, not in
`handleFailure()`.

### Schema: three nullable columns

`auth_kit_social_credential` gains `issuer` (`VARCHAR(512) NULL`) and `provider_settings` (`JSON NULL`);
`auth_kit_social_account` gains `issuer` (`VARCHAR(512) NULL`, the issuer a link was made under). All are nullable and
need no data migration (existing links keep `NULL`, which is what the OAuth2 providers record), so the migration
Doctrine generates is safe on a non-empty table:

```bash
php bin/console doctrine:migrations:diff   # then review and migrate
```

```sql
-- MySQL / MariaDB
ALTER TABLE auth_kit_social_credential ADD issuer VARCHAR(512) DEFAULT NULL, ADD provider_settings JSON DEFAULT NULL;
ALTER TABLE auth_kit_social_account ADD issuer VARCHAR(512) DEFAULT NULL;
-- PostgreSQL
ALTER TABLE auth_kit_social_credential ADD issuer VARCHAR(512) DEFAULT NULL;
ALTER TABLE auth_kit_social_credential ADD provider_settings JSON DEFAULT NULL;
ALTER TABLE auth_kit_social_account ADD issuer VARCHAR(512) DEFAULT NULL;
```

Use your table prefix (`doctrine.table_prefix`) when you set one. Until the columns exist every page that reads the
credential table fails, as for any new mapped column: migrate before switching traffic.

### New providers

- **Generic OIDC**: any provider key plus `setIssuer('https://…')`.
- **`gitlab`** (gitlab.com, or `setIssuer()` with a self-hosted base URL), **`apple`** (client secret = the `.p8` PEM,
  `setProviderSettings(['team_id' => …, 'key_id' => …])`), **`linkedin`**, **`slack`**.

Setup steps, redirect URIs and every check: [OIDC-PROVIDERS.md](OIDC-PROVIDERS.md). New root configuration
`nowo_auth_kit.oidc` (defaults are fine): [CONFIGURATION.md](CONFIGURATION.md#openid-connect-providers-oidc).

Things to do for a provider you enable:

- register `https://<your host>/login/social/<provider>/check` at the provider;
- **Apple**: the callback is a POST; if a firewall, a WAF or `access_control` limits the check route to GET, allow POST
  there, and allow `/bundles/nowoauthkit/js/nowo-auth-kit-form-post.js` in a strict CSP (`'self'` already covers it).
  Run `assets:install` so the script is published;
- give the button a mark with `social_login.provider_marks.<provider>` if you want one (none ship for these providers);
- **trust**: every configured issuer can assert a verified e-mail and, with `link_existing_account` other than `deny`,
  sign in as the local account with that address, administrators included. Use `deny` on profiles that offer issuers
  you do not fully trust ([OIDC-PROVIDERS.md](OIDC-PROVIDERS.md#every-configured-issuer-is-trusted-with-e-mail-addresses));
- **issuer changes**: a link signs in only under the issuer it was made with. In your admin screen, call
  `SocialCredentialIssuerGuard::issuerChangeError()` before saving a new issuer and show its message (unlink first, or
  use a new provider key);
- **host social handlers** (`SocialCallbackStepInterface`) that keep their own `(provider, sub)` links: store
  `$context->socialProfile->issuer` with each link and refuse a sign-in under another issuer, as AuthKit's linker does
  ([SOCIAL-LOGIN.md](SOCIAL-LOGIN.md#callback-handlers); audit ZBP-v-I1);
- **per-IP limits** (start limit included) key on the client IP as Symfony resolves it: check
  `framework.trusted_proxies` behind a proxy or a load balancer (audit ZBP-v-I3).

### Behaviour that changes

- `GET|POST /login/social/{provider}/check`: the route now accepts POST. A POST for a provider that does not use
  `response_mode=form_post` is sent to the sign-in page without being processed.
- `GET /login/social/{provider}` is rate-limited per client IP: `social_login.start_rate_limit` (default **20**,
  0 disables) per `social_login.start_rate_window` (60 s), through the attempt limiter. Over it, the visitor is sent back
  to the sign-in page with `social.error.rate_limited`. Raise it (or set 0) if many users share one IP (NAT, kiosk).
- A failed OIDC discovery is remembered for `oidc.discovery_failure_ttl` seconds (30) and not retried meanwhile.
- `SocialLoginStateStore::issue()` takes an optional third argument (the nonce) and `consumePending()` returns
  `['code_verifier' => …, 'nonce' => …]`. Only code that calls the store directly (tests, host start controllers) sees
  the extra key.
- A credential keyed `gitlab`, `linkedin`, `slack` or `apple` **without** its own authorize / token / userinfo URLs now
  signs in through OpenID Connect (before 2.3.0 it could not sign in at all). One **with** those URLs keeps the OAuth2
  path unchanged.

## To 2.2.1

From **2.2.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No code change is needed for most hosts. A host social callback handler (`SocialCallbackStepInterface`) that throws a lock conflict now
gets a 409 with the conflict code instead of `handleFailure(…, SocialCallbackFailure::Failed)`. The sign-in it made is
still undone ([SOCIAL-LOGIN.md](SOCIAL-LOGIN.md)). If your `handleFailure()` does clean-up or audit work, move that
work into a `finally` block inside `handle()` so it also runs on a lock conflict.

## To 2.2.0

From **2.1.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### Password reset: one link stores one password (REQ-RUNTIME-003)

Nothing to change for the bundled pages and stores: a completion now claims the validated link token or code once,
so two concurrent completions of one link store one password.

- **Your own `PasswordResetTokenManagerInterface`** (or a decorator of AuthKit's) keeps the 2.1 race (two completions
  can both store a password, the last one wins) unless it also implements
  `Nowo\AuthKitBundle\PasswordReset\PasswordResetCredentialClaimInterface::claimForUser(object $user, string $credential): bool`:
  clear only the credential `$credential` proves, with a statement that only one transaction can win (a conditional
  `UPDATE` or `DELETE` whose affected-row count you check), and return false when nothing was cleared. A decorator
  should forward the call to the store it wraps.
- **Your own controller calling `PasswordResetCompleter::complete($user, $password)`**: pass the link token or code
  you validated as the third argument. Without it the credential is cleared as in 2.1, unguarded, and since 2.2 the
  call triggers a deprecation (`E_USER_DEPRECATED` from `trigger_deprecation('nowo-tech/auth-kit-bundle', '2.2', …)`,
  visible in the Symfony profiler and in PHPUnit runs that fail on deprecations). Catch
  `PasswordResetLinkUsedException` (another request used the credential first; nothing was written) and answer as for an
  invalid link.

## To 2.1.0

From **2.0.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### The bundle declares its own identity / access-key columns (REQ-SEC-006)

Nothing to do: the declaration is discovered by autoconfiguration, and a host that does not install
`nowo-tech/migrations-kit-bundle` is unaffected.

What you can **remove**: if you excused this bundle's identity columns by hand so the uniform collation gate would
pass, those entries are now redundant — a declared exact-key column is exempt from the uniform gate by construction,
and `nowo:migrations:check-key-collations` checks it under its real owner (`nowo-tech/auth-kit-bundle`) instead of
yours. Delete them:

```diff
 nowo_migrations_kit:
     collation_check:
         ignore_columns:
-            'auth_kit_mfa.user_type': 'AuthKit MFA owner'
-            'auth_kit_mfa.user_id': 'AuthKit MFA owner'
-            'auth_kit_social_account.provider_user_id': 'AuthKit social provider subject'
-            'auth_kit_social_account.user_id': 'AuthKit social link owner'
-            'auth_kit_social_account.user_identifier': 'AuthKit social link owner identifier'
-            'auth_kit_account_vault.user_identifier': 'AuthKit account switcher identifier'
-            'auth_kit_invitation.email': 'AuthKit invitation e-mail'
-            'auth_kit_invitation.invited_by': 'AuthKit inviter'
```

The password reset columns (`auth_kit_password_reset.{user_id,user_identifier,token_hash,code_hash}`) go the same
way, prefixed tables included: the declaration resolves `nowo_auth_kit.doctrine.table_prefix` itself. Keeping an entry
is not an error, but it outlives the declaration and goes on excusing a column nothing checks any more, so drop it.

If the gate now *fails* on one of these columns, it is telling the truth: that table predates the collation listeners,
or it was restored from a dump, and it still carries the host's folding default. Run the `ALTER` the check prints (they
are the ones in [To 1.47.0](#to-1470)).

With the entities unmapped, or with no profile on `password_reset.storage: table`, the columns of those tables are
reported as **skipped** with their setting, never as a failure: there is no table to check.

## To 2.0.0

From **1.56.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

```bash
composer require nowo-tech/auth-kit-bundle:^2.0 -W
```

2.0 contains only changes that 1.x announced through deprecation notices: an application that runs the last 1.x
minor without deprecation notices from this bundle, and has none of the uses listed under item 3 of
[Before you upgrade](#before-you-upgrade) (no notice can report them), upgrades without code changes. After 2.0.0 is
released, the 1.x
line receives security fixes for 6 months: fixes rated Moderate and above are backported to the last 1.x minor;
Low ones are fixed in 2.x only.

Unchanged in 2.0: password hashing, MFA secrets and recovery codes, PKCE, the attempt limiter, QR binding, magic links,
the invitation tokens, the routes, the tables and the conflict answers (409 + `CONFLICT_001` / `CONFLICT_002`). No
migration is needed.

### Before you upgrade

On the last 1.x (1.56), clear every deprecation of this bundle:

1. The 1.54 notices (see [To 1.54.0](#to-1540)):
   - > "nowo_auth_kit.doctrine.private_entity_manager" is not set: the AuthKit writes flush the application's entity
     > manager, including its pending changes. In 2.0 the private entity manager is the only mode (as with true; the
     > option goes). […]
   - > The service "app.lookup" (App\Security\Lookup) implements "Nowo\AuthKitBundle\User\UserLookupInterface",
     > deprecated since 1.46.0 and removed in 2.0: implement "Nowo\AuthKitBundle\User\UserDirectoryInterface" instead
     > (see UPGRADING.md "To 1.46.0").
   - > "Nowo\AuthKitBundle\Admin\AdminUserCreatingEvent::getUser()" is deprecated since 1.46.0 and removed in 2.0: use
     > userRef() […] (also `AdminUserCreatedEvent::getUser()`);
   - > "nowo_auth_kit.default_locale", "nowo_auth_kit.enabled_locales" and "nowo_auth_kit.locale_in_path" are
     > deprecated and removed in 2.0 […]
   - the 1.27 notices of `templates.*` and `css.*` (`The "layout" option at "…templates" is deprecated, …`) and the
     1.46 notice of `private_entity_manager: false`.
2. Every host-flush probe line, `Your pending changes to App\X were flushed by NowoAuthKitBundle; 2.0 no longer does.`
   Each class listed is a change of yours that 2.0 no longer stores for you (see
   [Private manager only](#private-manager-only)); the 1.54 [probe guide](#reading-the-host-flush-probe) explains how to
   read them. Then set `doctrine.private_entity_manager: true` on 1.x and run your suite once more.
3. What no notice can report:
   - reads of the deprecated public properties of the events and results. Search your listeners for `->user`,
     `->actor`, `->issuer`, `->target`, `->from`, `->to`, `->token`, `->request` and `->challenge` on AuthKit events,
     `InvitationAcceptance`, `AccountState` and `SwitchOutcome`, and for the 1.55 getters `user()`, `actor()`,
     `issuer()`, `target()`, `from()`, `to()`, `request()`, `token()` and `challenge()` (see
     [Event and result members](#event-and-result-members));
   - a `{% form_theme … '@NowoAuthKitBundle/form/mfa_otp_widget.html.twig' %}` line, and a page template of yours that
     extends `layout_template` with `@NowoAuthKitBundle/layout.html.twig` as its value (the 1.x layout, used while
     `web_ui.layout_template` is null) or uses the globals `nowo_auth_kit_button_class` /
     `nowo_auth_kit_secondary_button_class`. In 2.0 these fail when Twig renders the page ("Unable to find template",
     "Variable … does not exist"), not at build time (see [Templates, blocks and globals](#templates-blocks-and-globals)).
   - uses of the constants of the removed interfaces: `InvitationActionAuthorizerInterface::AUTHORIZER_TAG`, `INVITE`,
     `REINVITE`, `RESEND`, `REVOKE`, `RETURN_LINK` and `InvitationGrantInterface::TAG`, also inside classes that already
     implement the 1.46 ports (1.x documented them on the deprecated interfaces) and in attributes such as
     `#[AutoconfigureTag(InvitationGrantInterface::TAG)]`. 2.0 fails with `Class "…" not found` when the line runs (at
     build time in an attribute). Use the same constants on `InvitationActionPolicyInterface` and
     `InvitationGrantHandlerInterface` (same values; available since 1.56.0, so you can switch on 1.x);
   - code of yours that calls or constructs AuthKit's built-in port services directly: `DoctrineUserLookup`,
     `DoctrineUserWriter`, `PropertyAccessUserFactory`, `NullQrLoginStepUp`, `DeviceIntelligenceQrLoginStepUp`,
     `DefaultEnrolmentGate`, `IdentifierAccountPresenter`, `RoleInvitationsAccessChecker` (for example an adapter of
     yours that wraps `DoctrineUserLookup`). Their methods now follow the 1.46 ports: `AuthUserRef` in and out
     (`findByIdentifier()` returns an `AuthUserRef`, `DoctrineUserWriter::add()` takes one), `isUnlocked()` instead of
     `assertUnlocked()`, `allowsEnrolment(EnrolmentCandidate)`, `presentationFor(AuthUserRef)`, `allowsManage()`; the
     Device Intelligence step-up takes a `QrLoginStepUpPolicyInterface` and the `RequestStack`. Decorate or alias the
     ports instead of composing the built-ins.

### Default flips

| Option | 1.x | 2.0 |
|---|---|---|
| `doctrine.private_entity_manager` | unset or `false`: the AuthKit writes flush your entity manager; `true`: AuthKit's private manager | AuthKit's private manager always (with the AuthKit entities mapped). `false` fails the build; `true` is accepted until 3.0 and logs "The "nowo_auth_kit.doctrine.private_entity_manager" option has no effect since 2.0 (the private entity manager is the only mode); remove it." |

There is no option to keep the 1.x behaviour: flush your own pending changes yourself. Hosts without the AuthKit
entities mapped (`doctrine.register_mapping: false`, or `auto` without social login, QR login, invitations, MFA or the
account switcher) write no AuthKit rows: nothing changes for them, and `private_entity_manager: true` is still refused
there, as in 1.x. The container parameter `nowo_auth_kit.doctrine.private_entity_manager` stays: it is `true` whenever
the private manager is registered.

### Private manager only

The private entity manager of [To 1.46.0](#private-entity-manager-doctrineprivate_entity_manager) is now unconditional:

- AuthKit writes its rows (QR login challenges, invitations, MFA credentials, social accounts and credentials, the
  account switcher) through its own entity manager, on the connection and mapping of your default manager. The tables
  do not move, and a transaction you open around an AuthKit call includes AuthKit's statements (savepoints).
- **Your pending changes are no longer flushed by AuthKit.** A change of yours that was only stored because an AuthKit
  write flushed your manager is no longer stored: typically in a listener of an AuthKit event, a port of yours, or code
  that changes an entity and then calls an AuthKit service. Flush it yourself. The 1.54 probe lines are the list of
  such changes in your application.
- The reverse, which the probe cannot see: **an AuthKit row you load through an AuthKit repository service
  (`InvitationRepository`, `QrLoginChallengeRepository`: they are on the private manager), change, and then flush
  your own manager is not saved**; the private manager drops it at the next request, message or command boundary with
  a warning. Change AuthKit rows through the AuthKit services. `SocialLoginAccountRepository` and
  `SocialLoginCredentialRepository` as your manager returns them (`$em->getRepository(SocialLoginAccount::class)`)
  stay on your manager, as in 1.46: editing social accounts or credentials through them and flushing your manager
  still works.
- AuthKit rows you load through your own manager (a repository of yours, a route argument, `getReference()`) are
  loaded again by id in AuthKit's manager when you hand them to `InvitationManager`, `InvitationAcceptor` or
  `QrLoginChallengeManager` (1.55.0, [AuthKit rows loaded through your own entity manager](#authkit-rows-loaded-through-your-own-entity-manager)).
  Your instance is not changed; read the result from the returned outcome.
- To point an entity of yours at an AuthKit row (a `ManyToOne` to `Invitation`), use
  `$yourManager->getReference(Invitation::class, $id)` and flush your manager: the foreign key is written, and AuthKit's
  manager is not involved.
- **Residual: the account writes still go through your manager.** Your user entities are yours: the Doctrine user
  store (`User\Doctrine\DoctrineUserWriter`, the default of `UserStoreInterface`), the password-reset credential kept
  on the user entity, and your `InvitationGrantHandlerInterface` services inside the acceptance transaction write
  through your entity manager, and the Doctrine store flushes it. A registration, an invitation acceptance, a social
  sign-up, an administrator creation or a password reset can therefore still write pending changes of yours. Do not
  rely on it; alias `UserStoreInterface` to your own store to control it.
- A port of yours whose correctness depended on AuthKit's flush (a custom `UserStoreInterface` that only changed a
  managed entity, a grant handler that changed an entity without flushing) must now commit its own change.
- FrankenPHP worker mode with kernel reset off: the private manager is dropped at every request, message, command and
  `kernel.reset` boundary, as in 1.x with the option on.

### Port interfaces

The 1.45 host port interfaces deprecated in 1.46.0 are removed. Implement their twin (the [1.46 table](#framework-free-host-ports-optional-until-20)):

| Removed interface | Implement instead | Wiring |
|---|---|---|
| `User\UserLookupInterface` | `User\UserDirectoryInterface` | alias the port to your service |
| `User\UserFactoryInterface` | `User\UserCreatorInterface` | alias |
| `User\UserWriterInterface` | `User\UserStoreInterface` | alias |
| `QrLogin\QrLoginStepUpInterface` | `QrLogin\QrLoginStepUpPolicyInterface` (return `false` instead of throwing) | alias |
| `Mfa\MfaPolicyInterface` | `Mfa\MfaRequirementPolicyInterface` | same `mfa.policy` service id |
| `MultiAccount\EnrolmentGateInterface` | `MultiAccount\EnrolmentPolicyInterface` | same `multi_account.enrolment_gate` |
| `MultiAccount\AccountPresenterInterface` | `MultiAccount\AccountPresentationProviderInterface` | same `multi_account.presenter` |
| `Invitation\Security\InvitationsAccessCheckerInterface` | `Invitation\Security\InvitationsAccessPolicyInterface` | same `invitations.security.access_checker` |
| `Invitation\Security\InvitationActionAuthorizerInterface` | `Invitation\Security\InvitationActionPolicyInterface` | autoconfigured, same tag |
| `Invitation\InvitationGrantInterface` | `Invitation\InvitationGrantHandlerInterface` | autoconfigured, same tag |
| `SocialLogin\SocialCallbackHandlerInterface` | `SocialLogin\SocialCallbackStepInterface` | autoconfigured, same tag |

- The constants moved with them: `InvitationActionAuthorizerInterface::AUTHORIZER_TAG`, `INVITE`, `REINVITE`,
  `RESEND`, `REVOKE` and `RETURN_LINK` are now on `InvitationActionPolicyInterface`;
  `InvitationGrantInterface::TAG` is `InvitationGrantHandlerInterface::TAG` (same values).
- The ports are now always defined: `UserDirectoryInterface`, `UserCreatorInterface`, `UserStoreInterface` and
  `QrLoginStepUpPolicyInterface` point at the built-ins (`DoctrineUserLookup`, `PropertyAccessUserFactory`,
  `DoctrineUserWriter`, `NullQrLoginStepUp`), which implement them; the per-profile defaults
  (`DefaultEnrolmentGate`, `IdentifierAccountPresenter`, `RoleInvitationsAccessChecker`) implement theirs. Same
  behaviour as in 1.x.
- **Decorators move to the port's id.** A decorator of a removed id (`#[AsDecorator(QrLoginStepUpInterface::class)]`,
  `decorates: Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface`, `on_invalid: ignore`
  included) has no replacement under the old id: decorate the port instead (`QrLoginStepUpPolicyInterface`,
  `UserDirectoryInterface`, …). For the tagged ports, register another tagged service (every authorizer must allow).
  AuthKit's own Device Intelligence step-up now decorates `QrLoginStepUpPolicyInterface`.
- **A removed path fails the build.** A service, alias or `#[AsAlias]` under a removed id, a decorator of one, and a
  class of yours that still implements a removed interface (Symfony would otherwise drop it silently, and a host rule
  on QR approval, invitation actions or the MFA requirement would disappear) fail the container build with "the
  service "…" uses …, which was deprecated in 1.46.0 and removed in 2.0. Implement … instead". A configured or tagged
  port service that does not implement its port fails the build, as in 1.46.

### Event and result members

The members deprecated in 1.46.0 ([Event accessors](#event-accessors)) are no longer public. The constructors are
unchanged (you can still dispatch `new CredentialsChangedEvent($user, …)`):

| Removed (public property, and its 1.55 getter) | Use |
|---|---|
| `AdminUserCreatingEvent::getUser()`, `AdminUserCreatedEvent::getUser()` | `userRef()`, `userRef()->user()` for the object |
| `AdminMagicLinkIssuedEvent::$issuer` / `issuer()`, `::$target` / `target()` | `issuerRef()`, `targetRef()` |
| `InvitationEvent::$actor` / `actor()` (Created, Resent, Revoked, Accepted) | `actorRef()` |
| `InvitationAcceptedEvent::$user` / `user()`, `InvitationAcceptingEvent::$user` / `user()` | `userRef()` |
| `MfaEvent::$user` / `user()` (all MFA events) | `userRef()` |
| `MfaResetByAdminEvent::$actor` / `actor()` | `actorRef()` |
| `MfaLoginCompletedEvent::$token` / `token()`, `::$request` / `request()` | `userRef()` + `firewall()`, `requestContext()` |
| `AccountEvent::$request` / `request()` (Added, Removed, Switched, Switching) | `requestContext()` |
| `AccountAddedEvent::$user`, `AccountRemovedEvent::$user` (and `user()`) | `userRef()` |
| `AccountSwitchedEvent::$from` / `$to`, `AccountSwitchingEvent::$from` / `$to` (and `from()`, `to()`) | `fromRef()`, `toRef()` |
| `QrLogin{ChallengeCreated,Approved,Denied,Completed}Event::$challenge` / `challenge()` | `challengeRef()`, `challengeId()` |
| `QrLogin{Approved,Completed}Event::$user` / `user()` | `userRef()` |
| `CredentialsChangedEvent::$user` / `user()` (read side) | `userRef()` |
| `AccountState::$user`, `SwitchOutcome::$user`, `InvitationAcceptance::$user` (and `user()`) | `userRef()` |

The other properties stay public, with their 1.55 getters. The read-write semantics stay:
`AccountSwitchingEvent::refuse()`, `InvitationAcceptingEvent::refuse()`, and fields you set on
`AdminUserCreatingEvent::userRef()->user()` (the object AuthKit persists).

### Configuration

| Removed key | Deprecated since | Use |
|---|---|---|
| `default_locale` (root) | 1.7 (alone: 1.54) | `locale.default` |
| `enabled_locales` (root) | 1.7 (alone: 1.54) | `locale.enabled` |
| `locale_in_path` (root) | 1.7 (alone: 1.54) | `locale.in_path` (`never` / `always` / `both`) |
| `templates.layout` (per profile, and the root shorthand) | 1.27 | `web_ui.layout_template` (null = `nowo_ui_kit.layouts.guest`) |
| `templates.<page>` | 1.27 | `web_ui.templates.<page>` |
| `templates.form_theme` | 1.27 | `web_ui.form_themes` |
| `css.button_class` | 1.27 | `nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary` |
| `css.secondary_button_class` | 1.27 | `nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_secondary` |
| `doctrine.private_entity_manager: false` | 1.46 | remove it (see [Default flips](#default-flips)) |

Each removed key fails the build with a message that names its replacement. The container parameters
`nowo_auth_kit.default_locale`, `nowo_auth_kit.enabled_locales` and `nowo_auth_kit.locale_in_path` stay (they come
from `locale.*`), and so do the read-only `templates.*` mirror (`nowo_auth_kit.templates`,
`ProfileSettings::$templates`) and `nowo_auth_kit.css` (the 1.x default class names; no longer configurable).
2.0 no longer bridges `css.*` to the UiKit theme: set the UiKit slots yourself.

### Templates, blocks and globals

| Removed | Deprecated since | Use |
|---|---|---|
| `@NowoAuthKitBundle/layout.html.twig` (and its blocks `auth_panel_heading`, `auth_brand`, `title`) | 1.27 | `nowo_ui_kit.layouts.guest`; `page.title`, `web_ui.brand`, `nowo_ui_document_title` |
| Twig globals `nowo_auth_kit_button_class`, `nowo_auth_kit_secondary_button_class` | 1.27 | `nowo_ui_class('btn_primary' / 'btn_secondary', 'NowoAuthKitBundle')` |
| `@NowoAuthKitBundle/form/mfa_otp_widget.html.twig` (block `nowo_auth_kit_mfa_otp_widget`) | 1.52 | nothing: delete the `form_theme` line (the field renders with OtpInputBundle's widget) |

AuthKit's own pages never used them. A page template of yours written against 1.x that extends `layout_template`
still receives `web_ui.layout_template` when you set it; without it, it receives the removed layout's name
(`ProfileSettings::legacyLayoutTemplate()`) and Twig fails with "Unable to find template". Extend your own layout or
`@NowoUiKit/page/auth.html.twig` instead.

### Fixes that come with 2.0

- Conflict answers now carry the `X-Conflict-Code` header (`CONFLICT_001` / `CONFLICT_002`) on pages too.
- A resend, re-invite or revocation that loses a race with an acceptance writes nothing and answers `NOT_OPEN`
  (it used to re-issue a token or set a revocation on the accepted invitation). `revokeOpenBy()` counts only the
  invitations it actually revoked.

### Other removals

- `Doctrine\IdentityCollationListener::postGenerateSchemaTable()` and
  `Doctrine\PasswordResetCollationListener::postGenerateSchemaTable()` (no-ops since 1.54.1): the collation and the
  foreign keys are applied on `postGenerateSchema`, as since 1.54.1.
- The host-flush probe of 1.54 (`Doctrine\HostFlushReporter`, internal) and its notices.
  `AuthKitTransactionRunner` and `MfaStore` keep their trailing `$hostFlush` constructor argument, ignored (removed in
  3.0).

### 1.x support

1.x receives security fixes for 6 months after 2.0.0: fixes rated Moderate and above are backported to the last 1.x
minor (1.56); Low ones are fixed in 2.x only. See [.github/SECURITY.md](../.github/SECURITY.md).

## To 1.56.0

From **1.55.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

```bash
composer update nowo-tech/auth-kit-bundle
```

### Preparing for 2.0: the tag and action constants

Read the invitation hook constants on the ports: `InvitationActionPolicyInterface::INVITE` (`REINVITE`, `RESEND`,
`REVOKE`, `RETURN_LINK`, `AUTHORIZER_TAG`) instead of `InvitationActionAuthorizerInterface::…`, and
`InvitationGrantHandlerInterface::TAG` instead of `InvitationGrantInterface::TAG`, including in
`#[AutoconfigureTag(…)]`. Same values. 2.0 removes the deprecated interfaces and their constants; no runtime notice can
report a constant read, so search your code for them.

## To 1.55.1

From **1.55.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.55.0

From **1.54.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

From **1.54.x**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### AuthKit rows loaded through your own entity manager

With `doctrine.private_entity_manager: true`, AuthKit writes its rows through its private manager. An invitation or a
QR login challenge you load through your own manager (your repository, a route argument, `getReference()`) and pass to
`InvitationManager::resend()` / `revoke()`, `InvitationAcceptor::attempt()` / `accept()` / `acceptExisting()` or
`QrLoginChallengeManager::approve()` / `deny()` / `consume()` / `isExpiredOrInvalid()` is now loaded again by id in
AuthKit's manager, and that instance is read and written. Before, it was inserted a second time and the call failed on
the unique key. What this means for you:

- Your instance is not changed: read the result from the returned outcome or snapshot, or load the row again. Your
  manager is never flushed, cleared or detached by these calls, and a later flush of it does not write your stale copy
  back (it has no changes of its own).
- A row deleted since you loaded it throws `Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotFoundException`; nothing is
  written.
- The low-level `InvitationRepository::save()`, `QrLoginChallengeRepository::save()` and `MfaStore::save()` refuse such
  an instance with `Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotManagedException`. Change rows through the AuthKit
  services, or call `adopt()` on the repository service first and change the instance it returns.

Without the private manager (one shared entity manager), the same re-load applies to an instance that manager no
longer holds (detached, or loaded before a `clear()`). Resend, revoke and the QR transitions now succeed with such an
instance where they used to fail with a duplicate INSERT. An acceptance marks the copy the manager holds for that id,
so a managed copy your code keeps shows the invitation as accepted after the call. Instances the manager holds behave
exactly as before.

This is the last step before you set `private_entity_manager: true` if you hand AuthKit rows loaded through your own
manager to these services.

### Preparing for 2.0: read event properties through their getters

Every public property of the AuthKit events and of `InvitationAcceptance`, `AccountState` and `SwitchOutcome` now has
a getter of the same name (`$existingAccount` → `isExistingAccount()`). PHP cannot report reading a property, so
search your listeners for property reads on AuthKit events and results and switch them before 2.0:

| Before | Now |
|---|---|
| `$event->issuer`, `$event->target` | `$event->issuerRef()`, `$event->targetRef()` |
| `$event->actor` | `$event->actorRef()` |
| `$event->user`, `$outcome->user` | `$event->userRef()`, `$outcome->userRef()` |
| `$event->request` | `$event->requestContext()` |
| `$event->from`, `$event->to` | `$event->fromRef()`, `$event->toRef()` |
| `$event->token` | `$event->userRef()` and `$event->firewall()` |
| `$event->challenge` | `$event->challengeRef()`, `$event->challengeId()` |
| `$event->delivery`, `$event->invitation`, `$event->reason`, `$event->remaining`, `$event->count`, … | `$event->delivery()`, `$event->invitation()`, `$event->reason()`, `$event->remaining()`, `$event->count()`, … |

The framework- and entity-typed properties (`$user`, `$actor`, `$issuer`, `$target`, `$from`, `$to`, `$request`,
`$token`, `$challenge`) are deprecated since 1.46.0 and 2.0 removes them from the public surface: their new getters
(`user()`, `actor()`, …, `challenge()`) are deprecated too and go with them, so move to the `*Ref()` methods
(`userRef()->user()` still returns your user object, for example to load your own entity). The other properties stay
public in 2.0; their getters are there so a listener can use one style throughout.

## To 1.54.1

From **1.54.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.54.0

From **1.53.x**:

```bash
composer update nowo-tech/auth-kit-bundle nowo-tech/transaction-doctrine
```

1.54.0 is the last 1.x minor before 2.0. It changes no behaviour. It only adds runtime deprecation notices for
everything 2.0 removes or flips, so your deprecation log lists what to change before you upgrade. It requires
`nowo-tech/transaction-doctrine` ^1.9.

### The private entity manager notice

With the AuthKit entities mapped (`doctrine.register_mapping` true, or `auto` with social or QR login) and
`doctrine.private_entity_manager` not set, building the container now logs:

> "nowo_auth_kit.doctrine.private_entity_manager" is not set: the AuthKit writes flush the application's entity
> manager, including its pending changes. In 2.0 the private entity manager is the only mode (as with true; the option
> goes). Opt in now with "nowo_auth_kit: { doctrine: { private_entity_manager: true } }", after checking the "Your
> pending changes to ... were flushed by NowoAuthKitBundle" deprecations (see UPGRADING.md "To 1.54.0").

What it means: today an AuthKit write (a QR login challenge, an MFA credential, an invitation, a social account, the
runner of registration, password reset and invitation acceptance) flushes your default entity manager first and again
inside its transaction, so anything your code had left pending there is written too. In 2.0 AuthKit writes its own
rows through its private manager and never flushes your pending changes. `false` keeps its 1.46 notice.

To opt in now (the tables do not move; see [To 1.46.0](#to-1460)):

```yaml
nowo_auth_kit:
    doctrine:
        private_entity_manager: true
```

### Reading the host-flush probe

While the option is unset or false, the outermost AuthKit unit of work calls the host-flush probe of
nowo-tech/transaction-doctrine right before each flush of your manager. Every entity class outside
`Nowo\AuthKitBundle\Entity` with pending changes there (a scheduled insert or delete, a changed field, association or
collection) triggers one deprecation per request, message or command:

> Your pending changes to App\Entity\Order were flushed by NowoAuthKitBundle; 2.0 no longer does.

Read the collected messages as your migration list:

1. Run your usual traffic and test suite on 1.54 with deprecations logged (the Symfony profiler, the `deprecation`
   log channel, or PHPUnit's deprecation report).
2. For each class listed, find the code that changes it and relies on AuthKit's write to store it (an event listener
   of an AuthKit event, a user port, a grant handler, code that calls an AuthKit service in the middle of its own
   changes). Make that code flush its own changes, or run it in your own transaction.
3. When no message is left, set `private_entity_manager: true`. The probe stays silent in that mode.

How to read it:

- It is read-only. It flushes, clears, closes or resets nothing, and it computes no change set.
- Only the outermost AuthKit unit reports. Inside it, the changes to your user entity are AuthKit's own (the account
  it creates, the password it changes). The account writes still flush your manager in 2.0: your user entities stay
  on your manager, and AuthKit's Doctrine user writer flushes it to store them. A class reported by registration,
  password reset, invitation acceptance or social sign-up may therefore still be written by 2.0 when that unit also
  writes an account. Do not rely on it: fix the code anyway.
- The probe is NowoTransactionDoctrineBundle's shared `nowo_transaction_doctrine.host_flush_probe` when you register
  that bundle, otherwise AuthKit's own. Both are reset at every request, message and command boundary (FrankenPHP
  worker mode with kernel reset off included).
- Without the AuthKit entities mapped (`register_mapping: false`, or `auto` without social or QR login),
  `private_entity_manager: true` is refused. Neither the notice nor the probe runs then.

### Other notices

Also new in 1.54.0, each removed in 2.0 (see [To 1.46.0](#to-1460) for the replacements):

- A host service on a deprecated 1.45 port interface (`UserLookupInterface`, `UserFactoryInterface`,
  `UserWriterInterface`, `QrLoginStepUpInterface`, `MfaPolicyInterface`, `EnrolmentGateInterface`,
  `AccountPresenterInterface`, `InvitationsAccessCheckerInterface`, `InvitationGrantInterface`,
  `InvitationActionAuthorizerInterface`, `SocialCallbackHandlerInterface`) logs, once per container build and in
  every environment: `The service "app.lookup" (App\Security\Lookup) implements "Nowo\AuthKitBundle\User\UserLookupInterface",
  deprecated since 1.46.0 and removed in 2.0: implement "Nowo\AuthKitBundle\User\UserDirectoryInterface" instead`.
  A class that implements both interfaces is reported too: 2.0 deletes the old one. The service is still asked
  exactly as before.
- `AdminUserCreatingEvent::getUser()` and `AdminUserCreatedEvent::getUser()` log a notice when called. Use
  `userRef()`, and `userRef()->user()` for the object.
- `default_locale`, `enabled_locales` and `locale_in_path` used on their own log a notice. Use `locale.default`,
  `locale.enabled` and `locale.in_path`.

Not reported at runtime (PHP cannot report reading a public property without changing it): the deprecated public
properties of the events and results listed in [To 1.46.0](#to-1460) (for example `AccountAddedEvent::$user`,
`MfaLoginCompletedEvent::$token`, `InvitationAcceptance::$user`). Use their `*Ref()` methods; static analysis with
deprecation rules reports the old properties. The inert `@NowoAuthKitBundle/form/mfa_otp_widget.html.twig` theme
(1.52) cannot report either, because Symfony never renders a form theme as a template. Delete any
`{% form_theme ... 'mfa_otp_widget.html.twig' %}` line: 2.0 removes the file.

### Preparing for 2.0

2.0 contains only the deprecations announced in 1.x: the private entity manager as the only mode, the 1.45 port
interfaces and the deprecated event members (1.46), the deprecated configuration keys and the inert MFA OTP widget
theme. Nothing is removed without a notice in 1.54. Clear your deprecation log on 1.54 and the upgrade is a
`composer update`.

1.x keeps security support for 6 months after 2.0.0 is released: fixes for Moderate and higher advisories are
backported to the last 1.x minor.

## To 1.53.0

From **1.52.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

From **1.52.x** (target 1.53.0):

```bash
composer update nowo-tech/auth-kit-bundle nowo-tech/transaction-doctrine
```

**Lock conflicts answer 409 instead of 503.** A deadlock, a lock wait timeout, a serialization failure or an AuthKit
named lock not obtained on an AuthKit page now answers `409 Conflict` (it was `503 Service Unavailable`).

- **Pages:** still the application's error page, with the new status. A template for 503 only (`error503.html.twig`)
  no longer applies; add `error409.html.twig` if you want a dedicated page.
- **JSON clients:** keep `{"ok": false, "error": "unavailable"}` and gain `"code": "CONFLICT_001"` (retry after
  `Retry-After`) or `"code": "CONFLICT_002"` (part of the request was saved: reload, do not repeat; no `Retry-After`).
- **To keep the 503:**

  ```yaml
  nowo_auth_kit:
      conflict_response:
          status: 503
  ```

  With NowoTransactionDoctrineBundle registered and AuthKit's `status` and `retry_after` unset, AuthKit follows
  `nowo_transaction_doctrine.conflict_response` (409 by default there too).

**Unique-key violations are no longer a 409.** Up to 1.52 a unique-key violation (and an optimistic lock) reaching an
AuthKit page answered 409 `{"ok": false, "error": "conflict"}`. AuthKit's own "only one" races never reach the page
(they are caught where they happen), so this mapping only hid unexpected errors. They now reach the application's
error handling as real errors. A client that matched `"error": "conflict"` should treat any non-2xx answer as a failure.

**Commit detection.** To tell `CONFLICT_002` apart, AuthKit counts the commits of the request with
transaction-doctrine's commit tracking DBAL middleware. It reuses NowoTransactionDoctrineBundle's ledger when the host
has it. Otherwise it registers the middleware on the application's connections (a few regular-expression checks per
statement). Set `conflict_response.commit_detection: false` to opt out: a conflict after
a commit then answers `CONFLICT_001`, unless a host write unit says otherwise.

See [CONCURRENCY](CONCURRENCY.md).

## To 1.52.0

From **1.51.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.52.0

From **1.51.x**:

```bash
composer update nowo-tech/auth-kit-bundle nowo-tech/otp-input-bundle
php bin/console assets:install
```

- **Hosts that use `nowo-tech/otp-input-bundle` must use ^1.9** (`composer.json` declares a conflict with `<1.9`).
  OtpInputBundle 1.9 renders the no-JavaScript text input and reveals the boxes itself, so AuthKit 1.52 drops its own
  OTP form theme. Hosts without the package are not affected.
- **Remove custom overrides of AuthKit's former OTP theme.** `@NowoAuthKitBundle/form/mfa_otp_widget.html.twig` (block
  `nowo_auth_kit_mfa_otp_widget`) is **deprecated and inert** since 1.52 and will be removed in 2.0. A
  `{% form_theme form.code '@NowoAuthKitBundle/form/mfa_otp_widget.html.twig' %}` line in your overrides of
  `mfa/challenge.html.twig`, `mfa/setup.html.twig` or `mfa/_code_form.html.twig` (as the 1.51 docs said) still renders
  correctly — the block is no longer a block prefix of the field, so it never applies — but delete it now: in 2.0 the
  missing template would be a Twig error. Also delete any template of yours that overrides or wraps
  `nowo_auth_kit_mfa_otp_widget` or `nowo_otp_input_widget` to add AuthKit's former wrapper: with OtpInputBundle 1.9
  such a wrapper renders the boxes twice (duplicate ids). To restyle the field, override OtpInputBundle's
  `nowo_otp_input_widget` or style `.nowo-auth-kit-mfa-otp` (the class of the `<nowo-otp-input>` host element).
- **Selectors in your tests or CSS:** `[data-testid="auth-kit-mfa-otp"]`, `.nowo-auth-kit-mfa-otp--invalid` and
  `data-nowo-auth-kit-otp-ready` are gone. Use `nowo-otp-input.nowo-auth-kit-mfa-otp` (or `[data-nowo-otp-init="1"]`
  once bound, OtpInputBundle's stable hook), `input[name$="[code]"]` / `.nowo-auth-kit-mfa-otp__text` for the named
  input (a visible text input without JavaScript, `type="hidden"` once the boxes are bound) and
  `[data-nowo-otp-digit="0"]` for the first box. The autosubmit marker `data-nowo-auth-kit-otp-autosubmit="1"` is now
  on the named input. The boxes are named by OtpInputBundle (`otp.digit_label`, domain `NowoOtpInputBundle`); the
  AuthKit key `mfa.field.code_digit` is removed.
- **`MfaCodeFormType::OTP_BLOCK_PREFIX` changed value**, from `nowo_auth_kit_mfa_otp` to `nowo_otp_input`
  (OtpInputBundle's own prefix). Code that builds a block name from it (`OTP_BLOCK_PREFIX ~ '_widget'`) now targets
  OtpInputBundle's global `nowo_otp_input_widget`: overriding that block changes every OtpType field of the app, not
  only the MFA code. Use the class `nowo-auth-kit-mfa-otp` to style the MFA code instead.
- The named code input has `maxlength` equal to the code length (OtpInputBundle's default is twice the length, for
  separators that AuthKit does not strip).
- Nothing changes on the server: same submitted value, same validation, attempt limiter, CSRF and constant-time check.

## To 1.51.1

From **1.51.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### To 1.51.1

From **1.51.0**: `composer update nowo-tech/auth-kit-bundle` and `php bin/console assets:install` (the fix is in
`js/nowo-auth-kit-otp.js` and `css/nowo-auth-kit.css`). Nothing to change in your configuration or templates. If your
own stylesheet forces `display` on the OtpInput classes (`.nowo-otp-input__gap`, `[data-nowo-otp-render-target]`), it no
longer matters: AuthKit shows the boxes by removing their `hidden` attribute once `otp-input.js` has bound them.

## To 1.51.0

From **1.50.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### To 1.51.0 (MFA code on OTP boxes)

From **1.50.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No schema change and nothing to do unless you use `nowo-tech/otp-input-bundle`.

- **`otp_input.enabled: true` now also covers the two-step verification code.** The new key `otp_input.mfa_code`
  defaults to `true`, so a profile that already turned the OTP input on for the password-reset code gets OTP boxes on
  the MFA challenge, setup and account forms too. To keep the MFA code a text field:

  ```yaml
  nowo_auth_kit:
      otp_input:
          enabled: true
          password_reset_code: true
          mfa_code: false
  ```

- **Turning it on** (needs the package and its public assets):

  ```bash
  composer require nowo-tech/otp-input-bundle
  php bin/console assets:install
  ```

  ```yaml
  nowo_auth_kit:
      otp_input:
          enabled: true
          mfa_code: true              # default
          mfa_code_autosubmit: false  # true: submit once every box is filled
  ```

- No JavaScript needed: until `otp-input.js` has bound the boxes, the field is one visible text input (it stays if the
  script is blocked, missing or fails), so nobody is locked out. (1.51.1: `js/nowo-auth-kit-otp.js` swaps the text
  input for the boxes; no stylesheet is involved.)
- With the package installed but `NowoOtpInputBundle` not registered, AuthKit now skips the OtpInput script instead of
  failing on the missing `nowo_otp_input` asset package.
- The account page code forms no longer carry `autofocus`, and `mfa.field.code_help` now takes `%digits%` (override
  it in your translations if you changed the text).
- **Overridden templates.** If you override `mfa/challenge.html.twig`, `mfa/setup.html.twig` or
  `mfa/_code_form.html.twig`, add `{% form_theme form.code '@NowoAuthKitBundle/form/mfa_otp_widget.html.twig' %}`
  before the code row to get the single-name group and the autosubmit marker; without it the field renders with the
  OtpInput bundle's own widget (still working). If you override `mfa/account.html.twig`, load
  `{{ include('@NowoAuthKitBundle/_otp_input_assets.html.twig') }}` in `nowo_ui_scripts`.
- Your own `MfaCodeFormType` forms: pass the profile's config as the `otp_input` option to get the boxes; without the
  option the field stays text.
- E2E tests that fill `input[name="mfa_code_form[code]"]` must type into the first box
  (`[data-nowo-otp-digit="0"]`) instead when JavaScript runs: the named text input is then hidden by the CSS.

## To 1.50.0

From **1.49.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Target: 1.50.0. From **1.49.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No configuration and no schema change. Two new dependencies come with it: `nowo-tech/transaction-contract` ^1.0 and
`nowo-tech/transaction-doctrine` ^1.1 (they allow `doctrine/dbal` ^3.8.2 || ^4).

What changes for you:

- **A failed AuthKit write no longer resets your entity manager in the middle of the request.** Up to 1.49 a manager
  closed by a failed AuthKit flush was reset at once, which detached every entity you had loaded (the signed-in user,
  your aggregates): your later changes in that request were silently lost. Now the manager stays closed for the rest of
  that request, so your next flush fails loudly, and AuthKit replaces it at the next request, message or command
  boundary (FrankenPHP worker mode with the kernel reset off included). If you catch an AuthKit failure and keep writing
  in the same request, check `isOpen()` first.
- **A failed invitation acceptance or social sign-in no longer clears your manager.** Only the rows the failed unit
  touched are detached (the invitation, the new account, what your grants changed); reload those if you still need them.
  Your own pending changes stay pending, except in these cases, where the row is detached and its pending change lost:
  - an account write of the unit flushed it with the unit (the Doctrine user writer flushes your whole manager, as in
    1.49): it was rolled back;
  - with the private manager or your own user ports: a lazy reference (proxy) of yours that the failed unit only
    loaded (for example the account looked up by its identifier) counts as touched;
  - with the private manager or your own user ports: a row of yours that points (to-one) at a detached row is detached
    too (cascade), even with pending changes of its own.

  **Recommended:** flush your manager before calling an AuthKit service that may create, link or change an account, or
  keep no pending changes on entities related to the account (memberships, profiles). The code fix for the last two
  cases is planned for 1.50.x (audit QGD-L1).
- **Your pending changes are flushed on their own before an AuthKit unit of work** whenever AuthKit writes through your
  manager (`doctrine.private_entity_manager` unset or false; with the private manager only for account writes of the
  Doctrine user writer, as before): an AuthKit failure never rolls them back. To keep your changes and AuthKit's
  together, open your own transaction around the call: AuthKit's unit then nests in it.
- **Storage failures on AuthKit's pages are 503 / 409, not 500.** A deadlock, lock wait timeout or serialization
  failure answers `503` with `Retry-After`; a unique key taken meanwhile `409`. Your error pages render them
  (`ServiceUnavailableHttpException` / `ConflictHttpException`). Your own controllers that call AuthKit services receive
  `Nowo\TransactionContract\TransientStorageFailure` and map it themselves (or use DoctrineDeadlockRetryBundle's
  `http_mapping`). The registration page, the social login callback, the QR login completion and
  `InvitationAcceptor::attempt()` now let a transient failure through instead of answering a refusal (`not_accepted`,
  `social.error.failed`); the invitation stays usable.
- **`InvitationAcceptor::attempt()` throws `Nowo\TransactionContract\TransientStorageFailure`** (a `RuntimeException`,
  the DBAL exception as previous) where 1.49 let a raw `Doctrine\DBAL\Exception\DeadlockException` /
  `LockWaitTimeoutException` through. If you call it from your own code, catch `TransientStorageFailure`, not
  `Doctrine\DBAL\Exception\RetryableException`. It is also thrown when the account lock is not obtained within **5
  seconds** (another creation of the same identifier holds it).
- **Account creation takes a database named lock** of the identifier (`GET_LOCK()` on MySQL / MariaDB, an advisory lock
  on PostgreSQL; nothing on SQLite) and refuses an identifier the user lookup port already finds, with the message of the
  unique key (`Registration failed: a "…" with the same unique value already exists.`), before inserting. Keep a unique
  key on your identifier column anyway: an account created by your own code does not take AuthKit's lock. Inside a
  transaction you opened, the lock is released before your commit, so the unique key is the guard there. With
  `registration_mode: first_user_only`, the registration page and a social sign-up of the first account lock the whole
  user class, so only one first account is ever created.
- **The locks are session locks.** Behind PgBouncer, AuthKit's connection must use session pooling or a direct
  connection (transaction and statement pooling are not supported: the lock and its release could run on different
  server connections). ProxySQL is fine (it keeps the backend connection after `GET_LOCK()`). On Galera / Percona XtraDB
  Cluster the lock is local to a node: send AuthKit's writes to one writer node. A release that fails closes the
  connection (it reconnects on the next statement). **Known limitation** (audit QGD-L2, fix planned for 1.50.x): when
  AuthKit's call runs inside a transaction you opened, that `close()` ends your transaction (the server rolls it back,
  AuthKit's rows included) although the call returns normally; your next statements run in autocommit and your
  `commit()` throws "no active transaction". It needs a release statement that fails on a live session (statement
  timeout, killed query), so it is very rare; check `$connection->isTransactionActive()` before committing if you nest
  AuthKit calls in your own transactions.
- **Doctrine DBAL 3**: enable `doctrine.dbal.use_savepoints: true`. DBAL 4 always nests with savepoints; without them,
  DBAL 3 marks your whole transaction rollback-only when an AuthKit unit nested in it fails.
- `MfaStore` no longer takes the unused optional `EntityManagerRecovery` argument; `MfaStore::insertDevice()` returns a
  bool. `EntityManagerRecovery::run()` / `flush()` stay, without the immediate reset.
- A DDD host keeps its own runner for its own application services: AuthKit's services are wired to
  `nowo_auth_kit.transaction_runner` and never to your `TransactionRunnerInterface` alias.

## To 1.49.0

From **1.48.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.49.0

From **1.48.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Nothing to change for lookups: the default identifier normaliser keeps the 1.48.0 behaviour for usable identifiers.
Two behaviour changes can affect you (see [Identifier normaliser](#identifier-normaliser-default-unchanged)): an
identifier the normaliser refuses is no longer found by the lookup-port flows, and AuthKit no longer creates an account
with such an identifier. Read on if you want to move your identifier column (`user.email`) to a binary collation.

### Identifier normaliser (default unchanged)

Every identifier AuthKit looks a user up by now goes through `Identity\UserIdentifierNormalizerInterface` first:
password sign-in (the AuthKit login form, before the firewall), magic login, password reset request and code, social
e-mail match, QR completion, MFA admin reset and admin creation (`exists()`).

Why the default changes nothing: AuthKit queries the identifier **as typed**, so a folding column (MySQL
`utf8mb4_0900_ai_ci` / `utf8mb4_unicode_ci`, MariaDB `*_general_ci`) returns the stored `Bob@x` for the typed `bob@x`.
The exact re-check that follows (`UserIdentifierMatcher`) compares the **stored** identifier with the typed one **after
NFC + lower-casing both**, not byte for byte, so it accepts that row. The default normaliser (`exact`) returns usable
values unchanged, so the query and the re-check see exactly what they saw in 1.48.0. Hosts that store mixed-case
identifiers keep working, and on an exact column (PostgreSQL, SQLite, binary MySQL / MariaDB) `Bob@x` and `bob@x` stay
two accounts, as in 1.48.0.

The default refuses (finds no user, sends no query) an identifier with a control or format character (ZWSP, ZWJ, soft
hyphen, BOM, bidi marks), edge white space (NBSP included), invalid UTF-8 or more than 1024 characters. Those values
could not match through the re-check before either, except a stored identifier that itself contains such characters:

- **Lookups (BC break for such accounts):** such an account no longer receives a magic link or a reset e-mail, is not
  matched by a social e-mail, a QR completion or the MFA admin reset, and `AdminUserCreator::exists()` answers false.
  Password sign-in leaves those values to your user provider as before, so the person can still sign in with the
  password. Find those rows with the [detection step](#moving-the-identifier-column-to-a-binary-collation) (step 3,
  it does not need the rest of the recipe) and fix them.
- **Creation (BC break):** registration, invitation acceptance, admin creation (`nowo:auth-kit:create-admin`) and social
  sign-up now refuse such an identifier (`RuntimeException` "… the identifier is not usable (refused by the identifier
  normaliser)", shown like the other registration errors) instead of storing an account the lookups would never find.
  An empty identifier, or none (your factory derives it from other fields), is left to your validation as before.

Accounts AuthKit creates (registration, invitation, admin, social) store the normalised form: the factory, or your
`UserCreatorInterface`, receives the normalised identifier in `NewUser` (`$identifier`, or the `$formData` value of the
registration field mapped to `user_identifier_field`), so immutable entities and DDD aggregates get it too. With the
default normaliser that is the value as typed.

### Moving the identifier column to a binary collation

With a binary collation (`utf8mb4_0900_bin` on MySQL, `utf8mb4_nopad_bin` on MariaDB, REQ-SEC-004) the database
compares byte for byte: the typed `Bob@x` no longer finds the stored `bob@x`, at sign-in included. You need two things,
**live at the same time**: the stored identifiers in normalised form, and the opt-in normaliser:

```yaml
# config/packages/nowo_auth_kit.yaml
nowo_auth_kit:
    identifier_normalizer: lowercase_ascii_email   # or the id of your own UserIdentifierNormalizerInterface service
```

It lower-cases the ASCII letters `A`-`Z` of **every value with exactly one `@` and text on both sides**, and changes
nothing else: no Unicode case folding, no accent stripping, no trimming. `josÉ@x` stays apart from `josé@x`, `AdminUser`
(no `@`) stays as typed.

**It is a root option: it applies to every profile and every identifier, not only e-mail addresses.** A username
profile whose identifiers contain one `@` (`Team@Alpha`) is lower-cased too, and its stored rows need the same fix.
There is no per-profile opt-out: run the detection and fix on the identifier column of every profile's user class, or
write your own normaliser (it receives only the value) if a profile must keep such values as typed.

**Order: the option goes live in the same deploy as the row fix, never before.**

- **Exact store** (PostgreSQL, SQLite without `NOCASE`, a MySQL / MariaDB column that is already binary): fix the rows
  **first** (steps 1 to 5, for example in a Doctrine migration that runs before the new configuration serves requests),
  then switch the option on. With the option on and the rows not fixed, a legacy `Bob@x` is found by no spelling
  (lookups send `bob@x`), and a row `bob@x` created by a write path of yours would receive every lookup of `Bob@x`
  (pre-hijack). Close registration for the deploy (`registration_mode`) and run step 1 again afterwards: between the
  row fix and the switch, the default normaliser still lets someone register `Bob@x` next to the fixed `bob@x`.
- **Folding MySQL / MariaDB column** (`*_ci`): the order inside the deploy does not matter (the lower-cased value still
  folds to the same row, and the unique index refuses case twins). Run the ALTER (step 7) after the fix, in the same or
  a later deploy.

Since 1.49.0, with a normaliser other than the default, every AuthKit creation path (registration, invitation, admin,
social) refuses an identifier whose lower-cased form is already stored (`UserLookupInterface::hasCaseVariant()`). Your
own write paths (step 6) are not covered by that guard.

Steps for MySQL 8.0+ / MariaDB 10.6+ (table `user`, column `email`; adapt the names):

1. **Collisions** — accounts the normaliser would make one. Must return no row; otherwise merge or rename those accounts
   by hand first. It may also list Unicode case variants (`JOSÉ@x` / `josé@x`) that the ASCII normaliser keeps apart;
   review them.

   ```sql
   SELECT LOWER(email) COLLATE utf8mb4_bin AS normalised, COUNT(*) AS accounts
   FROM user
   GROUP BY LOWER(email) COLLATE utf8mb4_bin
   HAVING COUNT(*) > 1;
   ```

2. **Detection** — the identifiers not in normalised form (an ASCII capital letter, exactly one `@`):

   ```sql
   SELECT email
   FROM user
   WHERE email COLLATE utf8mb4_bin REGEXP '[A-Z]'
     AND email LIKE '_%@_%' AND email NOT LIKE '%@%@%';
   ```

3. **Unusable identifiers** — rows every normaliser refuses (the lookups never find them, see above). A quick SQL check
   for control characters and edge white space:

   ```sql
   SELECT email FROM user WHERE email REGEXP '[[:cntrl:]]|^[[:space:]]|[[:space:]]$';
   ```

   It does not see format characters (soft hyphen, ZWJ, bidi marks), NBSP or invalid UTF-8. The complete check is the
   normaliser itself, in a one-off script (DBAL `Connection $connection`):

   ```php
   use Nowo\AuthKitBundle\Identity\ExactIdentifierNormalizer;

   $normalizer = new ExactIdentifierNormalizer();
   foreach ($connection->iterateColumn('SELECT email FROM user') as $email) {
       if ($normalizer->normalize((string) $email) === null) {
           echo json_encode($email), PHP_EOL; // fix by hand: the person can still sign in with the password
       }
   }
   ```

4. **Fix** the pure-ASCII rows (bytes = characters; SQL `LOWER()` would also lower-case non-ASCII letters, which the
   normaliser keeps):

   ```sql
   UPDATE user
   SET email = LOWER(email)
   WHERE email COLLATE utf8mb4_bin REGEXP '[A-Z]'
     AND email LIKE '_%@_%' AND email NOT LIKE '%@%@%'
     AND CHAR_LENGTH(email) = LENGTH(email);
   ```

   Run step 2 again. Any row left contains non-ASCII characters: set it by hand to the normaliser's result (only `A`-`Z`
   lower-cased, e.g. `JOSÉ@Example.com` → `josÉ@example.com`), or with
   `(new LowercaseAsciiEmailIdentifierNormalizer())->normalize($email)` in a one-off script. Step 2 must return no row.
5. **AuthKit's own identifier-keyed rows** — in the same migration, with the same conditions (and the same manual fix
   for the non-ASCII rows):
   - `auth_kit_mfa.user_id` — **required when a user class is not a Doctrine entity** (DDD adapter, in-memory or
     host-built user): its MFA owner is `getUserIdentifier()`, so lower-casing the user row without this step detaches
     the second factor (optional MFA is silently lost; with mandatory MFA whoever holds the password enrols a new
     factor). Rows of Doctrine entities are keyed by the entity id and must not be touched:

     ```sql
     UPDATE auth_kit_mfa
     SET user_id = LOWER(user_id)
     WHERE user_type = 'App\\Security\\HostUser'   -- your non-Doctrine user class (MySQL doubles the backslashes)
       AND user_id COLLATE utf8mb4_bin REGEXP '[A-Z]'
       AND user_id LIKE '_%@_%' AND user_id NOT LIKE '%@%@%'
       AND CHAR_LENGTH(user_id) = LENGTH(user_id);
     ```

   - `auth_kit_account_vault.user_identifier` (account switcher entries; otherwise the old entries are no longer listed
     or erased for the account) and `auth_kit_social_account.user_identifier` (erasure of the social links): the same
     `UPDATE … SET user_identifier = LOWER(user_identifier) WHERE …` with the conditions of step 4 on `user_identifier`.
   - Open password reset requests (`auth_kit_password_reset`) expire on their own; a request made before the deploy may
     have to be made again.
6. **Your own write paths** must store the normalised form from now on (profile e-mail change, imports, your own
   registration): call the `UserIdentifierNormalizerInterface` service, or lower-case `A`-`Z` in the entity setter.
   AuthKit's creation paths do it (the factory or your `UserCreatorInterface` receives the normalised identifier in
   `NewUser`); a host factory that ignores `NewUser` and builds the identifier itself must normalise it too.
7. **ALTER** the column (keep its length, nullability and indexes). MySQL: `utf8mb4_0900_bin`. MariaDB: use
   `utf8mb4_nopad_bin`, which every supported MariaDB version has (MariaDB 11.4 also lists `utf8mb4_0900_bin`, NO PAD
   there too, but older releases may not have it):

   ```sql
   -- MySQL 8.0+
   ALTER TABLE user MODIFY email VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
   -- MariaDB
   ALTER TABLE user MODIFY email VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL;
   ```

   Declare the collation on the mapping so `doctrine:migrations:diff` keeps it:
   `#[ORM\Column(length: 180, options: ['collation' => 'utf8mb4_0900_bin'])]`.
8. Switch `identifier_normalizer: lowercase_ascii_email` on (same deploy, see the order above), then run step 1 again
   and check: sign in as `Bob@Example.COM` for the stored `bob@example.com`.

**PostgreSQL** compares exactly already (deterministic collation): it is an exact store, so steps 1 to 6 come first and
there is no ALTER. `user` is a reserved word in PostgreSQL: quote the table (`"user"`), otherwise `FROM user` reads
`current_user`. Use `email ~ '[A-Z]'` instead of the `REGEXP` condition, `GROUP BY LOWER(email)` without `COLLATE`,
`OCTET_LENGTH(email)` instead of `LENGTH(email)`, `email ~ '[[:cntrl:]]|^[[:space:]]|[[:space:]]$'` in step 3, and a
single backslash in `user_type` literals (`'App\Security\HostUser'`):

```sql
SELECT LOWER(email) AS normalised, COUNT(*) AS accounts FROM "user" GROUP BY LOWER(email) HAVING COUNT(*) > 1;

SELECT email FROM "user" WHERE email ~ '[A-Z]' AND email LIKE '_%@_%' AND email NOT LIKE '%@%@%';

UPDATE "user"
SET email = LOWER(email)
WHERE email ~ '[A-Z]'
  AND email LIKE '_%@_%' AND email NOT LIKE '%@%@%'
  AND CHAR_LENGTH(email) = OCTET_LENGTH(email);
```

**SQLite** compares exactly unless the column is declared `NOCASE` (an exact store otherwise): fix the rows first, with
a one-off script using the normaliser (SQLite's `LOWER()` only lower-cases ASCII letters, so a plain
`UPDATE "user" SET email = LOWER(email) WHERE email GLOB '*[A-Z]*' AND email LIKE '_%@_%' AND email NOT LIKE '%@%@%'`
gives the same result).

What the tests run: `tests/Integration/LookAlike/IdentifierNormalizerKernelTest::testTheBinaryCollationMigrationRecipe()`
runs the collision (step 1), detection (step 2), control-character check (step 3, SQL only) and fix (step 4) statements
and the ALTER (step 7) on the test table `kernel_users` (instead of `user`) on MySQL 8.4, MariaDB 11.4 and PostgreSQL
17 (CI job `real-databases`), then signs in by every ASCII spelling. The step 5 statements, the PHP check of step 3 and
the SQLite variant are not run by the tests; try them on a copy of your data first.

### Throttling, MFA and LoginThrottleBundle

- **Password sign-in throttling** reads the identifier after the normaliser (the AuthKit form value is replaced at
  `kernel.request` priority 16, before the firewall at 8): Symfony `login_throttling` and LoginThrottleBundle count
  `Bob@x` and `bob@x` as one user. Both already lower-case the username for their keys (LoginThrottleBundle database
  storage also trims), so nothing changes for them. A host listener that reads `login_form[_username]` at a priority
  above 16 sees the raw value.
- **Magic login / reset request limits** are per client IP and device, not per identifier: unchanged. The **reset-code
  attempt key** is the stored identifier of the resolved account (else the NFC + lower-cased input): unchanged.
- **MFA owners** come from the loaded user (its id, or `getUserIdentifier()` for users that are not Doctrine entities),
  never from the typed value: unchanged. Since 1.47.0 `getUserIdentifier()` must return the stored value; with the
  normaliser and a binary column the stored value is the normalised one, so a user class that is not a Doctrine entity
  needs step 5 of the recipe, or its MFA rows are detached.

### Writing your own normaliser

Implement `Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface` and set `identifier_normalizer: <service id>`
(or alias the interface). Rules: return null to refuse; be idempotent; never map two values that can belong to two
stored accounts to the same result (no accent stripping, no Unicode case folding, no `+tag` removal unless your store
enforces it); keep it stateless (one instance serves every request of a FrankenPHP worker). It receives only the value,
not the profile: it runs for every profile.

Host lookup adapters (`UserDirectoryInterface` / `UserLookupInterface`) receive the normalised identifier in
`findByIdentifier()`. **`hasCaseVariant()` receives the raw value** (as typed in the registration or invitation form, or
passed to the admin command or by the social provider): compare it case-insensitively with the stored identifiers, and
normalise it yourself first if your normaliser changes more than ASCII case (for example NFC composition).

## To 1.48.0

From **1.47.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### Extra authorization parameters (1.48)

`authorization_params` (on the profile, or `setAuthorizationParams()` on the credential) adds parameters such as
`access_type=offline`, `login_hint` or `hd` to the authorization request. The client assembles and encodes the query, so
never paste a query string into `authorize_url`. The protocol parameters the bundle owns — `state`, `redirect_uri`,
`scope`, `client_id`, `client_secret`, `response_type`, `code_challenge`, `code_challenge_method`, and the parameters
that would carry a whole request of their own (`request`, `request_uri`, `response_mode`, `nonce`, `claims`,
`client_assertion`, `client_assertion_type`, `code`, `grant_type`) — cannot be set: the
configuration refuses them at compile time, `setAuthorizationParams()` throws, and a value written straight into the
database row is ignored. See [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md#extra-authorization-parameters).

### Schema: write the migration by hand (1.48) — required on MySQL / MariaDB

The credential table gains one column, `auth_kit_social_credential.authorization_params`.

**Write the migration by hand, as you do for this bundle's other tables.** That is the standing practice, not a
workaround for this column: the bundle's tables live outside your ORM metadata, so `doctrine:migrations:diff` also
proposes destructive, unrelated changes for them — hosts have seen it offer two hundred lines including
`DROP TABLE messenger_messages`. Never ship its output for these tables unreviewed.

For this column specifically, the single statement `diff` generates is wrong on a table that already has rows:

```sql
-- WRONG on a non-empty table:
ALTER TABLE auth_kit_social_credential ADD authorization_params JSON NOT NULL;
```

PostgreSQL refuses it outright, so you cannot get it wrong there. On **MySQL and MariaDB it succeeds** and leaves the
existing rows holding a value that is not a JSON object: the JSON scalar `null` on MySQL 8, and the **empty string** on
MariaDB (which slips past `CHECK (json_valid(…))`, because `ALTER` does not re-validate existing rows). Since 1.48 the
entity reads such a row as an empty map, so the login and invitation pages stay up, but the column is not really
populated. Use this instead:

```sql
-- MySQL / MariaDB (a JSON NOT NULL column cannot carry a literal default, so add, backfill, then tighten):
ALTER TABLE auth_kit_social_credential ADD authorization_params JSON NULL;
UPDATE auth_kit_social_credential SET authorization_params = '[]' WHERE authorization_params IS NULL;
ALTER TABLE auth_kit_social_credential MODIFY authorization_params JSON NOT NULL;
```

```sql
-- PostgreSQL:
ALTER TABLE auth_kit_social_credential ADD authorization_params JSON NOT NULL DEFAULT '[]';
ALTER TABLE auth_kit_social_credential ALTER COLUMN authorization_params DROP DEFAULT;
```

#### The document shape, pinned

**An empty map is stored as `[]`**, because that is what the bundle itself writes: Doctrine's `json` type encodes the
PHP empty array as the JSON array `[]`. A map with entries is a JSON object
(`{"prompt":"select_account"}`), and both `[]` and `{}` decode back to an empty PHP array, so `{}` is read correctly —
it is simply not what the bundle writes. Backfill with `'[]'` and a repaired row is indistinguishable from one the
bundle wrote itself. The same holds for the older `scopes` column (a JSON array, empty as `[]`).

#### Repairing rows if you already ran the generated statement

This statement is **idempotent** (safe to run twice) and **scoped to exactly the three states the bad `ALTER` can
leave**, so it cannot touch a row that this or a later version wrote legitimately:

```sql
UPDATE auth_kit_social_credential
   SET authorization_params = '[]'
 WHERE authorization_params IS NULL
    OR CAST(authorization_params AS CHAR) IN ('', 'null');
```

- `IS NULL` — the column added as nullable (by hand, or by a `diff` you adjusted).
- `''` — **the MariaDB case.** A repair that only tests `IS NULL` misses it, because MariaDB fills the existing rows
  with the empty string, not with SQL `NULL` and not with JSON `null`.
- `'null'` — the MySQL 8 case, the JSON scalar `null`. The `CAST(… AS CHAR)` comparison is deliberate: `JSON_TYPE()`
  raises an error on the MariaDB empty string, and SQL does not promise you left-to-right `OR` evaluation to protect
  you from it.

Rows holding `[]`, `{}` or real parameters match none of those predicates and are left alone.

A row left as SQL `NULL` is **harmless at read time** — the entity reads it as an empty map, which is why the pages
stay up — but rewrite it anyway: the mapping declares the column `NOT NULL`, so a later `MODIFY … NOT NULL` (or a
schema validation) fails while such a row exists.

The older `scopes` column of the same table has the identical `JSON NOT NULL` shape. It has always been created with
the table rather than added by an `ALTER`, so no host should have a bad row in it; since 1.48 it is read with the same
tolerance, so a host that did add it by hand as nullable is no longer at risk either.

### An extra parameter embedded in `authorize_url` (1.48)

If you worked around the missing option by putting the parameter in the credential's `authorize_url` — for example
`https://accounts.google.com/o/oauth2/v2/auth?prompt=consent` — nothing breaks: since 1.48 a parameter the bundle
sends is **removed from the authorize URL's own query** rather than repeated, because repeating a parameter is
forbidden (RFC 6749 §3.1) and Google and Microsoft Entra answer `invalid_request`. The rest of your query string is
kept untouched.

Which value wins: the one from `authorization_params` (or the built-in default), not the one in the URL. So move the
parameter to where it belongs:

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                authorization_params:
                    google:
                        prompt: consent     # or prompt: ~ to send none at all
```

### The account chooser on google and microsoft (`prompt=select_account`, 1.48)

**What changed.** The built-in `google` and `microsoft` providers now send `prompt=select_account` on the authorization
request. The provider asks which account to use, every time, instead of reusing whichever one the browser has open.

**Why.** Without the parameter the provider reuses the open session silently. Someone signed in to a personal Gmail and
a work account at the same time is bound to whichever account the browser happened to have open, with no chance to
choose — and the provider's subsequent error page has no account switcher either, so the only way out is unlinking the
wrong identity and signing out of the other account in that browser. That binds a local account to an unintended
identity, so it is treated as an identity-correctness bug rather than a preference.

**Who is affected.** Every host already using the built-in `google` or `microsoft` providers: people will see an account
chooser where they did not before. Nothing else about the flow changes, no data migration is involved, and custom
providers are untouched.

**How to restore the previous behaviour.** Remove the parameter with a `null` value — per profile:

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                authorization_params:
                    google:
                        prompt: ~
                    microsoft:
                        prompt: ~
```

or per credential, which wins over the configuration:

```php
$credential->setAuthorizationParams(['prompt' => null]);
```

A `null` value removes the parameter from the authorization request; it is never sent as an empty value. Any other value
replaces it (`prompt: consent`, `prompt: login`, …).

### Provider brand marks on the social / SSO buttons (1.48)

Nothing to do for a compliant button: an enabled `google`, `github` or `microsoft` credential now renders the
provider's official mark next to the label on the login page, with no configuration and no template override. Run
`php bin/console assets:install` if your deployment does not already run it (Flex does it on `composer install`) —
the marks ship in the bundle's public assets, like its CSS.

Two host-visible contracts changed:

- **`social_links` / `sso_links`** of the login template are now `Nowo\AuthKitBundle\View\ProviderLink` instead of
  `Nowo\UiKitBundle\View\Link`. `label` and `href` keep their names, so a template that prints `_link.label` /
  `_link.href` keeps working. Code or a partial that type-hints `Link` calls `$providerLink->link()`.
- **`invitation_social_providers`** rows carry an extra `mark` key (`?View\ProviderMark`).

A host that overrode `security/login.html.twig` keeps its own markup and gets no mark; move the override to the new
`nowo_auth_kit_provider_link` block (`_link` in scope, `{{ parent() }}` renders the compliant default) to get one.

To replace or remove a mark — for instance to ship your own copy of a provider's artwork, or not to redistribute one:

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                provider_marks:
                    github: false
                    google: { path: 'images/google-g.svg', on_white: true }
```

See [BRAND-MARKS.md](BRAND-MARKS.md) for the source and the trademark position of every shipped mark, and why no Apple
mark is shipped.

## To 1.47.0

From **1.46.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Security release (REQ-SEC-006). Nothing to change in the configuration. PostgreSQL and SQLite: nothing to do.
MySQL / MariaDB: run the detection queries below, then the ALTERs (or `doctrine:migrations:diff`, which now generates
them).

Behaviour changes:

- MFA owners, social provider subjects, switcher identifiers and invitation e-mails / inviters are compared **byte for
  byte**: a case variant or another Unicode normalisation form (`José` NFC vs NFD) is another user. 1.36 to 1.46.0
  compared the switcher and social-link erasure lower-cased and NFC-normalised.
- **`getUserIdentifier()` must return the stored, canonical value** when it is the MFA owner id (users that are not
  Doctrine entities). Owner ids are byte-exact: a user provider that loads users case-insensitively and returns the
  identifier **as typed** (`Jose@` for the stored `jose@`) gives that sign-in another owner, so the user's second factor
  is not found (and a required user is asked to enrol). Return the value from your storage, not the login input.
- An MFA owner id (the user identifier of users that are not Doctrine entities, or a string primary key) with edge white
  space or NBSP, a control or format character (ZWSP, ZWNJ, ZWJ, BOM, bidi marks, soft hyphen, line separators), invalid
  UTF-8 or more than 191 characters cannot have MFA (`MfaOwner::usable()`). If MFA is required for such a user, or the
  user enrolled before 1.47.0, the sign-in stops at the enrolment step, which refuses it with the message
  `mfa.flash.unavailable` (a warning is logged): fix the identifier, then let the user enrol again. Identifiers with
  ZWNJ / ZWJ are legitimate in some scripts (Persian, Indic); give those users an id without them (e.g. a numeric
  primary key) if they need MFA.
- **Required MFA fails closed** without a usable owner (also a composite, empty or over-long id): 1.46 let such a user
  sign in with the password alone.
- `MfaManager::enable()` throws the new `MfaUnavailableException` (a `RuntimeException`) for a user without a usable
  owner id (1.46: a `LogicException`), and when the MFA table still has a folding unique key and a look-alike owner's
  row matches (the second of two look-alike users cannot enrol until the ALTER). It is thrown **before** any write, so
  the entity manager stays open. AuthKit's enrolment pages show `mfa.flash.unavailable` instead of an error page; catch
  it if you call `enable()` yourself.
- Personal-data export, erasure and user removal still reach MFA rows and social links stored under an identifier that
  is now refused, byte for byte.
- A social provider subject with such characters, or longer than 255 characters, is refused.
- A warning is logged when the binary collation falls back to the PAD SPACE `utf8mb4_bin`: MySQL 5.7, or a
  `doctrine.dbal.server_version` without the patch number (`8`, `8.0`). Set the full version (e.g. `8.4.0`,
  `11.4.0-MariaDB`).

### Identity columns matched byte for byte (MySQL / MariaDB)

The code re-checks every row in PHP, so the fix holds without the ALTERs. The ALTERs make the database exact too:

- **Required** for `auth_kit_mfa` (`user_type`, `user_id`) when your MFA owner ids are strings (users that are not
  Doctrine entities, or string primary keys): its unique key `uniq_auth_kit_mfa_owner` folds, so a look-alike user
  (`Jose@` next to `jose@`) cannot enrol until the column is binary.
- **Recommended** for the other columns (exact SQL, no look-alike candidates to read and drop).

Use the table names of your prefix (`doctrine.table_prefix`). Run the detection queries **before** the ALTER: they group
by the column's current collation (as the old code matched) and count the distinct byte values, so each row is a set of
look-alike values. `TRIM`, `DISTINCT`, `LOWER` and `_ai_ci` comparisons fold too; only `CAST(… AS BINARY)` and `HEX()`
show the stored bytes.

```sql
-- 1. Look-alike groups per identity column (one row per group; HEX shows which values differ).
SELECT profile, user_type, user_id, COUNT(*) AS n, GROUP_CONCAT(HEX(user_id)) AS ids
  FROM auth_kit_mfa GROUP BY profile, user_type, user_id
  HAVING COUNT(DISTINCT CAST(user_type AS BINARY), CAST(user_id AS BINARY)) > 1;
SELECT provider, provider_user_id, user_class, COUNT(*) AS n, GROUP_CONCAT(HEX(provider_user_id)) AS subjects
  FROM auth_kit_social_account GROUP BY provider, provider_user_id, user_class
  HAVING COUNT(DISTINCT CAST(provider_user_id AS BINARY)) > 1;
SELECT user_class, user_id, COUNT(*) AS n, GROUP_CONCAT(HEX(user_id)) AS ids
  FROM auth_kit_social_account GROUP BY user_class, user_id
  HAVING COUNT(DISTINCT CAST(user_id AS BINARY)) > 1;
SELECT profile, user_identifier, COUNT(*) AS n, GROUP_CONCAT(HEX(user_identifier)) AS identifiers
  FROM auth_kit_account_vault GROUP BY profile, user_identifier
  HAVING COUNT(DISTINCT CAST(user_identifier AS BINARY)) > 1;
SELECT profile, email, COUNT(*) AS n, GROUP_CONCAT(HEX(email)) AS emails
  FROM auth_kit_invitation WHERE accepted_at IS NULL AND revoked_at IS NULL GROUP BY profile, email
  HAVING COUNT(DISTINCT CAST(email AS BINARY)) > 1;
SELECT invited_by, COUNT(*) AS n, GROUP_CONCAT(HEX(invited_by)) AS inviters
  FROM auth_kit_invitation WHERE invited_by IS NOT NULL GROUP BY invited_by
  HAVING COUNT(DISTINCT CAST(invited_by AS BINARY)) > 1;

-- 2. MFA credentials that 1.47.0 no longer reaches. A heuristic on the UTF-8 bytes: edge white space (ASCII space via
--    TRIM; NBSP, Ogham, EN/EM/thin/hair spaces, narrow NBSP, medium math space, ideographic space), and anywhere ASCII
--    and C1 controls (NEL...), soft hyphen, Arabic/Syriac format marks, Mongolian vowel separator, ZWSP / ZWNJ / ZWJ,
--    LRM / RLM, line / paragraph separators, bidi embeddings and isolates, word joiner and invisible operators, BOM,
--    interlinear annotations, tags. The code refuses by Unicode class (`ExactIdentifier`); a row this query misses is
--    still never reached (fail closed), it only stays in the table.
SELECT id, profile, user_type, HEX(user_id) FROM auth_kit_mfa
 WHERE CAST(user_id AS BINARY) <> CAST(TRIM(user_id) AS BINARY)
    OR HEX(user_id) REGEXP '^(09|0A|0B|0C|0D|C2A0|E19A80|E2808[0-9A]|E280AF|E2819F|E38080)|(09|0A|0B|0C|0D|C2A0|E19A80|E2808[0-9A]|E280AF|E2819F|E38080)$'
    OR HEX(user_id) REGEXP '^([0-9A-F]{2})*(0[0-9A-F]|1[0-9A-F]|7F|C2[89][0-9A-F]|C2AD|D88[0-5]|D89C|DB9D|DC8F|E1A08E|E2808[B-F]|E280A[8-E]|E281A[0-9A-F]|EFBBBF|EFBFB[9AB]|F3A0)';

-- 3. Such a row is orphaned (nobody can use it). Once the user's identifier is fixed (or the user is gone), delete it
--    with its recovery codes and trusted devices (<id> from query 2; with the table prefix of your configuration):
DELETE FROM auth_kit_mfa_recovery_code WHERE mfa_id = <id>;
DELETE FROM auth_kit_mfa_trusted_device WHERE mfa_id = <id>;
DELETE FROM auth_kit_mfa WHERE id = <id>;
```

A group in query 1 for `auth_kit_mfa` cannot exist while the folding unique key is there; for the other tables it means
two users (or subjects) that the old code could confuse: check them before the ALTER. The ALTER itself never fails on
duplicates (a binary collation only splits groups). A look-alike enrolment before 1.47.0 left no trace in
`auth_kit_mfa` (the row keeps its owner id): with string owner ids, reset the MFA of a user whose authenticator stopped
working rather than assuming a lost device.

MySQL 8.0.17+ (`utf8mb4_0900_bin`; MariaDB: `utf8mb4_nopad_bin`; MySQL 5.7 only: `utf8mb4_bin`):

```sql
ALTER TABLE auth_kit_mfa
  MODIFY user_type VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE auth_kit_social_account
  MODIFY provider_user_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY user_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY user_identifier VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE auth_kit_account_vault
  MODIFY user_identifier VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE auth_kit_invitation
  MODIFY email VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
  MODIFY invited_by VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
```

Check afterwards: `SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA =
DATABASE() AND TABLE_NAME LIKE '%auth_kit_%' AND COLLATION_NAME IS NOT NULL;`

## To 1.46.0

From **1.45.2**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Nothing to change: defaults and existing host services behave exactly as in 1.45.2. 1.46.0 adds the opt-in paths of
REQ-ARCH-001 (DDD / hexagonal hosts) and deprecates the framework-typed members that 2.0 removes. Check one thing: the
build now fails when a configured or tagged port service implements neither its interface nor the 1.46 twin (see
below).

### Framework-free host ports (optional until 2.0)

Nothing to change: every deprecated interface keeps working exactly as in 1.45 until 2.0. To keep Symfony types out of
your adapters (DDD / hexagonal hosts), implement the 1.46 twin instead:

| Deprecated interface | Implement instead | Wiring |
|---|---|---|
| `User\UserLookupInterface` | `User\UserDirectoryInterface` | alias the new interface to your service |
| `User\UserFactoryInterface` | `User\UserCreatorInterface` | alias |
| `User\UserWriterInterface` | `User\UserStoreInterface` | alias |
| `QrLogin\QrLoginStepUpInterface` | `QrLogin\QrLoginStepUpPolicyInterface` (return `false` instead of throwing `AccessDeniedException`) | alias |
| `Mfa\MfaPolicyInterface` | `Mfa\MfaRequirementPolicyInterface` | same `mfa.policy` service id |
| `MultiAccount\EnrolmentGateInterface` | `MultiAccount\EnrolmentPolicyInterface` | same `multi_account.enrolment_gate` |
| `MultiAccount\AccountPresenterInterface` | `MultiAccount\AccountPresentationProviderInterface` | same `multi_account.presenter` |
| `Invitation\Security\InvitationsAccessCheckerInterface` | `Invitation\Security\InvitationsAccessPolicyInterface` | same `invitations.security.access_checker` |
| `Invitation\Security\InvitationActionAuthorizerInterface` | `Invitation\Security\InvitationActionPolicyInterface` (an `InvitationSnapshot` instead of the entity) | autoconfigured, same tag |
| `Invitation\InvitationGrantInterface` | `Invitation\InvitationGrantHandlerInterface` | autoconfigured, same tag |
| `SocialLogin\SocialCallbackHandlerInterface` | `SocialLogin\SocialCallbackStepInterface` (return a `SocialCallbackResult`; AuthKit signs in and redirects) | autoconfigured, same tag |

- `AuthUserRef::$roles` are the role names the user object reports, **without the role hierarchy** (`ROLE_ADMIN` does
  not imply `ROLE_USER` there): check reachable roles through your own security service if you need them.
- `EnrolmentCandidate::$secondFactorPending` only knows AuthKit's own MFA; with another 2FA bundle, refuse its
  "pending" token through `$tokenClass` (and use `isFullyAuthenticated()` to exclude remember-me sign-ins).
- Users are `ValueObject\AuthUserRef` (`userClass`, `id`, `identifier`, `roles`, `user()`); requests are
  `ValueObject\AuthRequestContext` (method, path, route, client IP, user agent, locale, firewall, profile, `header()`,
  `query()`, `post()`, `sessionHas()` / `sessionGet()` / `sessionRemove()`).
- The user ports and `SocialCallbackResult::signIn()` need the reference to carry your **security user object**
  (`AuthUserRef::of($securityUser, $class, $id, $identifier, $roles)`): AuthKit signs that object in and hashes its
  password. A reference by id alone (`AuthUserRef::ofId()`) is refused there with a `LogicException`.
- Which interface is asked: a service implementing the deprecated interface is asked through it (also when it
  implements the twin too); only-twin services through an internal gate. For the aliased ports, alias **only** the new
  interface: aliasing both to different services fails the build. Your decorators of the deprecated id still wrap the
  port (AuthKit's own Device Intelligence step-up decorator included).
- **Build-time check (new):** a configured or tagged port service whose class implements neither interface fails the
  build. 1.45 ignored such a per-profile service silently (an `mfa.policy` implementing nothing made MFA optional); if
  your build fails here, the service was never asked.
- Symfony's DebugClassLoader reports, in debug mode, each of your classes that implements a deprecated interface.

### Event accessors

Nothing to change: the members below still work until 2.0. To keep listeners free of Symfony types (DDD /
hexagonal hosts), read the replacement instead.

| Class | Deprecated member | Replacement |
|---|---|---|
| `Admin\AdminUserCreatedEvent`, `Admin\AdminUserCreatingEvent` | `getUser()` | `userRef()` (the object for host fields: `userRef()->user()`) |
| `MagicLogin\AdminMagicLinkIssuedEvent` | `$issuer`, `$target` | `issuerRef()`, `targetRef()` |
| `Invitation\Event\InvitationEvent` (Created, Resent, Revoked, Accepted) | `$actor` | `actorRef()` |
| `Invitation\Event\InvitationAcceptedEvent` | `$user` | `userRef()` (or the 1.42 scalars `$userClass`, `$userId`, `$userIdentifier`) |
| `Invitation\Event\InvitationAcceptingEvent` | `$user` | `userRef()` (null: a new account) |
| `Mfa\Event\MfaEvent` (all MFA events) | `$user` | `userRef()` |
| `Mfa\Event\MfaResetByAdminEvent` | `$actor` | `actorRef()` |
| `Mfa\Event\MfaLoginCompletedEvent` | `$token`, `$request` | `userRef()` + `firewall()`, `requestContext()` |
| `MultiAccount\Event\AccountEvent` (Added, Removed, Switched, Switching) | `$request` | `requestContext()` (its `profile` is the event's profile; `firewall` is null) |
| `MultiAccount\Event\AccountAddedEvent`, `AccountRemovedEvent` | `$user` | `userRef()` |
| `MultiAccount\Event\AccountSwitchedEvent`, `AccountSwitchingEvent` | `$from`, `$to` | `fromRef()`, `toRef()` |
| `QrLogin\Event\QrLogin{ChallengeCreated,Approved,Denied,Completed}Event` | `$challenge` | `challengeId()`, `challengeRef()` |
| `QrLogin\Event\QrLogin{Approved,Completed}Event` | `$user` | `userRef()` |
| `User\Event\CredentialsChangedEvent` | `$user` (read side) | `userRef()`; the constructor still takes the user object |
| `MultiAccount\AccountState`, `MultiAccount\SwitchOutcome`, `Invitation\InvitationAcceptance` | `$user` | `userRef()` |

`AuthUserRef` and `AuthRequestContext` are built on each call: compare users with `isSameUserAs()` (class and id),
not with `===`. The read-write events keep their semantics: `AccountSwitchingEvent::refuse()`,
`InvitationAcceptingEvent::refuse()`, and fields a listener sets on `AdminUserCreatingEvent::userRef()->user()`
(the same object as `getUser()`).

### Private entity manager (`doctrine.private_entity_manager`)

Nothing changes unless you opt in. With `nowo_auth_kit.doctrine.private_entity_manager: true`:

- AuthKit stops flushing your default entity manager on its own writes (QR login, invitations, MFA, social links,
  account switcher). Your pending changes are stored only when **you** flush. If your code relied on an AuthKit write to
  flush your own pending changes, flush them yourself.
- The tables, connection and mapping do not move; the private manager shares the default manager's connection, so a
  transaction you open around an AuthKit call includes AuthKit's rows (its statements then run in savepoints).
- `SocialLoginAccountRepository` / `SocialLoginCredentialRepository` (what `$em->getRepository(SocialLogin…::class)`
  returns) stay bound to **your** manager: editing social credentials or accounts through them and flushing your
  manager works as before. AuthKit's own services use private copies of them. `InvitationRepository` and
  `QrLoginChallengeRepository` services are AuthKit's and use the private manager; an `$em->getRepository(Invitation::class)`
  of your own manager still returns a repository on your manager.
- The invitation acceptance and `SocialAccountLinker::linkAndSignIn()` keep their own transaction on the shared
  connection. The acceptance still flushes your manager **inside that transaction** (the account and what your
  `InvitationGrantInterface` changed), as in 1.45; the invitation row is flushed through the private manager in the
  same transaction.
- Requires the AuthKit entities mapped on the default manager (`doctrine.register_mapping` true, or auto with a feature
  that uses them); the build fails otherwise. `doctrine.entity_manager` keeps its 1.43 meaning (closed-manager recovery
  set); the private manager is always created on the default manager.
- Entities you change in memory but never save through AuthKit are dropped at the next boundary and logged as a warning
  (entity classes and counts only).
- With doctrine/dbal 3 the rollback middleware is not registered: after a rollback the private manager is only replaced
  at the next boundary.

`private_entity_manager: false` is deprecated; in 2.0 the private manager is the only mode.

### XML mapping (`doctrine.mapping_type`)

`nowo_auth_kit.doctrine.mapping_type: xml` (default `attribute`) maps the AuthKit entities from
`src/Resources/config/doctrine-xml/` on the default manager. A host that maps them itself on another manager
(`doctrine.entity_manager`) can point its own mapping at that directory (`type: xml`, `prefix: Nowo\AuthKitBundle\Entity`).

### Reset credential in a bundle table (optional)

Nothing changes by default (`password_reset.storage: user_entity`). To keep the reset credential off your user entity
(DDD hosts, users that are not Doctrine entities):

```yaml
nowo_auth_kit:
    password_reset:          # or profiles.<name>.password_reset
        storage: table
```

0. The table is mapped only while a profile uses `storage: table` (hosts that do not use it never see it in their
   migrations). On MySQL / MariaDB the identity and hash columns get a binary collation in the generated migration.
1. Create the table (migration): `bin/console doctrine:migrations:diff` (or `doctrine:schema:update`) adds
   `auth_kit_password_reset` (with your `doctrine.table_prefix`). `doctrine.register_mapping` must not be `false`.
2. Pending credentials stored on the user are not migrated: a user who asked for a link before the switch asks again.
   You can then drop the `token_field` / `token_expires_field` columns from the user (they are no longer read).
3. With a custom `UserLookupInterface`, `findById()` must find the user by the id AuthKit stores (`(string) getId()`,
   else the readable `id` property); users without an id are found by `findByIdentifier()`.
4. A password or identifier change made outside a Doctrine flush must dispatch `CredentialsChangedEvent` (as before).

### Coming in 2.0

The deprecated port interfaces and event members are removed; the private entity manager becomes the only mode.

## To 1.45.2

From **1.45.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.45.1

From **1.45.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.45.0

From **1.44.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Nothing to change. With UserKit installed and the AuthKit entities mapped, the eraser `auth_kit_invitations` and the
export sections `invitations` and `social_accounts` register automatically (see
[USAGE](USAGE.md#gdpr-erasure-and-export)):

- an erasure now also deletes the invitations sent to the person, revokes their open invitations and removes their
  identifier from the other invitations;
- the export section keys `invitations` and `social_accounts` can clash with your own export providers: UserKit's
  `PersonalDataExporter` throws a `LogicException` ("Duplicate personal data export section") on a duplicate key, so the
  whole personal-data export fails. If your application already exports `invitations` or `social_accounts`, remove your
  own provider in the same upgrade, or set `nowo_auth_kit.personal_data.enabled: false`. Do the same if you already
  erase these tables and must keep doing it your way (duplicate eraser keys do not fail: both run, the second finds
  nothing);
- with retention obligations for these records, set `nowo_auth_kit.personal_data.enabled: false`.

## To 1.44.0

From **1.43.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No action required: both new features are off by default.

- To stop a sign-in from landing on an API, JSON, asset or foreign URL saved by a background call of an expired
  session, set `nowo_auth_kit.login_target.enabled: true`. Check that your `default_target_path` and
  `login_success_route` are same-origin HTML pages, and add a `LoginTargetVoterInterface` service for HTML pages the
  default rules refuse (for example a page under an `api` path segment). Setting `denied_path_prefixes` or
  `denied_path_patterns` replaces the default list.
- To answer background calls of an expired session with a JSON 401 instead of a login redirect, set
  `nowo_auth_kit.session_expired.enabled: true` and handle `401` + `X-Auth-Kit: session-expired` in your front end
  (Turbo and htmx included: see the snippet in LOGIN-TARGET.md).
- With `login_target` enabled, every fallback route must exist and need no parameters: `cache:warmup` fails otherwise.
- `MfaContainmentSubscriber` now listens to `kernel.exception` at priority 4 (was 2). Only a host listener registered
  between priorities 2 and 4 on that event would see a different order.

See [LOGIN-TARGET.md](LOGIN-TARGET.md).

## To 1.43.0

From **1.42.0**: `composer update nowo-tech/auth-kit-bundle`. No configuration change is needed.

- When a bundle write fails and closes an entity manager, AuthKit now resets only its own managers: the one the write
  went through and the one that maps the AuthKit entities. A closed manager of the host is no longer reset by the
  bundle; the host resets it itself, as with any other failure of its own.
- Optional: `nowo_auth_kit.doctrine.entity_manager: <name>` names an additional manager that maps the AuthKit entities
  (the host maps them there), which the bundle resets too. The manager of the entities (`getManagerForClass()`) is
  always included. The build fails when the named manager does not exist or does not map `Nowo\AuthKitBundle\Entity`.
  The AuthKit mapping and writes stay on the default manager; a dedicated manager is not supported yet.

## To 1.42.0

From **1.41.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Target **1.42.0**, from **1.41.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Nothing to change: every new key defaults to the 1.41 behaviour. Optional (see
[INVITATIONS](INVITATIONS.md#host-follow-ups-142)):

- **Existing accounts accepting invitations**: replace your own `InvitationRepository::claim()` call with
  `InvitationAcceptor::acceptExisting($user, $invitation, $user->getEmail())` (pass the account's current, verified
  e-mail, outside your own transaction). It dispatches `InvitationAcceptingEvent` with `$event->user` set — if your listener refuses addresses that
  already have an account, let `$event->isExistingAccount()` through — then `InvitationAcceptedEvent` with
  `existingAccount: true` (no `UserRegisteredEvent`). Invitations carrying grantable roles are refused for existing
  accounts.
- **Bulk invitations**: set `invitations.batch.max_size` (and `rate_limit` / `rate_window`) and call
  `InvitationManager::inviteBatch()` with your own bounded list.
- **Shared or persistent rate-limit counters**: `nowo_auth_kit.attempt_limiter.cache_pool: cache.rate_limits` (any
  PSR-6 pool service).
- **Social acceptance on AuthKit's page**: `invitations.social.accept_page: true` (with `social_login.mode: enabled`),
  optionally `name_field` and `keep_pending_on_mismatch: true` (not with `social_login.link_existing_account: deny`). Remove a host-side social start route on
  `/invitation/social/{provider}`, its callback handler and the Twig helpers that listed the providers. The page
  firewall must allow anonymous `POST /invitation/social/{provider}` (like `/invitation`).
- **Seat or quota check when the link opens**: listen to `InvitationOpeningEvent` and `refuse()` it with an
  `InvitationRefusal`; keep the `InvitationAcceptingEvent` check too (it is the one that runs on submit).
- **Templates**: the accept page receives `invitation` (`InvitationSnapshot`) — read it instead of
  `InvitationAcceptor::pending()` in a Twig extension. A custom `invitation_invalid` template sees the new kinds
  `unavailable`, `social_mismatch`, `social_failed` and `accepted`.

## To 1.41.0

From **1.40.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Target **1.41.0**, from **1.40.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Nothing to change: the new user ports are aliased to the Doctrine + PropertyAccessor implementations of earlier
releases. Optional:

- **Non-Doctrine security user / domain-owned users**: alias `Nowo\AuthKitBundle\User\UserFactoryInterface`,
  `UserWriterInterface` and `UserLookupInterface` to your adapter (see [USAGE](USAGE.md#using-it-from-a-ddd--hexagonal-application)).
  Keep the exact stored-identifier rule in `findByIdentifier()`. Password reset with such a user still needs your own
  `PasswordResetTokenManagerInterface`.
- **Domain events**: listen to `User\Event\UserRegisteredEvent` and `PasswordReset\PasswordResetCompletedEvent`
  instead of `SecurityEvents::INTERACTIVE_LOGIN` / Doctrine lifecycle events.
- **Password property not named `password`**: set `profiles.<name>.password_reset.password_field` so that a password
  change clears a pending reset credential (and `mfa.trusted_devices.password_field` for MFA trusted devices).
- **Passwords changed outside Doctrine** by your own code: dispatch `User\Event\CredentialsChangedEvent($user)` after
  your commit. AuthKit dispatches it itself for the passwords it changes through a non-Doctrine writer.
- Behaviour notes: `nowo:auth-kit:create-admin`'s "already exists" check and the MFA admin page now use the exact
  stored-identifier match (no accent-insensitive look-alike). If you construct `MfaAdminController` yourself, pass a
  `UserLookupInterface` as the new last argument.

## To 1.40.0

From **1.39.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No action required: the recovery codes pages render byte-identical to 1.39 until a host fills the new blocks.

To put icons on the recovery codes actions (UI-PATTERNS: export / save kinds), extend the page instead of copying it:

```twig
{# templates/security/mfa_recovery_codes.html.twig, set as web_ui.templates.mfa_recovery_codes #}
{% extends '@NowoAuthKitBundle/mfa/recovery_codes.html.twig' %}
{% block nowo_auth_kit_mfa_codes_actions %}<p>{{ include('@NowoAuthKitBundle/mfa/_codes_actions_kinds.html.twig') }}</p>{% endblock %}
{% block nowo_auth_kit_mfa_continue_button %}
    <button type="submit" class="nowo-ui-btn nowo-ui-btn--primary">{{ ux_icon('tabler:check', {'aria-hidden': 'true'}) }}{{ 'mfa.recovery.continue'|trans({}, 'NowoAuthKitBundle') }}</button>
{% endblock %}
```

`mfa/account_codes.html.twig` has the same `nowo_auth_kit_mfa_codes_actions` block plus `nowo_auth_kit_mfa_codes_done`
(override it empty when the actions block already draws Done). `mfa/account_codes_grid.html.twig` is unchanged.

## To 1.39.0

From **1.38.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

**1.39.0**. REQ-SEC-004: Pass (good) / Low, Appendix HU.

- **Upgrade UserKit with it:** AuthKit now conflicts with `nowo-tech/user-kit-bundle` below 1.13, because a rollback calls
  `UserSessionManager::adopt()`. Hosts without UserKit are not affected.
  A host that has to stay on UserKit 1.12 stays on AuthKit 1.38. A host on AuthKit 1.38.0 should not take UserKit 1.13
  alone: a first link in add mode loses the account just added, and a revocation waiting on the row during a failed
  sign-in is lost until the next sign-out.
- **`password_changed` runs after the commit.** A rolled-back first link no longer fires it, and with
  `revocation.store: cache` the user's other sessions now stay signed in. A listener on that event can no longer undo a
  first link: if it throws, the password stays replaced, the link stays, the user stays signed in and the visitor gets a
  500 (HU-I2).
- The 1.38 notes about that event firing before the commit no longer apply.

## To 1.38.0

From **1.37.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Planned **1.38.0**. REQ-SEC-004: Appendix HP, Pass (good) / Low.

**Behaviour changes, on failure paths only:**

- **Firewall that cannot sign in** (several authenticators and neither `form_login` nor `login_link`): social and QR
  redirect to the sign-in page instead of a 500, registration shows `register.flash_unavailable`, an invitation shows
  its "not accepted" notice and stays open. An error is logged. Nothing is written.
- **A `LoginSuccessEvent` listener throwing during social, QR or the magic link confirm:** the sign-in is undone. On social and QR a `RuntimeException` then answers like any failed attempt: QR redirects to the sign-in page instead of a 500. The magic link confirm stays a 500, with nobody signed in. The social link, a new account and the
  password replacement are rolled back. Other exceptions stay a 500, with nobody signed in. Your listener's own database
  writes on the same connection roll back too in the social flow, but what it did elsewhere does not: mail, messages to a
  non-database transport, and events. `UserSessionsRevokedEvent` (`password_changed`) fires before the commit, also for a
  change that is then rolled back, and with `revocation.store: cache` the user's other sessions stay signed out. The user
  row stays locked until your listeners return, so keep them short. A registration keeps its account and an invitation
  stays accepted. If a signed-in user's first link fails, that session ends too, because its token holds the replaced
  password.
- **On PostgreSQL, a listener catching a database error on the same connection during a social sign-in** now fails it (a
  500, nobody signed in, nothing written) instead of a success redirect whose transaction had silently rolled back. Do
  not swallow database errors there, or use a savepoint. MySQL and SQLite carry on as before.
- **`mfa/account_codes_grid.html.twig` with a `before` block** now renders its heading as an `h2`, so your block can hold
  the page's `h1`. Set `nowo_auth_kit_mfa_codes_heading` to keep another level.

**Optional:** handlers that link and then sign in should use `SocialAccountLinker::linkAndSignIn()`, because
`linkOrCreate()` writes at once. Fill `nowo_auth_kit_mfa_codes_actions` with
`{{ include('@NowoAuthKitBundle/mfa/_codes_actions_kinds.html.twig') }}` for UiKit actions, and use
`nowo_auth_kit_mfa_account_before` instead of wrapping `nowo_ui_content`. A host rule setting `list-style` on
`.nowo-auth-kit-mfa__code-grid` can go.

## To 1.37.1

From **1.37.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Planned **1.37.1**. No action needed.

Social sign-in and QR completion no longer fail with "Too many authenticators" on a firewall with several
authenticators. With several, the sign-in goes through `form_login`, else `login_link`. Firewalls with one authenticator
are unchanged. A firewall with several and neither of those two still gets Symfony's error.

**Affected:** social sign-in from 1.9.0, QR completion from 1.12.0, both through 1.37.0, only on a firewall with two or
more authenticators (remember-me does not count).

**Until you upgrade:** nothing in configuration selects the authenticator. Keep one authenticator on the firewall social
and QR run on, or set `social_login.mode` / `qr_login.mode` to `disabled` so visitors do not reach the 500. On 1.37.0,
social can also go through a `SocialCallbackHandlerInterface` that calls `SocialAccountLinker::linkOrCreate()` and then
`Security::login($user, 'form_login', $firewall)`; QR has no such hook. A failed attempt may already have linked the social account and consumed the QR challenge. With the default `link_existing_account: reset_password`, that link also replaced the local password of an existing account, so a user who tried and failed needs a password reset to use a password again. Nothing signs anyone in, and the next social attempt after upgrading works.

**Not changed:** registration and invitation sign-in still name `form_login`, so they fail on a firewall without it.

## To 1.37.0

From **1.36.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

REQ-SEC-004: Appendix GY, Pass (good) / Low. Additions are
opt-in, and pages render the same bytes when nothing is configured, with these exceptions:

**Check these (behaviour changes):**

- **`magic_login.mode: disabled` now means off.** A signed link on AuthKit's check route is refused and the confirm page
  redirects to the sign-in page. `AdminMagicLinkIssuer` throws `AdminMagicLinkRefusedException` (`feature_disabled`) and
  `canIssue()` is false. If you turned self-service off but use administrator links, set `mode: enabled` and hide the
  link another way (for example `availability_checker`). A `login_link` on your own route is unaffected.
- **Erasure runs more erasers** with UserKit installed and the entities mapped: `auth_kit_social_account` always, and
  `auth_kit_mfa` / `auth_kit_account_switcher` even with the feature off. An eraser of your own for social accounts
  keeps working (it finds nothing left), and you can drop it. Tests comparing the returned key list need the new keys.
- **A callback with a wrong `state` no longer burns the pending state.** The answer to the visitor is the same.
- **Hosts with an object id (a `Uid`):** `SocialAccountLinker` now stores the id's string. Rows stored before keep the
  identifier as `user_id` and still fail with "was not found" on every social sign-in of that user. Delete those rows;
  the user then links again.
- **Vault and MFA erasure on `_ai_ci` collations** no longer delete look-alike identifiers; a row whose identifier
  differs only by case still goes.
- **`_target_path` on the account switcher** gains an `id` attribute. The name and value are unchanged.

**New options, if you want them:**

- **MFA account pages:** set `web_ui.templates.mfa_account` / `mfa_account_codes` and move a
  `templates/bundles/NowoAuthKitBundle/mfa/account*.html.twig` override to your own path. Keep
  `{% extends '@!NowoAuthKit/mfa/account.html.twig' %}`. A bundle-path override keeps working if you leave it.
- **Recovery codes grid:** `web_ui.templates.mfa_account_codes: '@NowoAuthKitBundle/mfa/account_codes_grid.html.twig'`,
  or extend it from your template and fill `nowo_auth_kit_mfa_codes_before`. The default layout did not change.
- **Availability checkers:** replace a `kernel.request` subscriber that gates AuthKit routes with
  `magic_login.availability_checker` / `password_reset.availability_checker`. The answer is now 404, not a redirect to
  the sign-in page. Magic login gates its **request** route only: links already issued keep working. See
  [MAGIC-LOGIN.md](MAGIC-LOGIN.md#availability-checker).
- **Social callback handlers:** replace a subscriber that swaps `_controller` on the callback with a
  `SocialCallbackHandlerInterface`, and delete your own state and code handling. Call `SocialAccountLinker` if you can,
  and use `$context->verifiedEmail()` otherwise. See [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md#callback-handlers). The first
  factor of a handler's sign-in is `social`, so check `mfa.first_factors.social`.

**Only if you call these directly:** `SocialLoginCheckController::check()` now returns `Response`. Tests that compare
`Configuration::PAGE_TEMPLATES` or `AuthPageFactory::pages()` with a literal list need the two new keys.

## To 1.36.1

From **1.36.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No configuration or schema change.

- The UserKit erasure result now lists `auth_kit_mfa` for every user while the eraser is registered (at least one
  profile with `mfa.enabled`), also for users without a second factor (nothing is deleted for them).
- Host code that calls `MfaManager::eraseUser($user, $originalIdentifier)` itself must pass only the identifier
  captured from that same user before the erasure started (see [MFA](MFA.md#personal-data-userkit)).
- If you turned MFA off in every profile, the eraser is not registered: delete leftover MFA rows of erased users with
  `MfaManager::eraseUser()` in your own eraser, or keep one profile with `mfa.enabled`.

## To 1.36.0

From **1.35.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No migration. With UserKit personal data enabled (`nowo_user_kit.personal_data.enabled: true`) AuthKit now registers
two erasers at priority 200 (`auth_kit_mfa`, `auth_kit_account_switcher`) and the export section `mfa`; they run
before host erasers. Update UserKit to **≥ 1.12** as well, so the `sessions` eraser carries the original identifier
and `auth_kit_mfa` / `auth_kit_account_switcher` find the rows of identifier-keyed users after a host identity eraser
above priority 200 (with UserKit 1.11, keep host identity erasers below 200).

- Do not log `context['original_identifier']` of `UserSessionsRevokedEvent` in host audit listeners: during an
  erasure it is the erased person's e-mail.

- **Host workarounds can go:** an eraser that called `AccountVault::forgetUser()` before the identity eraser (Beacon's
  `AccountSwitcherEraser`, priority 93) or deleted the MFA rows keeps working (it finds nothing left, `forgetUser()` is
  idempotent) and can be deleted; keep a functional test that an erased account leaves the switcher.
- List the `mfa` section in the privacy text if the host documents the export sections.
- Schedule `nowo:auth-kit:accounts:purge` (nightly) if not done yet.

## To 1.35.0

From **1.34.x**:

```bash
composer update nowo-tech/auth-kit-bundle nowo-tech/ui-kit-bundle
```

UiKit **2.26** is now required (the avatar partial and URL policy). Read UiKit's UPGRADING for 2.25 / 2.26; then
`php bin/console assets:install` (UiKit's new `js/nowo-ui-avatar.js`).

Behaviour changes of the account switcher (only with `multi_account.enabled` and a presenter that returns an avatar):

- **Only the active account shows its picture.** The other accounts of the browser show their initials. To show every
  account's picture while the browser is signed in, set `multi_account.chooser.other_account_avatars: true` (the host
  must then serve each account's picture to that browser: the request carries the active session's cookie). Anonymous
  pages (sign-in page, chooser after sign-out) never show a picture.
- With `identifier_display: masked | none`, an account named by its identifier shows one initial (`A`), not two (`AE`).
- The sign-in page that lists accounts is now `Cache-Control: private, no-store`.
- **`http:` and `data:` avatar URLs now show the initials**, and so do relative paths without a leading `/`,
  protocol-relative or `http:` asset URLs (an asset package whose `base_urls` is `//cdn…` or `http://…`), IPv6-literal
  hosts and URLs with quotes, `<`, `>` or backtick. Return `https://` URLs without userinfo or a same-origin `/path`
  from your `AccountPresenterInterface`. The URL should be stable, access-checked (no bearer secret) and generated by
  the host, never entered by a user.
- The picture goes over the initials (UiKit's `partials/_avatar.html.twig`), and a broken one falls back to them when
  the page loads UiKit's `scripts` (`{{ include(nowo_ui_partial('scripts')) }}`, or `js/nowo-ui-avatar.js` in a hand-
  written list).
- A full-file override of `accounts/_switcher.html.twig` keeps its own markup: compare it with the new avatar include.

## To 1.34.0

From **1.33.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No action unless you enable MFA or the account switcher (`mfa.enabled` / `multi_account.enabled` stay `false`: no
service, route, cookie or listener). Notes:

- **Schema:** the MFA entities live in `src/Entity`, so with the default `doctrine.register_mapping: true` your next
  `doctrine:migrations:diff` adds `auth_kit_mfa`, `auth_kit_mfa_recovery_code`, `auth_kit_mfa_trusted_device` and
  `auth_kit_account_vault` (empty); `register_mapping: auto` keeps them out until a profile enables a feature. `register_mapping: false` with
  `mfa.enabled: true` is refused.
- The profile configuration (`nowo_auth_kit.profiles` parameter) gains an `mfa` node and three `web_ui.templates`
  keys.

To enable MFA ([MFA.md](MFA.md)):

1. Set `mfa.enabled: true` and `mfa.issuer`; register `nowo-tech/doctrine-encrypt-bundle` (the secret is
   `#[Encrypted]`) and `endroid/qr-code` (QR code); keep form CSRF protection on.
2. Generate and run the migration.
3. `access_control`: `/login/mfa` reachable like the login page (`PUBLIC_ACCESS`), `/account/security/mfa` for
   `ROLE_USER`.
4. Host `LoginSuccessEvent` listeners that must not run for a half-authenticated user: skip
   `$event->getAuthenticatedToken() instanceof MfaPendingToken`, or listen to `MfaLoginCompletedEvent`.
5. With UserKit sessions, use UserKit ≥ 1.11 (pending sign-ins are registered once complete).
6. `composer require symfony/lock` (MFA refuses to compile without `lock.factory`; on several hosts use a shared
   store). If your user entity keeps its password hash in another field than `password`, set
   `mfa.trusted_devices.password_field`. Setting up the second factor needs a sign-in within `mfa.recent_sign_in`.

To enable several accounts at once ([MULTI-ACCOUNT.md](MULTI-ACCOUNT.md)): UserKit ≥ 1.11 with sessions on (≥ 1.11.1
recommended: a revoked parked session stays revoked after the retention purge), the PHP `sodium` extension,
`multi_account.enabled: true`, the migration (table `auth_kit_account_vault`), `/accounts` reachable anonymously
(`nowo:auth-kit:configure-security` adds the rules), and the switcher partial in your user menu. The normal sign-out
then ends every account of the browser (`logout_scope: all`). Behind a TLS-terminating proxy without
`trusted_proxies`, set `multi_account.cookie.secure: true`.

## To 1.33.1

From **1.33.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

From **1.33.0** (security patch, no schema change):

- `invitations.merge_key`: a boolean or float key value now throws `InvalidArgumentException` (use the typed id, `int` or `string`). On a merge, the stored key value is kept (`7` stays `7` when re-invited with `'7'`).
- `show_link_when_undelivered`: the link shown once is refused (`not_delivered`) to a `null` actor and when a host `InvitationActionAuthorizerInterface` refuses `return_link`; authorizers are asked for `return_link` before the send when the option is on.
- `TOO_MANY_OPEN` only counts unexpired open invitations of the e-mail. `InvitationRepository::findOpenByEmailAndPayloadKey()` takes an optional `$now`.

## To 1.33.0

From **1.32.2**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No action required: every 1.33 invitation addition is opt-in and the defaults keep 1.32 behaviour
(`invitations.registration_role: null`, `merge_key: null`, `payload_merge: replace`, `admin_routes: true`; `resend()`
and `InvitationRequest` gain optional trailing arguments). Notes:

- **Schema:** `auth_kit_invitation` gains a nullable `last_sent_by` column (who issued the current link). Generate
  and run a migration (`doctrine:migrations:diff`) if invitations are enabled.
- A listener of `InvitationAcceptingEvent` that calls `refuse()` or throws blocks acceptances (nothing is created).
- `returnLink` needs a non-null actor. Hosts acting on invitations without roles must implement
  `InvitationActionAuthorizerInterface` (AuthKit's role rule is always true for them).
- `merge_key` values compare as strings (`7` ≡ `'7'`); non-scalar values throw; more than 500 open invitations of
  one e-mail answer `InvitationOutcome::TOO_MANY_OPEN`.
- Hosts reading `_nowo_auth_kit_invitation.<profile>` from the session should move to
  `InvitationAcceptor::pending()` / `acceptPendingForSocial()` (the key itself is unchanged).
- `invitations.routes.accept.path` must now end with `/{token}` (the default does; other paths could not work).
- With `payload_merge: deep` a later scalar wins (a re-invite can downgrade a role kept in the payload).
- With `admin_routes: false`, remove links to `nowo_auth_kit_invitations*` routes from your menus.
- With `merge_key`, several open invitations can exist for one e-mail; once one is accepted the others can no longer
  create the (same) account — revoke them or handle existing accounts in your own flow.

## To 1.32.2

From **1.32.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

From **1.32.1**: no action unless your firewall no longer intercepts the logout URL (HTTP 500 "reached
LogoutController" / "intercepted by the logout key").

### Logout with locale.in_path (1.31.2+)

Up to **1.31.1** the AuthKit controllers were also autoconfigured, so on Symfony 7.3+ the recipe import
`controllers: { resource: routing.controllers }` registered their `#[Route]` attributes a second time and
`nowo_auth_kit_logout` ended up as the bare `/logout`. **1.31.2** fixed that (the `nowo_auth_kit` route loader is the
only import): with `locale.in_path: both` the canonical `nowo_auth_kit_logout` is `/{_locale}/logout` and the bare
`/logout` is `nowo_auth_kit_logout_unlocalized` (`locale.unlocalized_route_suffix`). The firewall `logout.path` is a
route name and matches **only that route**: with `logout.path: nowo_auth_kit_logout` and `unlocalized: serve`, a
request to `/logout` is served by `LogoutController`, which throws.

Firewall `logout.path` per mode (what `nowo:auth-kit:configure-security --force` writes since this release):

| `locale.in_path` | `locale.unlocalized` | `logout.path` | Intercepted |
|------------------|----------------------|---------------|-------------|
| `never` | — | `nowo_auth_kit_logout` | `/logout` |
| `always` | — | `nowo_auth_kit_logout` | `/{_locale}/logout` |
| `both` | `redirect` | `nowo_auth_kit_logout` | `/{_locale}/logout`; `/logout` redirects there with its query (CSRF token) |
| `both` | `serve` | `nowo_auth_kit_logout_unlocalized` (`<name><unlocalized_route_suffix>`) | `/logout`; `/{_locale}/logout` only with `localized_logout_redirect: true` |

With `both` + `serve`, enable the opt-in redirect so `/{_locale}/logout` (e.g. the embed widget link, which carries the
request locale) reaches the bare URL with its query string:

```yaml
nowo_auth_kit:
    locale:
        in_path: both
        unlocalized: serve
        localized_logout_redirect: true   # default false
security:
    firewalls:
        main:
            logout:
                path: nowo_auth_kit_logout_unlocalized
```

`LocalizedLogoutRedirectSubscriber` (`kernel.request`, priority `9`: after the router, before the firewall) answers
302 (307 for a POST logout) to the bare route generated by the router, only for the logout route of each profile and
only with `in_path: both` + `unlocalized: serve`. A host listener doing the same (e.g. symfony-beacon) can be removed
once this option is on. Hosts on `never`, `always` or `both` + `redirect` keep `logout.path: nowo_auth_kit_logout`.

`nowo:auth-kit:configure-security` without `--force` leaves an existing firewall alone but now warns when its
`logout.path` differs from the route above.

## To 1.32.1

From **1.32.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.32.0

From **1.31.2**:

```bash
composer update nowo-tech/auth-kit-bundle
```

From **1.31.2**: no action unless you enable the new invitations (`invitations.mode` stays `disabled`: no route, no
page, no command). `nowo-tech/form-kit-bundle` must be `^2.14`.

**Schema:** the `Invitation` entity lives in `src/Entity` with the other AuthKit entities, so with the default
`doctrine.register_mapping: true` it is mapped even while invitations are disabled, and your next
`doctrine:migrations:diff` adds the `auth_kit_invitation` table (as for the social / QR entities). To keep it out,
set `doctrine.register_mapping: auto` (mapped only when social login, QR login or invitations are enabled).

To use invitations ([INVITATIONS.md](INVITATIONS.md)):

1. Set `absolute_url.base_uri` and `invitations.mode: enabled` (optionally `registration_mode: invite_only`).
2. Create the table: `php bin/console doctrine:migrations:diff` then migrate (`auth_kit_invitation`; with
   `doctrine.register_mapping: auto` the AuthKit entities are mapped once invitations are enabled).
3. Add `access_control` for `^/admin/invitations` and, if wanted, install `symfony/mailer`.
4. Schedule `nowo:auth-kit:invitations:purge`.

`RegistrationMode` has a new case `InviteOnly` (`invite_only`): host code that `match`es every case of the enum needs
an arm for it.

## To 1.31.2

From **1.31.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

No action needed. `QrLoginDenyType`, `QrLoginRetryType` and `LogoutFormType` are now FormKit Types bound to the
`auth_kit` profile (REQ-TWIG-005 rule 11); their names, fields, CSRF settings and rendered markup are unchanged.
`nowo-tech/form-kit-bundle` was already a required dependency; override the forms' FormKit options through
`nowo_form_kit.profiles.auth_kit` (see [CONFIGURATION.md](CONFIGURATION.md#formkit-profile-auth_kit)).

## To 1.31.1

From **1.31.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.31.0

From **1.30.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

Security release (SEC-004 2026-10-27). Hosts pinned to 1.30.0 (e.g. core-nowo-v2) should read every item.

### Security hardening (behaviour changes)

1. **Notifiers get the stored identifier.** `MagicLoginNotificationContext::$identifier` and
   `PasswordResetNotificationContext::$identifier` now hold the identifier **stored on the account**, not the value typed
   in the form (they could differ in case or accents), and both contexts expose the account as `$user`. A notifier that
   mails `$context->identifier` needs no change and is now safe; a notifier that read the address from the request must
   switch to `$context->identifier` or to the e-mail of `$context->user`. Never mail a value taken from the request.
2. **Identifier lookups are exact.** Reset, magic login, social e-mail matching and QR completion accept an account only
   when its stored identifier equals the input after Unicode NFC and lower-casing. With an `email` identifier the
   request forms reject non-ASCII addresses (`validation.identifier_email`). Hosts storing identifiers with leading or
   trailing spaces must clean them up.
3. **Social login: `create_user_if_missing` defaults to `false`** and, when `true`, follows `registration_mode` (no
   sign-up while registration is disabled or `first_user_only` is used up). Set it explicitly to keep social sign-up:

   ```yaml
   nowo_auth_kit:
       social_login:
           create_user_if_missing: true
   ```
4. **Social login: linking to an existing account** (`social_login.link_existing_account`, default `reset_password`).
   The first social sign-in whose verified e-mail matches an existing local account now replaces that account's
   password with a random one (other sessions end, pending reset credentials are cleared): someone who registered the
   address before its owner loses access. The user keeps signing in with the provider, or resets the password. Use
   `deny` to refuse the link (sign in locally), or `keep` for the pre-1.31 behaviour only if the application verifies
   e-mail ownership at registration.
5. **Social login schema change (several profiles).** Links are scoped to the user class. Update the unique index of
   `auth_kit_social_account` (prefix the table name with `doctrine.table_prefix` if you use one):

   ```sql
   -- MySQL / MariaDB
   DROP INDEX uniq_auth_kit_social_account_provider_subject ON auth_kit_social_account;
   CREATE UNIQUE INDEX uniq_auth_kit_social_account_provider_subject_class ON auth_kit_social_account (provider, provider_user_id, user_class);
   -- PostgreSQL / SQLite
   DROP INDEX uniq_auth_kit_social_account_provider_subject;
   CREATE UNIQUE INDEX uniq_auth_kit_social_account_provider_subject_class ON auth_kit_social_account (provider, provider_user_id, user_class);
   ```

   or generate it: `php bin/console doctrine:migrations:diff` (or `doctrine:schema:update --dump-sql` to review).
   Existing rows stay valid. Single-profile hosts keep working without the migration (one user class); with several
   profiles, a provider subject already linked for another user class can only be linked for a second class after the
   migration (until then the insert fails instead of signing into the wrong account). `findOneByProviderSubject()`
   keeps its 1.30 behaviour when called without the new `$userClass` argument.
6. **Minimum password length 12** (was 6) on registration and password reset when `password_strength` is off. Keep
   the old floor only if your policy requires it (minimum 8):

   ```yaml
   nowo_auth_kit:
       password_policy:
           min_length: 8
           not_compromised: true   # optional, Have I Been Pwned range API
   ```
7. **Reset codes:** the `max_code_attempts` budget belongs to the account (spelling variants share it) and a client IP
   may submit `password_reset.code_rate_limit` codes (default 10) per `code_rate_window` (900 s).
8. **`configure-security` output:** `login_link.signature_properties` is `[<identifier>, password]` (a password change
   voids pending links) and `login_link.lifetime` is `max(magic_login.lifetime, magic_login.admin_link.lifetime)`
   (3600 with defaults). Re-run `php bin/console nowo:auth-kit:configure-security` or edit `security.yaml` by hand.
   Links mailed before the change stop working once.
9. **Per-firewall login links and QR profile binding.** Magic links are signed and consumed by the login link handler
   of the profile's firewall (`security.authenticator.login_link_handler.<firewall>`), no longer always `main`; each
   firewall serving a profile with magic login needs its own `login_link`. A QR challenge completes only through the
   profile that created it and for an account of that profile's user class.
10. **CSRF is mandatory for QR login and the magic-login confirm interstitial.** The container no longer compiles when
    one of them is enabled without `framework.csrf_protection` / `framework.form.csrf_protection`;
    `symfony/security-csrf` is now a dependency.
11. **Registration auto-login** uses `Security::login($user, 'form_login', <firewall>)`: the user checker,
    `LoginSuccessEvent` listeners (e.g. login throttling) and the session strategy run. The firewall of the profile
    must declare `form_login`. A user checker that rejects the new account sends it to the login page.
12. **Pending reset credentials** are cleared when the identifier or password changes outside the reset flow (Doctrine
    `onFlush` listener on the profile user classes).
13. **Locales in generated paths** are restricted to `locale.enabled`; other request locales fall back to the default.

### Translation domain and routes

No configuration change is required for these two:

1. **Constraint messages use the `NowoAuthKitBundle` domain** (REQ-I18N-003). The bundle no longer ships
   `validators.<locale>.yaml`; the AuthKit form constraint messages (`register.password.*`, `reset.*.*_required`,
   `reset.code.code_length`, `magic_login.request.identifier_required`, …) are translated from the
   `NowoAuthKitBundle` catalogues, which always held the same texts. Rendered messages are unchanged.
   - If you override those keys in `translations/validators.<locale>.yaml`, your override still wins (BC); move it to
     `translations/NowoAuthKitBundle.<locale>.yaml` when convenient.
   - Code reading `FormError::getMessage()` of an AuthKit form gets the translated text, as before; the error is
     rebuilt after validation (same template, parameters, plural, cause and origin).
2. **Controllers declare `#[Route]`** (REQ-SF-004). Route names, paths, methods and requirements are unchanged; keep
   importing `@NowoAuthKitBundle/Resources/config/routing.yaml`. Do not import the bundle `src/Controller/`
   directory as `type: attribute` (those copies would have no profile). `resource: routing.controllers`
   (Symfony 7.3+ recipe) does not pick them up: the controller services are not autoconfigured (no `routing.controller` tag).

## To 1.30.0

From **1.29.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

- No breaking change: every new option is opt-in (see [CHANGELOG](CHANGELOG.md#1300---2026-10-23)).
- `PasswordResetRequestHandler::handle()` now returns `PasswordResetRequestOutcome` instead of `void`; callers that
  ignore the return value are unaffected.
- `ResetPasswordController::reset()` takes `?string $token = null` (null only with `password_reset.token_in_session`).
- Custom `_authenticated.html.twig` embed overrides: render `logout_form` (when not null) if you set
  `routes.logout.methods: [POST]`; the GET link would answer 405.
- Hosts that previously aliased the bare routes (e.g. `<route>.default` → `<route>_unlocalized`) can drop the aliases
  with `locale.unlocalized_route_suffix: .default`; hosts that kept an app-side POST logout route can use
  `routes.logout.methods: [POST]`; hosts with an additive migration for the `auth_kit_*` tables can set
  `doctrine.register_mapping: auto` and drop those tables in a migration.

## To 1.29.0

- No breaking change. To issue support sign-in links from an administration screen, inject
  `Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer` (see [MAGIC-LOGIN.md](MAGIC-LOGIN.md#administrator-issued-links)).
- When you use it, set the firewall `login_link.lifetime` to at least `magic_login.admin_link.lifetime` (default 3600)
  and add `password` to `signature_properties`.

## To 1.28.0

From **1.27.3**:

```bash
composer update nowo-tech/auth-kit-bundle
```

```bash
composer update nowo-tech/auth-kit-bundle
```

- **Password toggle:** `nowo-tech/password-toggle-bundle` older than **2.0.3** is now a Composer conflict (the auth
  pages load its stylesheet through the `nowo_password_toggle` asset package it registers). Update it to `^2.0.3`.
- **Social login timeouts:** if you overrode the `$timeout` / `$maxDuration` arguments of
  `Nowo\AuthKitBundle\SocialLogin\OAuth2Client` in your service configuration, move them to config:

  ```yaml
  nowo_auth_kit:
      http_client:
          timeout: 10
          max_duration: 20
  ```

- **Table prefix (optional):** `nowo_auth_kit.doctrine.table_prefix` renames the AuthKit tables
  (`app_auth_kit_qr_login_challenge`, …). Setting it on an existing install needs a migration that renames the tables.
- **Overridden `security/qr_login_approve.html.twig`:** the Deny button now uses the `deny_form` variable
  (`QrLoginDenyType`, rendered with `form_start(deny_form, {action: …})` / `form_end(deny_form)`). Old overrides that
  still print a raw `<form>` keep working (`deny_url`, `deny_route`, `approve_token` are unchanged).

## To 1.27.3

From **1.27.2**:

```bash
composer update nowo-tech/auth-kit-bundle
```

- **`flash_trans`** now translates `TranslatableInterface` flashes instead of returning the object. Host templates
  that worked around it (`message.message is defined ? message|trans : message|flash_trans`) can go back to
  `message|flash_trans`; the workaround keeps working.

## To 1.27.2

From **1.27.1**:

```bash
composer update nowo-tech/auth-kit-bundle
```

- **Per-page overrides**: extend the bundle original with `{% extends '@!NowoAuthKit/security/<page>.html.twig' %}`.
  The `@!NowoAuthKitBundle` namespace shown in earlier docs is not registered; replace it if you copied it.
- **QR challenge + Stimulus** (`web_ui.qr_login.stimulus_controller`): `data-controller="<id>"` and the
  `data-<id>-{status-url,complete-url,start-url,poll-interval,expires-at}-value` attributes moved from the challenge
  root `[data-nowo-auth-kit-qr]` to the auth panel (`section[data-testid="auth-kit-qr_login_show"]`), so the
  `continue` / `retry` targets are now found. A controller that read `this.element.dataset.nowoAuthKitQrState` or
  other challenge-root attributes should read them from the root, e.g. a `data-<id>-target` region's
  `closest('[data-nowo-auth-kit-qr]')`, or through its values. The bundled script (no controller) is unchanged.
- **QR approve link**: the "Open approve link on your phone" link is now also rendered when a QR image is shown
  (`[data-nowo-auth-kit-qr-region="link"]`); hide `.nowo-auth-kit-qr__link` in host CSS to keep the old look.
- **Password reset request**: with `delivery: code` or `both` the page shows an "I already have a code" link to the
  code page (`reset.request.enter_code`).

## To 1.27.1

From **1.27.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.27.0

From **1.26.0**:

```bash
composer update nowo-tech/auth-kit-bundle
```

### Template contract (1.27)

The auth pages follow the **UiKit 2.x page contract** (REQ-UI-003) and require **`nowo-tech/ui-kit-bundle` ^2.2**:
register `Nowo\UiKitBundle\NowoUiKitBundle` in `config/bundles.php` (Flex does it) and run `assets:install`. Hosts
that only configured `nowo_auth_kit` need no template work; check the points below.

**Pages → scaffold** ([TEMPLATES.md](TEMPLATES.md)): every page (`login`, `register`, `reset_request`,
`reset_password`, `reset_password_code`, `magic_login_request`, `magic_login_confirm`, `qr_login_show`,
`qr_login_approve`) extends `@NowoUiKit/page/auth.html.twig` / `AuthPage`; data blocks `nowo_ui_auth_fields`,
`nowo_ui_auth_actions`, `nowo_ui_auth_footer`.

**What hosts may notice**

1. **Layout.** Pages render in `nowo_ui_kit.layouts.guest` (default `@NowoUiKit/layout/guest.html.twig`, which loads
   `nowo-ui.css` but no CSS framework). Point `nowo_ui_kit.layouts.guest` (or `web_ui.layout_template` per profile) at
   a layout that loads your CSS and renders the canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`,
   `nowo_ui_styles`, `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`,
   `nowo_ui_scripts`); the demo's `templates/layouts/guest.html.twig` is a one-file bridge. A 1.x layout set as
   `templates.layout` keeps working (it renders `auth_panel`, which every page maps to the scaffold content) but gets
   none of the UiKit assets; `templates.layout: '@NowoAuthKitBundle/layout.html.twig'` now means the UiKit guest layout.
2. **Overrides.** Delete forks under `templates/bundles/NowoAuthKitBundle/security/`:
   - reset password forks that only moved the confirmation field before the button — fixed upstream;
   - `qr_login_show` forks for Stimulus values / countdown / retry — use `web_ui.qr_login.stimulus_controller`
     (values + targets on the challenge) or a thin `web_ui.templates.qr_login_show` extending
     `@!NowoAuthKit/security/qr_login_show.html.twig` (blocks `nowo_auth_kit_qr_*`);
   - `layout.html.twig` forks that changed the base layout — set `nowo_ui_kit.layouts.guest`;
   - `_device_intelligence_assets.html.twig` forks for the CSP nonce — not needed (nonce on every script since 1.22.3,
     external scripts too now).
   1.x page forks (`{% extends layout_template %}` + `auth_panel`) still render: `layout_template` is the deprecated
   bridge layout unless `web_ui.layout_template` is set.
3. **Markup.** Panel, heading, intro, error, form frame, buttons and footer links are UiKit markup (`nowo-ui-*` +
   theme slots). CSS or tests targeting `.nowo-auth-kit__panel`, `.nowo-auth-kit__button`, `.nowo-auth-kit__footer`,
   `.nowo-auth-kit__alert--error`, `.nowo-auth-kit__qr-*` or the Bootstrap embed classes should use
   `[data-testid="auth-kit-<page>"]`, `[data-nowo-ui-page="auth"]`, `[data-nowo-ui-region="form"]`, the theme slots
   (`auth_panel`, `btn_primary`, `flash_error`, …) and `[data-nowo-auth-kit-qr]`.
4. **Forms.** Default form themes are the UiKit form theme + the AuthKit PasswordToggle theme (UiKit `input` slot);
   the seeded FormKit `auth_kit` profile no longer adds `form-control` / `form-check-input` / `mb-2`. Bootstrap hosts
   get the Bootstrap classes from the slot defaults (`css_framework: bootstrap5`); set `web_ui.form_themes` to keep
   e.g. `bootstrap_5_layout.html.twig`.
5. **Embedded widget.** `auth_kit_dropdown()` is a `<details>` disclosure styled by `nowo-auth-kit.css` + slots (no
   Bootstrap dropdown JS); its register / login tabs use the UiKit tabs runtime — load the UiKit `scripts` partial in
   the host layout (`{{ include(nowo_ui_partial('scripts')) }}`) and `nowo-auth-kit.css` on pages that show it.
6. **Validation messages** are translated through the bundle's new `validators` catalogues (they were raw keys).

**Deprecated configuration (removed in 2.0)**

| 1.x key (per profile) | Replacement |
| --- | --- |
| `templates.layout` | `web_ui.layout_template` (null = `nowo_ui_kit.layouts.guest`) |
| `templates.<page>` | `web_ui.templates.<page>` |
| `templates.form_theme` | `web_ui.form_themes` |
| `css.button_class` | `nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary` (bridged) |
| `css.secondary_button_class` | `nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_secondary` (bridged) |

| 1.x template / block / global | Replacement |
| --- | --- |
| `@NowoAuthKitBundle/layout.html.twig` | `nowo_ui_kit.layouts.guest` |
| `auth_panel_heading` | `page.title` (`AuthPage`), or override the UiKit auth scaffold |
| `auth_brand` | `web_ui.brand` (`AuthPage::brand`) |
| `title` | `nowo_ui_document_title` |
| `nowo_auth_kit_button_class` / `nowo_auth_kit_secondary_button_class` | `nowo_ui_class('btn_primary' / 'btn_secondary', 'NowoAuthKitBundle')` |

## To 1.26.0

## To 1.25.0

From **1.24.1** — security - PKCE for social login, lock-serialized attempt limiter.

```bash
composer update nowo-tech/auth-kit-bundle
```

- **Social login PKCE:** `google`, `github` and `microsoft` credentials now send `code_challenge` (S256) on the authorize redirect and `code_verifier` on the token exchange. All three accept PKCE for confidential web clients, so no IdP-side change is needed. To keep the old behaviour for a provider set `social_login.pkce: { github: false }`; to enable PKCE for a custom IdP set `social_login.pkce: { my_idp: true }`. A login started before the upgrade (no verifier in the session) still completes without PKCE.
- **Rate-limit locking (optional):** install `symfony/lock` to make `AuthKitAttemptLimiter` counters concurrency-safe (FrameworkBundle registers `lock.factory` automatically). Use a shared lock store (Redis, PDO, …) when several hosts share `cache.app`.

## To 1.24.1

From **1.24.0** — security fixes - QR completion expiry, social login fallback route, provider errors, locale redirect query.

```bash
composer update nowo-tech/auth-kit-bundle
```

- QR login completion after the challenge has expired now redirects to login instead of authenticating.
- After a social login without `login_success_route`, users land on the login route instead of the hard-coded `homepage` route.

## To 1.24.0

From **1.23.x** — minor release, no required changes.

```bash
composer update nowo-tech/auth-kit-bundle
```

**Recommended (security):** pin the origin of mailed / QR links so a forged `Host` header cannot poison password-reset or magic links:

```yaml
nowo_auth_kit:
    absolute_url:
        base_uri: '%env(DEFAULT_URI)%'   # https://app.example.com (optional :port and /path prefix)
```

With it set, reset links, magic links, the QR approve URL and the social-login OAuth `redirect_uri` use that scheme/host/port/path instead of the request. If you use social login, make sure the callback URL registered at each provider is `{base_uri}` + the `social_login_check` path (it normally already is). Without the option nothing changes; then configure `framework.trusted_hosts` in production.

`AuthKitUrlGenerator` and `MagicLoginRequestHandler` gained an optional trailing constructor argument (`?AbsoluteUrlGenerator`); code that instantiates them by hand keeps working.

## To 1.23.1

From **1.23.0** — configuration merge fix only.

```bash
composer update nowo-tech/auth-kit-bundle
```

No action required. A partial `nowo_auth_kit` block loaded after the main file (`config/packages/prod/*.yaml`, `when@prod:`, a bundle prepend) no longer resets `locale` (`in_path`, `default`, `enabled`, `unlocalized`) or `default_profile`. If you repeated the full `locale` node in every env file only to work around that, you can drop the copies. `default_profile` still falls back to the first profile key when it is not set in any block.

## To 1.23.0

From **1.22.x** — minor release, no required changes.

```bash
composer update nowo-tech/auth-kit-bundle
```

**Flash messages are now translated before they are stored** (`nowo_auth_kit.flash.translate: true`). Templates that print `{{ message }}` now show text instead of `magic_login.request.flash_sent`; templates that already did `{{ message|trans({}, 'NowoAuthKitBundle') }}` keep working (translating an already translated sentence returns it unchanged). If a host test asserts the raw flash key, assert the translated text or set:

```yaml
nowo_auth_kit:
    flash:
        translate: false
```

Hosts that wrote their own `flash_trans` filter (messages → NowoAuthKitBundle) can delete it and the template override that used it: AuthKit now registers `flash_trans` (domains in `flash.twig_filter_domains`). Set `flash.twig_filter: false` if your app must keep its own filter of the same name.

**Recipient-locale notifications** (`recipient_locale.enabled: true`) change nothing until your user entity implements `Nowo\AuthKitBundle\Locale\PreferredLocaleAwareInterface` (or you set `recipient_locale.resolver`). Then custom notifiers receive `$context->recipientLocale` and an already localized `resetUrl` / `loginUrl`, and run with the translator switched to that locale — drop any link-prefix rewriting or explicit locale lookups in your notifiers. The locale must be listed in `nowo_auth_kit.locale.enabled`.

**Admin CLI:** `php bin/console nowo:auth-kit:create-admin admin@example.com` replaces app-specific "create admin" commands. Listen to `Nowo\AuthKitBundle\Admin\AdminUserCreatedEvent` for follow-up work (e.g. marking setup completed) and to `AdminUserCreatingEvent` to fill required entity fields before the flush. Configure `create_admin.min_password_length` / `create_admin.roles` if the defaults (12, `[ROLE_ADMIN]`) do not fit.

## To 1.22.4

From **1.22.3** — route loader fix only.

```bash
composer update nowo-tech/auth-kit-bundle
```

No action required. Hosts that wrapped or decorated `AuthKitRouteLoader` to swallow the “already loaded” exception can drop that workaround.

## To 1.22.3

No action required. Inline `<script>` blocks in the bundle templates now emit `nonce` from the request attribute `csp_nonce` (see [Security — Content Security Policy](SECURITY.md#content-security-policy-csp)). If you overrode `_device_intelligence_assets.html.twig` or `_registration_submit.html.twig` **only** to add the nonce, you can delete those overrides.

## To 1.22.2

From **1.22.1** — maintenance release (dependencies, demo, tests).

```bash
composer update nowo-tech/auth-kit-bundle
```

No breaking changes. No application upgrade steps.

## To 1.22.0

From **1.21.1** — QR approve CSRF always on; Doctrine `SortDirection`.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

- Ensure `doctrine/orm` is `^3.7` (SortDirection).
- QR approve always posts through `QrLoginApproveType` CSRF; no host action if you already used the bundle form. Custom approve endpoints must keep Form CSRF.

## To 1.21.1

From **1.21.0** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## To 1.21.0

From **1.20.1** — FrankenPHP worker hardening (scenario B: kernel not reset). Clear cache after upgrade.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No configuration keys required. Behaviour changes to be aware of:

- `QrLoginChallengeManager::approve()`, `deny()` and `consume()` throw `Nowo\AuthKitBundle\QrLogin\QrLoginChallengeConflictException` when the challenge was already resolved by another request. Code that calls these methods outside the bundle controllers should catch it.
- `QrLoginChallengeManager::find()` now calls `QrLoginChallengeRepository::findFresh()` instead of `find()`; if you replaced the repository, add that method.
- A duplicate user on registration (unique constraint violation) now raises a `RuntimeException` from `UserRegistrar::register()`; `RegisterController` redirects to the login route instead of returning HTTP 500.
- `auth_kit_dropdown` renders the guest panel on pages that are not behind a firewall with `security: true`, even when a token is present in the token storage.
- OAuth token/userinfo HTTP calls time out after 10 s idle / 20 s total. Override the `$timeout` and `$maxDuration` arguments of `Nowo\AuthKitBundle\SocialLogin\OAuth2Client` in your service configuration to change them.
- Social credential/account lookups and magic/password-reset user resolvers refresh managed Doctrine entities so worker mode without kernel reset does not reuse stale rows. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

Recommended in worker mode: shared `cache.app` (not in-memory `ArrayAdapter`); Auth Kit routes behind a stateful firewall. Keeping Symfony `services_resetter` enabled remains a good idea but the bundle no longer depends on it for its own security decisions.

## To 1.20.1

From **1.20.0** — No breaking changes. **No application upgrade steps.** Templates that call SlideToConfirm Twig helpers without the optional package installed no longer fail to compile.

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.20.0

From **1.19.0** — optional **OTP input** (`nowo-tech/otp-input-bundle`, not installed by default). Skip this section unless you want the multi-box code field on password-reset completion.

```bash
composer require nowo-tech/otp-input-bundle
php bin/console assets:install
```

```yaml
nowo_auth_kit:
    otp_input:
        enabled: true
        password_reset_code: true
```

No change unless you enable it. Login forms are unchanged. The widget is entry UX for the reset code, not a replacement for server OTP verification (`hash_equals`, `max_code_attempts`).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.19.0

From **1.18.0** — optional **device intelligence** (`nowo-tech/device-intelligence-bundle`). Skip this section unless you want device observation on AuthKit pages. Requires **PHP 8.3+**. The package is **not** a Composer `require` / `require-dev` of AuthKit.

```bash
composer require nowo-tech/device-intelligence-bundle
php bin/console assets:install
```

```yaml
nowo_auth_kit:
    device_intelligence:
        enabled: true
        collect_on_auth_pages: true
        collect_endpoint: /_device/collect
        new_device_notify: true
        device_rate_limit: true
        qr_login:
            approve_require_trusted: true
```

Implement `NewDeviceLoginNotifierInterface` (mail/SMS) if `new_device_notify` is true; the default is a no-op. Device ID is **not** a credential: login, CSRF, LoginThrottle, and remember-me stay unchanged. AuthKit never auto-`trust()`s a device after login. QR `approve_require_trusted` skips default `NullQrLoginStepUp`; a custom `QrLoginStepUpInterface` still runs after the trusted-device check.

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.18.0

From **1.17.5** — optional **slide-to-confirm** (`nowo-tech/slide-to-confirm-bundle`, not installed by default). Skip this section unless you want the gesture.

```bash
composer require nowo-tech/slide-to-confirm-bundle
php bin/console assets:install
```

```yaml
nowo_auth_kit:
    slide_to_confirm:
        enabled: true
        registration_consent: gate
        qr_login_approve: danger
    registration_fields:
        - email
        - password
        - terms:
            type: checkbox
            slide_to_confirm: true
```

No change unless you enable it. Login forms are unchanged. The swipe is confirmation UX, not authorization. Existing checkbox / QR submit behaviour is the default.

```bash
composer update nowo-tech/auth-kit-bundle
```

## From 1.17.4 to 1.17.5

No application upgrade steps. **Demos:** MySQL env policy (REQ-DEMO-011). **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.4

From **1.17.3** — Flex `when@prod` sets `nowo_auth_kit.login_throttle_required: true`. Container compilation **fails** if `NowoLoginThrottleBundle` is not registered.

1. New Flex installs already require `nowo-tech/login-throttle-bundle` ^3.1 (since **1.17.3**). Run:

```bash
composer require nowo-tech/login-throttle-bundle:^3.1
php bin/console nowo:login-throttle:configure-security
php bin/console cache:clear --env=prod
```

2. Existing apps that **do not** merge the updated recipe keep `login_throttle_required: false` (default) and still boot — install the throttle bundle anyway for production login forms.
3. Local demos without the throttle bundle may set `login_throttle_required: false` (not for production).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.3

From **1.17.2** — Flex recipe now requires Login Throttle Bundle for new installs. Apps installed before 1.17.3 are **not** broken, but production login forms should add:

```bash
composer require nowo-tech/login-throttle-bundle:^3.1
php bin/console nowo:login-throttle:configure-security
```

See [INSTALLATION.md](INSTALLATION.md) and [SECURITY.md](SECURITY.md).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.2

From **1.17.1** — no application upgrade steps. Spec Kit baseline inventory and user stories catch up with features already shipped in 1.17.x (QR login, social/enterprise SSO, magic login, outbound-mail gate).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.1

From **1.17.0** — No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.17.0

### Magic login confirm Form CSRF (from 1.16.x)

Breaking for hosts that override `magic_login_confirm` with a raw POST to `magic_login_check`:

- Confirm POST is now **`magic_login_confirm`** (`/magic-login/confirm` by default), not `magic_login_check`.
- Template must render `magic_login_confirm_form` via `form_start` / `form_end` (CSRF enabled). BC vars `action` / `params` remain available.
- Re-run `php bin/console nowo:auth-kit:configure-security` so `access_control` includes the confirm path.
- Wire `LoginLinkHandler` if your firewall is not `main` (same as `MagicLoginRequestHandler`):

```yaml
# config/services.yaml
Nowo\AuthKitBundle\Controller\MagicLoginConfirmController:
    arguments:
        $loginLinkHandler: '@security.authenticator.login_link_handler.<firewall>'
```

`login_link.check_post_only: true` remains required so the email GET does not authenticate.

## To 1.16.0

From **1.15.x** — no breaking changes. Optional: enable a confirm interstitial for `login_link.check_post_only` hosts:

```yaml
nowo_auth_kit:
    profiles:
        default:
            magic_login:
                mode: enabled
                confirm_interstitial: true
            templates:
                magic_login_confirm: '@NowoAuthKitBundle/security/magic_login_confirm.html.twig'
```

Then set `security.firewalls.<name>.login_link.check_post_only: true` (or re-run `php bin/console nowo:auth-kit:configure-security`). Hosts that previously decorated `AuthKitRouteLoader` for GET+POST magic-login check can remove that decorator.

```bash
composer require nowo-tech/auth-kit-bundle:^1.16
php bin/console cache:clear
```

## To 1.13.1

From **1.13.0** — no host upgrade steps. Optional: `composer update nowo-tech/auth-kit-bundle` to pick up the Form service wiring fix.

## To 1.13.0

From **1.12.2** — adds required **FormKitBundle** and **Twig Extra** (REQ-TWIG-004).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

Composer pulls `nowo-tech/form-kit-bundle` `^2.0`, `twig/extra-bundle` `^3.12`, and `twig/string-extra` `^3.12`. Register if Flex did not:

```php
Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
```

Auth form types use profile `auth_kit` via `#[FormKitConfig]`. The bundle prepends that profile (and `css_framework: bootstrap` when unset) under `nowo_form_kit` when the host has not defined them. Host values are not overwritten.

**Maintainers:** `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php`.

No schema changes.

## To 1.12.2

From **1.12.1** — docs TOC fix + CI Docker `ext-gd` for Endroid PNG QR; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.12.1

Optional: `composer require endroid/qr-code:^6` so QR login show pages render an image (`EndroidQrCodeGenerator`). Without it, URL/`public_code` fallback remains. PNG needs `ext-gd`; otherwise SVG is used.

## To 1.12.0

From **1.11.x**.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
php bin/console doctrine:schema:update --force
# or add migrations for:
# - table auth_kit_qr_login_challenge
# - column auth_kit_social_credential.enterprise_sso
```

### Optional: QR phone login

Enable per profile (`qr_login.mode: enabled`) and ensure the user entity exposes configured `phone` / `phone_verified_field` properties. See [QR-LOGIN.md](QR-LOGIN.md). Add `PUBLIC_ACCESS` for `/login/qr` routes (and locale-prefixed variants).

### Optional: enterprise SSO buttons

Set `enterpriseSso: true` on OIDC credentials that should appear under **Sign in with your organization**. See [SSO.md](SSO.md).

### WebAuthn

Not implemented yet; see [WEBAUTHN.md](WEBAUTHN.md).

## To 1.11.0

From **1.10.1** / **1.10.0** or earlier 1.x minors with default templates.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

### Template structure

- Bundle layout now nests security content inside `auth_brand` and `auth_panel`.
- Bundle security pages (`login`, `register`, `reset_*`, `magic_login_request`) now override `auth_panel` instead of `body`.
- If your application override extends `@NowoAuthKitBundle/layout.html.twig` and customizes only the inner panel, rename:

```twig
{% block body %}
    {# old custom panel #}
{% endblock %}
```

to:

```twig
{% block auth_panel %}
    {# custom panel #}
{% endblock %}
```

Overriding the whole `body` block still works for full takeovers, but hosts that want to keep the shared shell should now prefer `auth_brand`, `auth_panel_heading`, and `auth_footer_extra`.

### Optional: outbound mail readiness

Password-reset and magic-login links on the default login page are now hidden unless `nowo_auth_kit_outbound_mail_ready()` returns `true`.

If your app needs an environment-aware check, register a service that implements `Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface` and point the bundle to it:

```yaml
nowo_auth_kit:
    outbound_mail_ready_checker: app.auth_kit.mail_ready_checker
```

### Optional: shared form themes and CSS classes

You can now configure the default full-page form theme list and button classes per profile:

```yaml
nowo_auth_kit:
    profiles:
        default:
            templates:
                form_theme:
                    - 'bootstrap_5_layout.html.twig'
                    - '@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'
            css:
                button_class: 'btn btn-primary w-100'
                secondary_button_class: 'btn btn-outline-secondary w-100'
```

The default layout also loads `{{ asset('css/nowo-auth-kit.css', 'nowo_auth_kit') }}` for minimal design tokens.

## To 1.10.1

From **1.10.0** — test/CI fix only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

## To 1.10.0

From **1.9.1** — security hardening; defaults are safe. Clear cache after upgrade.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

### Behaviour changes (defaults)

- **Social login:** linking/creating a local user requires a verified email from the IdP (`require_verified_email: true`). Set `social_login.require_verified_email: false` only if you accept account-takeover risk.
- **Rate limits / OTP:** password-reset request, magic-login request, and registration use `cache.app` counters (defaults: 5 / 900s). OTP verification clears the reset credential after `max_code_attempts` (default 5). Set limits to `0` to disable.
- **Custom OAuth endpoints:** must be HTTPS and must not target localhost/private IPs.
- Re-run `php bin/console nowo:auth-kit:configure-security` if social login is enabled so `access_control` includes social routes.
- Ensure the app has a working `cache.app` pool (Symfony default is fine).

## To 1.9.1

From **1.9.0** — documentation only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Adds the [QR-LOGIN.md](QR-LOGIN.md) design (explicitly **in development** / not shipped).

## To 1.9.0

From **1.8.2** — backward compatible; social login is opt-in (default `mode: disabled`).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

New runtime dependency: `symfony/http-client` (^7.4 || ^8.0). No application config required if you leave social login disabled.

### Social login (optional)

1. Enable and migrate schema:

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                mode: enabled
                create_user_if_missing: true
```

```bash
php bin/console doctrine:schema:update --force
# or add a migration for auth_kit_social_credential + auth_kit_social_account
```

2. Insert at least one enabled `SocialLoginCredential` row (client id/secret). Buttons appear only then.
3. Allow public access to `/login/social` (and locale-prefixed variants). See [SOCIAL-LOGIN.md](SOCIAL-LOGIN.md).

## To 1.8.2

From **1.8.1** — maintainer / demo / CI only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Demo `make up` / `demo-smoke` no longer fail when `vendor/` is missing at container start (FrankenPHP worker waits for Composer).

## To 1.8.1

From **1.8.0** — maintainer / CI only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Optional monorepo Makefile includes no longer break standalone CI checkouts; Compose V1 (`docker-compose`) is accepted as a fallback.

## To 1.8.0

From **1.7.6** — backward compatible for typical apps (DI / Flex).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

- New runtime dependency: `psr/clock` (^1.0).
- If you **manually** instantiate `PasswordResetTokenManager`, pass a `Psr\Clock\ClockInterface` as the fifth constructor argument (Symfony Clock is auto-wired when using the container).
- Embed API: prefer `AuthEmbedOptions`; `array` options still accepted for Twig/BC (`auth_kit_dropdown({…})`).
- Logging sample notifiers no longer include secrets in log context (URLs/tokens/codes).

## To 1.7.6

From **1.7.5** — backward compatible for apps; re-run security config if you use the CLI helper.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**Logout CSRF:** `nowo:auth-kit:configure-security` now writes `logout.enable_csrf: true`. Re-run with `--force` if your firewall was generated earlier, or add `enable_csrf: true` under `logout` manually. If you override the embed authenticated partial, include `_csrf_token` / `csrf_token('logout')` on the logout URL.

**Optional:** bump `nowo-tech/password-strength-bundle` to ^2.0 when integrating strength UI (Twig namespace / translation domain rename — see that package’s UPGRADING).

## To 1.7.5

From **1.7.4** — maintainer / demo / CI only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime API changes. Package QA now includes FrankenPHP PHPStan rulesets; the README shows the worker-friendly banner. Demo Makefiles expose `restart` / `ensure-up` / `update-deps` / `release-check`; root `make down-dev` stops the bundle container without removing volumes. See [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).

## To 1.7.4

From **1.7.3** — CI/maintainers only; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

No runtime or demo changes. GitHub release sync no longer runs on tag push (use **Create Release** for new tags; run **Sync Missing Releases** manually or wait for the daily schedule if a release is missing). See [`RELEASE.md`](RELEASE.md).

## To 1.7.3

From **1.7.2** — demo-only change; no application config required.

```bash
composer update nowo-tech/auth-kit-bundle
```

If you run the FrankenPHP demo: Caddyfile selection is now driven by **`FRANKENPHP_MODE=worker|classic`** (default `worker`), not by `APP_ENV`. Copy the new key from `.env.example`, then recreate the container (`docker compose up -d` / `make -C demo up-symfony8`). See [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md).

## To 1.7.2

From **1.7.1** / **1.7.0** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

If you still set flat `default_locale` / `enabled_locales` / `locale_in_path` **and** a nested `locale` block, Symfony will emit a deprecation; keep only `locale` (nested values already took precedence).

## To 1.7.1

From **1.7.0** — demo-only patch; no application config changes required.

```bash
composer update nowo-tech/auth-kit-bundle
```

If you run the FrankenPHP demo, pull and restart: bare auth URLs (`/login`, …) now redirect to `/{locale}/…` (`locale.in_path: both`). See [`demo/README.md`](../demo/README.md).

## To 1.7.0

From **1.6.1** / **1.6.0** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**Locale routing** is now configured under `locale`:

```yaml
nowo_auth_kit:
    locale:
        in_path: always          # never | always | both
        default: en
        enabled: [en, es]
        unlocalized: redirect    # serve | redirect (only for both)
```

Legacy `default_locale`, `enabled_locales`, and `locale_in_path: true|false` still work (`true` ≡ `always`). Mixing those flat keys with a nested `locale` node triggers a deprecation; prefer only `locale`.

When using `in_path: both`, re-run:

```bash
php bin/console nowo:auth-kit:configure-security
```

so `access_control` covers both `/{locale}/…` and bare paths. Keep `form_login` on the canonical route names (not `*_unlocalized`).

## To 1.6.1

From **1.6.0** — backward-compatible patch.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**If magic login is enabled:** Symfony requires `login_link.signature_properties`. Re-run:

```bash
php bin/console nowo:auth-kit:configure-security
```

Or add manually (use your `user_identifier_field`, typically `email`):

```yaml
security:
    firewalls:
        main:
            login_link:
                check_route: nowo_auth_kit_magic_login_check
                signature_properties: [email]
                # …
```

No other application changes required. Demo-only: try reset / magic login via the session delivery inbox (no mailer).

## To 1.6.0

From **1.5.1** / **1.5.0** — backward compatible when magic login stays disabled (default).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**Optional — enable passwordless magic login:**

```yaml
nowo_auth_kit:
    magic_login:
        mode: enabled
        lifetime: 600
        max_uses: 1
```

```bash
php bin/console nowo:auth-kit:configure-security
```

Prefer **1.6.1+** so `configure-security` also writes `signature_properties` (required by Symfony). If you stay on 1.6.0 and configure `login_link` by hand, include e.g. `signature_properties: [email]`.

Implement `MagicLoginNotifierInterface` (email the `loginUrl`) and alias it in `services.yaml`. See [`MAGIC-LOGIN.md`](MAGIC-LOGIN.md).

No entity fields are required (links are signed by Symfony `login_link`).

## To 1.5.1

From **1.5.0** — backward compatible for application integrators.

```bash
composer update nowo-tech/auth-kit-bundle
```

No configuration or template changes required. Package runtime behavior is unchanged.

**Maintainer / demo notes:**

- The FrankenPHP **Symfony 7.4** demo (`demo/symfony7`, port `:8009`) was removed. Use `demo/symfony8` (`make -C demo up-symfony8`, port `:8010`).
- Contributors: run `make setup-hooks` and see [`GITHUB_CI.md`](GITHUB_CI.md) (**REQ-GIT-001** — no Cursor co-author trailers in commit messages).

## To 1.5.0

From **1.4.4**, **1.4.3**, **1.4.2**, **1.4.1**, **1.4.0**, or earlier 1.x — backward compatible for single-entity setups.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

**No migration required** if you keep the flat configuration (`user_class` at root). It is normalized internally to a single `default` profile.

**What is new:**

- Multiple user entities can each have their own login, registration, password reset, routes, templates, and firewall under `nowo_auth_kit.profiles`.
- Routes are registered per profile; each route sets `_auth_kit_profile` so controllers resolve the correct settings automatically.
- `ProfileRegistry::resolveForObject($user)` resolves the profile from the authenticated entity class (cached O(1) lookup).
- Embed dropdown (`auth_kit_dropdown()`) uses the default profile unless you pass `profile` in Twig options.

**Optional migration to profiles layout:**

```yaml
nowo_auth_kit:
    default_profile: app_user
    profiles:
        app_user:
            user_class: App\Entity\User
            registration_mode: first_user_only
            routes:
                login:
                    path: /login
                    name: nowo_auth_kit_login
        admin:
            user_class: App\Entity\Admin
            registration_mode: disabled
            routes:
                login:
                    path: /admin/login
                    name: nowo_auth_kit_admin_login
```

**Behavior note:** each profile must define a unique `user_class` and unique route **names** across all profiles. Route paths may differ per profile.

## To 1.4.4

From **1.4.3** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
```

No configuration or template changes required. This release adds maintainer-only Spec Kit documentation and tooling (`specs/`, `.specify/`, `docs/SPEC-KIT.md`); integrator-facing behavior is unchanged.

## To 1.4.3

From **1.4.2** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No configuration changes required. Password field labels now use the AuthKit translation domain when `password_strength` is enabled.

## To 1.4.2

From **1.4.1** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No configuration changes required.

If you use `password_strength.enabled: true`, the confirmation field now validates match only (fix for double strength UI/validation). Custom form overrides are unaffected unless they duplicated the old `RepeatedType` + `PasswordStrengthType` pattern.

## To 1.4.1

From **1.4.0** — no code or configuration changes required.

```bash
composer update nowo-tech/auth-kit-bundle
```

This release fixes CI coverage checks and bumps GitHub Actions only.

## To 1.4.0

From **1.3.0** — backward compatible.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No configuration changes required.

### Login template: register link

The bundle login template now uses `registration_allowed` instead of always showing the register link. If you override `security/login.html.twig`, wrap the register link:

```twig
{% if registration_allowed|default(false) %}
    <a href="{{ path(register_route, auth_kit_route_params()) }}">{{ 'login.register_link'|trans({}, 'NowoAuthKitBundle') }}</a>
{% endif %}
```

See [USAGE.md](USAGE.md#registration-link-on-login-page).

### Optional: new translations

Catalogues **de**, **fr**, **it**, **nl**, and **pt** ship with the bundle. Add locales to Symfony and `nowo_auth_kit.enabled_locales` as needed.

## To 1.3.0

From **1.2.0** — backward compatible; password strength is opt-in (`password_strength.enabled: false` by default).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

No changes required unless you want strength policies on registration or password-reset fields.

### Optional: password strength

Requires `nowo-tech/password-strength-bundle` (not installed by default):

```bash
composer require nowo-tech/password-strength-bundle
php bin/console assets:install
```

```yaml
nowo_auth_kit:
    password_strength:
        enabled: true
        level: medium
        policy_mode: level
```

Include the client script in your layout:

```twig
<script src="{{ asset('bundles/passwordstrength/password-strength.js') }}" defer></script>
```

Policy details (`levels`, `form_theme`, live feedback) live in `nowo_password_strength.yaml`. See [CONFIGURATION.md](CONFIGURATION.md#password-strength-optional).

## To 1.2.0

From **1.1.1** — backward compatible; remember-me is opt-in (`remember_me.enabled: false` by default).

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console nowo:auth-kit:configure-security --force
php bin/console cache:clear
```

Login forms post nested field names (`login_form[_username]`, not bare `_username`). The command above adds `username_parameter`, `password_parameter`, `csrf_parameter`, and `invalidate_session: true` on logout.

### Optional: remember me

Enable persistent login in `config/packages/nowo_auth_kit.yaml`:

```yaml
nowo_auth_kit:
    remember_me:
        enabled: true
        lifetime: 604800
        path: /
```

Then sync the firewall (remember-me is updated on every run; `--force` only needed to refresh `form_login`):

```bash
php bin/console nowo:auth-kit:configure-security
```

To **disable** remember-me, set `enabled: false` (and remove `remember_me` from `login_fields` if present), then re-run the command above — the `remember_me` firewall block is removed automatically.

See [CONFIGURATION.md](CONFIGURATION.md#remember-me).

## To 1.1.1

From **1.1.0** — no code or configuration changes required.

```bash
composer update nowo-tech/auth-kit-bundle
```

This release updates README screenshots, Composer package metadata URLs, and CI action versions only.

## To 1.1.0

From **1.0.0** — backward compatible; new features are opt-in via configuration defaults.

```bash
composer update nowo-tech/auth-kit-bundle
php bin/console cache:clear
```

### Optional: password reset

1. Add nullable token fields to your user entity (see [PASSWORD-RESET.md](PASSWORD-RESET.md)).
2. Enable in config:

   ```yaml
   nowo_auth_kit:
       password_reset:
           mode: enabled
           delivery: link   # or code | both
   ```

3. Implement and register `PasswordResetNotifierInterface`.
4. Run `php bin/console nowo:auth-kit:configure-security` to add public `access_control` paths.

### Optional: embedded auth dropdown

```yaml
nowo_auth_kit:
    embed:
        mode: dropdown
```

Render in Twig: `{{ auth_kit_dropdown() }}`. See [USAGE.md](USAGE.md#embedded-loginregister-dropdown).

### Optional: locale in URL paths

```yaml
nowo_auth_kit:
    locale_in_path: true
    default_locale: en
    enabled_locales: [en, es]
```

Re-run `php bin/console nowo:auth-kit:configure-security` so `access_control` patterns include `^/(en|es)/login`, etc.

Use `auth_kit_route_params()` in Twig for locale-aware links. See [USAGE.md](USAGE.md#locale-in-url-paths).

### Demo users

If you run the FrankenPHP demos, rebuild the PHP image after pulling (`docker compose build php`) and reset MySQL volumes if Doctrine warns about MySQL &lt; 8 (`docker compose down -v`).

## To 1.0.0

This is the first public release. Install via Composer and follow [INSTALLATION.md](INSTALLATION.md).

```bash
composer require nowo-tech/auth-kit-bundle
```

After Flex installs the recipe (or manual setup):

1. Configure `config/packages/nowo_auth_kit.yaml` (`user_class`, `registration_mode`, etc.).
2. Install password-field dependencies if not added by the recipe:

   ```bash
   composer require nowo-tech/password-toggle-bundle symfony/ux-icons symfony/http-client
   php bin/console ux:icons:lock
   ```

3. Run `php bin/console nowo:auth-kit:configure-security`.
4. Clear cache: `php bin/console cache:clear`.

## Future upgrades

When upgrading between versions:

1. Read [CHANGELOG.md](CHANGELOG.md) for breaking changes.
2. Run `composer update nowo-tech/auth-kit-bundle`.
3. Clear Symfony cache: `php bin/console cache:clear`.
4. Re-run `php bin/console nowo:auth-kit:configure-security` if route names or firewall settings changed.
5. Verify `config/packages/nowo_auth_kit.yaml` against [CONFIGURATION.md](CONFIGURATION.md).
6. If password toggle icons break after an upgrade, run `php bin/console ux:icons:lock` again.
