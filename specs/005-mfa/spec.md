# Feature Specification: Two-factor authentication (MFA)

**Feature Branch**: `005-mfa` (`feat/1.34.0-mfa`)  
**Status**: Implemented (target v1.34.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Configuration**: `nowo_auth_kit.profiles.<name>.mfa`  
**Origin**: symfony-beacon D-20261103-07 ("Sí a MFA en AuthKit"), prerequisite of D-20261103-05 (several accounts);
contract `.uic-work/authkit-multiaccount/CONTRACT-MFA.md` with owner decisions D-M1…D-M11 (2026-11-03)

---

## Summary

Native second factor, opt-in per profile: TOTP authenticator apps + single-use recovery codes, a firewall-level
two-step token after every first factor, trusted devices, required roles with a grace period, an administrator reset,
UserKit session integration. Defaults unchanged (no service, route, entity mapping with `auto`, cookie or listener).

## User Scenarios

- **US-01 (P1)** A user enrols on the account page (QR + key, confirm with a code), saves ten recovery codes shown
  once, and from then on every sign-in asks for a code after the password, magic link, SSO, sign-up or invitation.
- **US-02 (P1)** A half-authenticated browser reaches nothing but the challenge, enrolment and sign out.
- **US-03 (P1)** A lost phone: a recovery code signs in once; an administrator can reset the factor.
- **US-04 (P2)** "Trust this device for 30 days" skips the code on that browser until forgotten or expired.
- **US-05 (P2)** Administrators must have MFA: reminders during a grace period, then enrolment at sign-in.

## Requirements

- **FR-001** `mfa.enabled: false` registers nothing; `true` needs `issuer`, DoctrineEncryptBundle (unless
  `require_encryption: false`) and form CSRF (compile-time).
- **FR-002** On `AuthenticationTokenCreatedEvent` of the profile firewall, a user with MFA (factor not exempt,
  browser not trusted) or who must enrol now gets a role-less `MfaPendingToken` (attribute
  `nowo.authentication_pending`); remember-me sign-ins follow `mfa.remember_me` (`trusted` / `challenge`).
- **FR-003** While pending, only the challenge, setup, logout and `allowed_routes` run; others redirect (GET target
  kept) or answer `401 {"error":"mfa_required"}`; access-control refusals are contained the same way; the pending
  state expires after `pending_lifetime` and ends after `max_attempts` wrong codes (session invalidated).
- **FR-004** No remember-me cookie at the first factor; issued on completion when requested.
- **FR-005** Completion restores the token, migrates the session id, dispatches `MfaChallengePassedEvent` and
  `MfaLoginCompletedEvent`, redirects to the kept target (same-origin path only).
- **FR-006** TOTP RFC 6238 (SHA-1, digits / period configurable, window ±1), 160-bit secret, replay refused through a
  conditional `UPDATE` of `last_used_step`; enrolment secret only in the session until confirmed; server-rendered QR;
  the otpauth URI never logged.
- **FR-007** Recovery codes: `count` codes, HMAC at rest, single use, regenerate with a current code, shown once with a
  download; input normalised.
- **FR-008** Disable and new recovery codes need a current code (step-up); disable forgets devices and signs the other
  sessions out (UserKit `revokeOthers`, epoch); re-enrolment likewise.
- **FR-009** Trusted devices: HMAC token in a `__Host-` httpOnly SameSite=Lax cookie (≤ 10 tokens), rotated on use,
  `lifetime` 30 days, max 5 or UserKit `maxTrustedDevices` (LRU forgotten), forgotten on disable / reset / re-enrol /
  UserKit sign-out everywhere, admin, account disabled, password changed; UserKit device id kept and
  `revokeDevice()` on forget (current session kept); never skip forced enrolment.
- **FR-010** Required: `required_roles` (hierarchy) then `policy`; grace starts at the first required sign-in; after
  it, `setup` mode; admin reset sets grace to zero.
- **FR-011** Rate limits: per account (lockout event at the limit) and per client IP; failures audited without codes.
- **FR-012** Admin reset (`MfaManager::reset()`; optional page with `admin.routes`): access roles, CSRF, never own
  account or under `SwitchUserToken`, never reveals the secret, `revokeAll` + epoch, `MfaResetByAdminEvent`.
- **FR-013** Account pages refuse `SwitchUserToken`; pending tokens are never impersonation sources (no roles).
- **FR-014** Owners keyed by Doctrine root class + id; UserKit `forgetUser()` deletes the factor.
- **FR-015** Pages on the UiKit contract (`web_ui.templates.mfa_*`), `no-store`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`; translations in de, en, es, fr, it, nl, pt.
- **FR-016** Audit DD follow-up: `symfony/lock` required with MFA (compile-time); setting up the second factor needs a
  sign-in within `recent_sign_in` (900 s); `trusted_devices.password_field`; only wrong codes count per IP.

## Success Criteria

- **SC-001** Kernel tests: enrolment, challenge, replay, recovery single use, remember-me after the second step,
  forced enrolment after grace, trusted devices, step-up, lockout, expiry, admin reset, impersonation refusals,
  UserKit integration (`Mfa*KernelTest`), RFC 6238 vectors (`TotpTest`).
- **SC-002** 100 % line coverage, PHPStan level 8, CS, Rector, Igor, Twig lint, translations parity.
- **SC-003** Demo e2e (Playwright + axe): enrol, challenge, recovery codes, admin reset.

## Out of scope

WebAuthn and e-mail codes (ROADMAP, D-M1), scheb/2fa compatibility (D-M8), SMS.
