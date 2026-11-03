# Roadmap

Planned direction for Auth Kit Bundle. Items are grouped by horizon and may change with feedback and capacity.

## Table of contents

- [Current state (v1.34)](#current-state-v134)
- [Short term](#short-term)
- [Medium term](#medium-term)
- [Non-goals](#non-goals)

---

## Current state (v1.34)

- Login, registration (open, first user, invite-only), password reset (link / code), magic login, social login / SSO,
  QR phone login, invitations host API, embedded widget, UiKit page contract, FrankenPHP worker safe.
- **Two-factor authentication** (1.34, opt-in): TOTP authenticator apps + recovery codes, trusted devices, required
  roles with a grace period, administrator reset — [MFA.md](MFA.md).

## Short term

- **Several accounts signed in at once** (account switcher, per-account sessions, UserKit integration; requested by
  symfony-beacon D-20261103-05), on top of MFA.

## Medium term

- **WebAuthn / passkeys** as a second factor and as a passwordless first factor (design in [WEBAUTHN.md](WEBAUTHN.md);
  deferred from the MFA v1 scope, owner decision D-M1 2026-11-03).
- **E-mail codes** as a fallback second factor (through the host mailer, with the same rate limits as TOTP; D-M1).
- Optional per-device remember-me revocation together with UserKit's planned per-device series.

## Non-goals

- SMS one-time codes as a primary factor (SIM swap risk); hosts can add them through a custom first factor.
- Storing passwords or second factors outside the host database.
