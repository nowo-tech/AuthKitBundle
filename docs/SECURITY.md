# Security

## Table of contents

- [Threat model](#threat-model)
- [Application responsibilities](#application-responsibilities)
- [Bundle responsibilities](#bundle-responsibilities)
- [AI security audit](#ai-security-audit)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Threat model

Auth Kit Bundle provides login/register **UI and persistence helpers**. Symfony Security remains responsible for authentication, session management, and authorization after login.

| Area | Risk | Mitigation |
| --- | --- | --- |
| Login form | CSRF, credential stuffing | Symfony `form_login` with CSRF; document rate limiting via `nowo-tech/login-throttle-bundle` |
| Registration | Mass signup, privilege escalation, session fixation | `registration_mode`; configurable `registration_role`; session ID migrated after auto-login |
| Password storage | Weak hashing | Uses `UserPasswordHasherInterface` |
| Password reset / magic login | Token leakage, enumeration, OTP brute-force | Tokens stored hashed; uniform UX messages; prefer `link` delivery; apps must rate-limit requests and OTP verification |
| Logout | CSRF | `logout.enable_csrf: true` from `configure-security`; embed link includes CSRF token |
| Templates | XSS | Twig auto-escaping; apps must not disable escaping in overrides |
| Configuration | Wrong entity/field mapping | Validation in `Configuration`; documented `security.yaml` setup |

## Application responsibilities

- Configure `security.yaml` (firewall, provider, `access_control`)
- Protect admin routes with appropriate roles
- Pair login / reset / magic / OTP verification with rate limiting (`nowo-tech/login-throttle-bundle` and/or listeners on bundle events)
- Prefer password-reset `delivery: link` (or stronger OTP charset) in production; lock out failed OTP attempts
- Do not alias `LoggingPasswordResetNotifier` / `LoggingMagicLoginNotifier` in production
- Run `composer audit` in the application
- Do not commit `.env` or secrets

## Bundle responsibilities

- Hash passwords on registration and password reset completion
- Use Symfony form CSRF defaults on login forms; logout CSRF when configured via CLI / demo
- Migrate the session after registration auto-login
- Compare reset OTP hashes with `hash_equals`
- No automatic modification of `security.yaml` without explicit CLI command

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-07-27 |
| Method | Cursor agent static review (`src/`, Twig, SECURITY docs, demo env, Flex recipe) |
| Grade | **Pass (conditional)** — overall **Medium** |
| Open residuals | App-owned rate limiting / LoginThrottle pairing; OTP entropy + lockout when `delivery: code`; timing side-channels on reset/magic request; do not use logging notifiers in prod |

See also the monorepo record in [`BUNDLES_SECURITY_ANALYSIS.md`](https://github.com/nowo-tech/bundles/blob/master/BUNDLES_SECURITY_ANALYSIS.md) (AuthKitBundle entry).

## Release security checklist (12.4.1)

Before each release, confirm:

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☐ |
| `.env` listed in `.gitignore`; no secrets in repo | ☐ |
| Flex recipe / default config contain no secrets | ☐ |
| User input validated (forms + Symfony validator on registration) | ☐ |
| Output escaped (Twig templates) | ☐ |
| `composer audit` run on bundle and demo | ☐ |
| Logs do not dump credentials | ☐ |
| Password hashing via Symfony hasher (no custom crypto) | ☐ |
| Registration gate prevents unwanted signups per config | ☐ |
| Document DoS/rate-limit pairing with login throttle bundle | ☐ |
| AI security audit Pass (good/conditional) recorded (REQ-SEC-004) | ☐ |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for private disclosure.
