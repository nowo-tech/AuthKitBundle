# Feature Specification: Passkeys / WebAuthn runtime

**Feature Branch**: `008-webauthn` (`feat/2.4-webauthn`)  
**Status**: Implemented (target v2.4.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Configuration**: `nowo_auth_kit.profiles.<name>.webauthn.*`, `mfa.first_factors.webauthn`  
**Origin**: Symfony Beacon request `2026-11-25-authkit-webauthn` (spec 221 block D, T028; owner D-20261125-05), design
[`docs/WEBAUTHN.md`](../../docs/WEBAUTHN.md) (1.12.0)

---

## Summary

Passkeys as a passwordless first factor with conditional UI (the browser offers the site's passkeys, or a phone
through the hybrid transport's QR code) and as a second factor next to TOTP. Ceremonies on web-auth/webauthn-lib 5.3;
AuthKit owns the challenges, storage, limits, events, pages and personal data. Opt-in, off by default.

## User Scenarios

### US-01 — Add a passkey (P1)

**Given** `webauthn.mode: enabled` and a user who signed in within `recent_sign_in`, **When** the user names a passkey
and completes the browser ceremony on `/account/webauthn`, **Then** it is stored (`WebauthnCredentialAddedEvent`) with
its COSE key, counter, transports and backup flags under the MFA owner key and an HMAC user handle. Refused (no row):
no recent sign-in, impersonation, remember-me session, `max_credentials` reached (also against a parallel
registration), a duplicate credential id, options of another account, an invalid ceremony (origin, RP id, user
verification, challenge, attestation `direct` without a statement).

### US-02 — Sign in with a passkey (P1)

**Given** a registered passkey, **When** the browser answers the discoverable options of the login page (button or
conditional UI), **Then** the user is signed in through `Security::login()` (first factor `webauthn`, MFA exempt by
default) and `WebauthnCredentialUsedEvent` (`login`) is dispatched. An unknown credential, another account's
credential, a broken check, a reused or expired challenge, a counter that does not increase, a user who is gone and
too many attempts all answer the same redirect and flash (`WebauthnAuthenticationFailedEvent` carries the reason).

### US-03 — Passkey as the second factor (P1)

**Given** `mfa.enabled` and `webauthn.mfa`, **When** a user with a passkey signs in with a password, **Then** the
challenge offers **Use a passkey** (the code form only with TOTP on); only the user's own passkeys are listed and
accepted; each assertion uses one attempt of the pending sign-in; a required user with a passkey is never forced into
TOTP enrolment.

### US-04 — Rename and remove (P2)

**Given** a recent sign-in, **When** the user renames or removes a passkey, **Then** it changes
(`WebauthnCredentialRemovedEvent`); another account's passkey answers "unknown"; the last passkey of a required user
without TOTP stays (last-factor guard).

### US-05 — Personal data (P2)

**Given** UserKit, **Then** the eraser `auth_kit_webauthn` deletes the user's passkeys in every profile (also under the
original identifier) and the export section `passkeys` lists them without key material; a missing table is nothing to
do.
**Given** no UserKit and passkeys switched off in every profile (2.4.1, audit ZBX-v-I2), **Then**
`MfaManager::forgetUser()` (through `WebauthnErasure`, always registered) still deletes the stored passkeys.

## Requirements

- **FR-WA-01** Challenges: 32 random bytes in the session per profile and ceremony, `timeout_ms` + 30 s, taken out
  before verification and claimed once in the attempt limiter's store under its lock.
- **FR-WA-02** User verification required; exact origins; RP id hash; ES256 / EdDSA / RS256.
- **FR-WA-03** Counter recorded by `UPDATE … WHERE sign_count < :new` (or both zero).
- **FR-WA-04** Deny by default: one generic answer for every refused sign-in; second-step options list only the
  pending user's passkeys.
- **FR-WA-05** Rate limits `rate_limit.{options,assert,register}`; per-owner lock for the limit and the guard.
- **FR-WA-06** Table `auth_kit_webauthn_credential` with exact-key columns, XML mapping, SQL scripts per platform,
  demo migration.
- **FR-WA-07** Templates overridable (`web_ui.templates.webauthn_account`, blocks), script with CSP nonce.
- **FR-WA-08** FrankenPHP worker mode without kernel reset; REQ-RUNTIME-003.

## Success Criteria

- 100 % line coverage; kernel tests for every scenario and refusal; worker test (two users, 300 rounds, flat memory);
  real-database tests (MySQL, MariaDB, PostgreSQL; DBAL 3 and 4) for the counter race, the unique key, exact owners
  and the SQL scripts.
