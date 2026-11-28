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
- **Several accounts at once** (1.34, opt-in): account switcher with per-account sessions — [MULTI-ACCOUNT.md](MULTI-ACCOUNT.md).
- **Passkeys / WebAuthn** (2.4.0, opt-in): passwordless first factor with conditional UI and second factor next to
  TOTP — [WEBAUTHN.md](WEBAUTHN.md).
- **SAML 2.0 Service Provider sign-in** (2.10.0, opt-in): SP-initiated, signed AuthnRequests, strict response checks,
  provider rows per tenant with certificate rollover and SP key rotation — [SAML.md](SAML.md). Later: IdP-initiated
  sign-in (if ever), Single Logout, IdP metadata refreshed from a URL.

## Short term

- Account switcher: recognise older rotated tokens as reuse (token chain), optional device binding of the vault.

## Medium term

- Social / OIDC: per-credential `link_by_email` and an allow-list of e-mail domains a provider may assert (hardening of
  audit ZBP-L2: today `link_existing_account` is per profile and trusts every configured issuer with every address).

- Passkeys: a passkey as the forced-enrolment choice of a required user (today the setup page enrols TOTP), and an
  optional AAGUID allow-list with FIDO metadata for `attestation: direct`.
- **E-mail codes** as a fallback second factor (through the host mailer, with the same rate limits as TOTP; D-M1).
- Optional per-device remember-me revocation together with UserKit's planned per-device series.

## Non-goals

- SMS one-time codes as a primary factor (SIM swap risk); hosts can add them through a custom first factor.
- Storing passwords or second factors outside the host database.
