# Feature Specification: Several accounts at once (account switcher)

**Feature Branch**: `006-multi-account` (`feat/1.34.0-multi-account`, stacked on `feat/1.34.0-mfa`)  
**Status**: Implemented (target v1.34.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle` (+ `nowo-tech/user-kit-bundle` 1.11, PR #31)  
**Configuration**: `nowo_auth_kit.profiles.<name>.multi_account`  
**Origin**: symfony-beacon D-20261103-05 ("Varias cuentas a la vez"); contract `.uic-work/authkit-multiaccount/CONTRACT.md`
(owner decisions D1–D10, 2026-11-03; S0 session-hop spike: GO)

---

## Summary

Google-style multi-account sign-in: each account keeps the session of its own complete sign-in (MFA included); the
inactive ones are parked in a server-side vault reachable only through the browser's per-account opaque tokens;
switching re-opens the target's session (no new authentication), re-runs the user checker and regenerates the session
id. UserKit 1.11 tells whether parked sessions are alive. Defaults unchanged.

## Requirements

- **FR-001** `multi_account.enabled: false` registers nothing; `true` requires UserKit ≥ 1.11 sessions (D1), a stateful
  firewall, form CSRF and the sodium extension (D6).
- **FR-002** Vault cookie: one 256-bit base64url token per account, httpOnly, SameSite Lax/Strict, `__Host-` on HTTPS,
  browser-session by default (D3); HMAC at rest; rotation on every use; previous token accepted within
  `rotation_grace`, later removes the entry (`token_reuse`); unknown tokens dropped and rate limited.
- **FR-003** Parked data (session id, remember-me cookie) sealed with XChaCha20-Poly1305 under HKDF(token), associated
  data `profile|public_id`.
- **FR-004** Add: parks the current complete sign-in (creating its entry), clears the remember-me cookie, opens a fresh
  session in add mode (15 min). Enrolment only for a complete sign-in (`EnrolmentGateInterface`; default: not an
  `MfaPendingToken`, not a `SwitchUserToken`) in add mode or for an account already in the vault (re-bind); never from
  admin-issued magic links (D8); at most `max_accounts` (5).
- **FR-005** Switch (CSRF, rate limited): target among the browser's entries only; UserKit state live → re-open its
  session, refresh the user, user checker, `AccountSwitchingEvent` (deny only); refusal rolls back (target session
  ended, entry removed); success migrates the session id, rekeys UserKit, restores the target token and remember-me
  cookie, rotates tokens, `AccountSwitchedEvent`. Revoked → removed; expired with a remember-me cookie → revived through
  the firewall's remember-me (D9); otherwise removed.
- **FR-006** Sign out: parked account ended (UserKit row deleted, session emptied); active account through the firewall
  sign-out with scope `current`; sign out all; the firewall sign-out ends every account with `logout_scope: all` (D2),
  the active one plus the chooser with `current`.
- **FR-007** UserKit sign-outs everywhere / admin / account disabled / password changed and user removal remove the user
  from every browser.
- **FR-008** Switcher partial and `auth_kit_accounts()`, chooser page, sign-in page list with masked identifiers (D4),
  optional cross-tab notice (D7), presenter SPI; `private, no-store`, `X-Frame-Options: DENY`; 7 locales.
- **FR-009** Entries expire `max_lifetime` (30 days, D10) after the last full sign-in; purge command.
- **FR-010** Audit DE hardening: a revoked parked session never revives (UserKit ≥ 1.11.1 keeps a purged revoked row
  revoked); a previous token after `rotation_grace` removes the entry and signs the account out everywhere, within it
  the rotating browser learns the current token (no re-rotation) and another browser is ignored; unknown tokens never disable a vault; a failing switch rolls back to the previous
  session and token; the AD binds the user identifier; identifiers masked for anonymous browsers; `_target_path`
  without control characters or backslashes; re-bound parked sessions ended; D8 kept through MFA completion;
  `cookie.secure`; per-firewall user provider; `configure-security` makes the switcher routes public.

## Success criteria

- **SC-001** Kernel tests: add a second account with MFA, switch both ways without a password (new session id,
  original token), revoke one account, sign out one / all, refused disabled account, normal sign-out ends all, chooser
  and sign-in list (masked), token reuse, expiry, revival through remember-me, host veto, rate limits, impersonation,
  stale forms, removed users, emptied sessions, host gate / presenter / `logout_scope: current`.
- **SC-002** 100 % coverage, PHPStan 8, CS, Rector, Igor, Twig lint, translations parity.
- **SC-003** Demo e2e (Playwright + axe): add with MFA, switch, revoke, sign out one / all, refused disabled account.
