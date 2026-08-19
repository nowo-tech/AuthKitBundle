# Security

## Table of contents

- [Threat model](#threat-model)
- [Application responsibilities](#application-responsibilities)
- [Logging](#logging)
- [Bundle responsibilities](#bundle-responsibilities)
- [AI security audit](#ai-security-audit)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Threat model

Auth Kit Bundle provides login/register **UI and persistence helpers**. Symfony Security remains responsible for authentication, session management, and authorization after login.

| Area | Risk | Mitigation |
| --- | --- | --- |
| Login form | CSRF, credential stuffing | Symfony `form_login` with CSRF; **require** `nowo-tech/login-throttle-bundle` + `nowo:login-throttle:configure-security` in production |
| Registration | Mass signup, privilege escalation, session fixation | `registration_mode`; configurable `registration_role`; session ID migrated after auto-login |
| Password storage | Weak hashing | Uses `UserPasswordHasherInterface` |
| Password reset / magic login | Token leakage, enumeration, OTP brute-force | Tokens stored hashed; uniform UX messages; built-in request rate limits + OTP `max_code_attempts` lockout; prefer `link` delivery |
| Social login | Account takeover via unverified email; SSRF via custom IdP URLs | `require_verified_email` (default true); HTTPS + public-host checks on custom endpoints |
| Registration | Mass signup, privilege escalation, session fixation | `registration_mode`; `registration_rate_*`; race check for `first_user_only`; configurable `registration_role`; session ID migrated after auto-login |
| Logout | CSRF | `logout.enable_csrf: true` from `configure-security`; embed link includes CSRF token |
| Templates | XSS | Twig auto-escaping; apps must not disable escaping in overrides |
| Configuration | Wrong entity/field mapping | Validation in `Configuration`; documented `security.yaml` setup |

## Application responsibilities

- Configure `security.yaml` (firewall, provider, `access_control`); re-run `configure-security` after enabling social login
- **Require** `nowo-tech/login-throttle-bundle` and run `nowo:login-throttle:configure-security` so login forms are throttled in production
- Protect admin routes with appropriate roles
- Provide a working Symfony `cache.app` pool (used by Auth Kit attempt limiter and Login Throttle when storage is `cache`)
- Prefer password-reset `delivery: link` (or stronger OTP charset) in production
- Do not alias `LoggingPasswordResetNotifier` / `LoggingMagicLoginNotifier` in production (even redacted, they are sample/dev helpers)
- Run `composer audit` in the application
- Do not commit `.env` or secrets
- **Residual:** OAuth `client_secret` and linked account tokens are stored in cleartext in the DB — encrypt at rest (app/DB) if required by your threat model

## Logging

Sample logging notifiers record **metadata only**: masked identifier, delivery mode, expiry. They **never** log reset/magic URLs, link tokens, or OTP codes (REQ-OBS-001). Prefer `Null*Notifier` (default) or your own mailer/SMS notifier in production.

## Bundle responsibilities

- Hash passwords on registration and password reset completion
- Use Symfony form CSRF defaults on login forms; logout CSRF when configured via CLI / demo
- Migrate the session after registration auto-login
- Compare reset OTP hashes with `hash_equals`; clear reset credentials after too many failed OTP attempts
- Rate-limit password-reset / magic-login / registration requests via `AuthKitAttemptLimiter` (`cache.app`)
- Require verified IdP email before social auto-link/create (configurable)
- Validate custom OAuth endpoint URLs (HTTPS, no private/loopback hosts)
- No automatic modification of `security.yaml` without explicit CLI command

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-07-30 |
| Method | Cursor agent static review + remediation pass (`src/`, Twig, SECURITY docs) |
| Grade | **Pass (conditional)** — overall **Medium** |
| Mitigated in 1.10.0 | Unverified-email social auto-link; missing reset/magic/register rate limits; OTP lockout; custom OAuth SSRF; social `PUBLIC_ACCESS`; `first_user_only` race; magic login 500 oracle |
| Open residuals | Cleartext OAuth secrets/tokens at rest; residual timing side-channels on reset/magic request; do not use logging notifiers in prod |

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
| Require Login Throttle bundle + `configure-security` in INSTALLATION/recipe | ☐ |
| AI security audit Pass (good/conditional) recorded (REQ-SEC-004) | ☐ |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for private disclosure.
