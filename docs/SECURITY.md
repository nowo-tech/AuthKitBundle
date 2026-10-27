# Security

## Table of contents

- [Threat model](#threat-model)
- [Application responsibilities](#application-responsibilities)
- [Logging](#logging)
- [Bundle responsibilities](#bundle-responsibilities)
- [Password-reset poisoning (Host header)](#password-reset-poisoning-host-header)
- [Content Security Policy (CSP)](#content-security-policy-csp)
- [AI security audit](#ai-security-audit)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Threat model

Auth Kit Bundle provides login/register **UI and persistence helpers**. Symfony Security remains responsible for authentication, session management, and authorization after login.

| Area | Risk | Mitigation |
| --- | --- | --- |
| Login form | CSRF, credential stuffing | Symfony `form_login` with CSRF; **require** `nowo-tech/login-throttle-bundle` + `nowo:login-throttle:configure-security` in production |
| Registration | Mass signup, privilege escalation, session fixation, pre-registration of someone else's address | `registration_mode`; `registration_rate_*`; race check for `first_user_only`; configurable `registration_role`; auto-login through `Security::login()` (user checker, `LoginSuccessEvent`, session strategy) since 1.31; a later social link resets the local password (`social_login.link_existing_account`) |
| Identifier lookup | Accent/case-insensitive database collations (`utf8mb4_0900_ai_ci`, `*_unicode_ci`) resolve a look-alike address (`victim@exámple.com`, an IDN the attacker owns) to the victim | Since 1.31 every anonymous lookup (reset, magic login, social e-mail match, QR completion) accepts only an account whose **stored** identifier equals the input after NFC normalisation and lower-casing (`UserIdentifierMatcher`); e-mail identifiers must be plain ASCII addresses on the request forms |
| Notifiers | A notifier mailing the typed address sends the victim's link to the attacker | Since 1.31 `identifier` in `MagicLoginNotificationContext` / `PasswordResetNotificationContext` is the identifier **stored** on the account, and `user` carries the account. **Send only to it** (or to a channel read from `user`), never to request input |
| Password storage | Weak hashing, weak passwords | `UserPasswordHasherInterface`; `password_policy.min_length` (12 by default since 1.31) and optional `password_policy.not_compromised` |
| Password reset / magic login | Token leakage, enumeration, OTP brute-force | Tokens stored hashed; uniform UX messages; request rate limits; OTP `max_code_attempts` per **account** (spelling variants share it, 1.31) plus `code_rate_limit` per client IP; a pending credential is cleared when the identifier or password changes outside the reset flow; prefer `link` delivery |
| Magic link lifetime / replay | Used admin links replayed after the firewall forgets them; links valid after a password change | `configure-security` writes `login_link.lifetime = max(magic_login.lifetime, magic_login.admin_link.lifetime)` and `signature_properties: [<identifier>, password]` (1.31); links are signed and consumed by the login link handler of the **profile's firewall** |
| Mailed / QR links | Password-reset poisoning: forged `Host` header puts an attacker origin in the victim's reset / magic link | `absolute_url.base_uri` (1.24+) builds out-of-band links from a fixed origin; otherwise configure `framework.trusted_hosts` |
| Redirects | A request locale from input turning `/{_locale}/login` into `//evil.example/login` | Generated paths only use locales from `locale.enabled` (1.31); the bare-URL redirect only follows same-origin paths |
| Social login | Account takeover via unverified or look-alike e-mail; sign-up while registration is closed; cross-profile identity; SSRF via custom IdP URLs | `require_verified_email` (default true); exact stored-identifier match; `create_user_if_missing` default **false** and gated by `registration_mode` (1.31); links scoped per user class (unique `provider + subject + user_class`, 1.31); HTTPS + public-host checks on custom endpoints |
| QR login | CSRF approval by a signed-in phone; completing a challenge through another profile | QR approve always uses form CSRF and the container refuses to compile without it (1.31); the desktop cookie is bound to the creating profile and the approving account must be of the profile's user class |
| Logout | CSRF | `logout.enable_csrf: true` from `configure-security`; embed link includes CSRF token (or POST form) |
| Templates | XSS | Twig auto-escaping; apps must not disable escaping in overrides |
| Slide to confirm (optional) | Crafted POST can still send `confirm=1` | UX only — CSRF, authn, QR step-up, and business rules remain mandatory |
| Device intelligence (optional) | Treating Device ID as a login secret; blocking login on `isNew()`; auto-trust | Device ID is **not** a credential. LoginThrottle/CSRF/remember-me unchanged. AuthKit never auto-`trust()`. QR trusted-device step-up is opt-in |
| OTP input (optional) | Treating the multi-box widget as verification | UX only — `hash_equals` and `max_code_attempts` remain mandatory |
| Configuration | Wrong entity/field mapping | Validation in `Configuration`; documented `security.yaml` setup |

### Link lifetimes

| Link | Default lifetime | Setting |
| --- | --- | --- |
| Password reset link / code | 3600 s | `password_reset.token_ttl` |
| Magic login (sign-in page) | 600 s | `magic_login.lifetime` |
| Administrator-issued magic link | 3600 s | `magic_login.admin_link.lifetime` (firewall `login_link.lifetime` must be at least this) |
| QR challenge | 90 s | `qr_login.challenge_ttl` |

## Application responsibilities

- Configure `security.yaml` (firewall, provider, `access_control`); re-run `configure-security` after enabling social login or magic login
- **Require** `nowo-tech/login-throttle-bundle` and run `nowo:login-throttle:configure-security` so login forms are throttled in production
- **Notifiers:** deliver links and codes only to `$context->identifier` (the stored identifier) or to a channel read from `$context->user`; never to a value taken from the request
- Keep `framework.csrf_protection` and `framework.form.csrf_protection` enabled (QR login and the magic-login confirm interstitial refuse to run without them)
- Social login: keep `create_user_if_missing: false` unless public sign-up is intended; keep `link_existing_account: reset_password` (or `deny`) unless the application verifies e-mail ownership at registration; run the 1.31 schema change (see UPGRADING) when several profiles use social login
- Deliver mails asynchronously (Symfony Messenger) so the request step does not reveal through its timing whether an account exists; do not show the `throttled` flag of `PasswordResetCheckEmail` in a way that differs from the normal answer
- Protect admin routes with appropriate roles
- Provide a working Symfony `cache.app` pool (used by Auth Kit attempt limiter and Login Throttle when storage is `cache`)
- Set `nowo_auth_kit.absolute_url.base_uri` (e.g. `%env(DEFAULT_URI)%`) or `framework.trusted_hosts` in production so mailed links cannot be poisoned through the `Host` header
- Prefer password-reset `delivery: link` (or stronger OTP charset) in production
- Do not alias `LoggingPasswordResetNotifier` / `LoggingMagicLoginNotifier` in production (even redacted, they are sample/dev helpers)
- Run `composer audit` in the application
- Do not commit `.env` or secrets
- **At rest:** OAuth `client_secret` (`SocialLoginCredential`) and linked account access / refresh tokens (`SocialLoginAccount`) are encrypted with `#[Encrypted]` (nowo-tech/doctrine-encrypt-bundle; configure its key). The provider `raw_profile`, e-mail and display name of a linked account are stored in cleartext

## Logging

Sample logging notifiers record **metadata only**: masked identifier, delivery mode, expiry. They **never** log reset/magic URLs, link tokens, or OTP codes (REQ-OBS-001). Prefer `Null*Notifier` (default) or your own mailer/SMS notifier in production.

## Bundle responsibilities

- Hash passwords on registration and password reset completion
- Use Symfony form CSRF defaults on login forms; logout CSRF when configured via CLI / demo; refuse to compile (`CsrfProtectionPass`) and refuse to act (`FormCsrf`) when QR approval or the magic-login confirm interstitial would run without form CSRF
- Sign the new account in through `Security::login()` after registration (session migrated by the firewall strategy)
- Resolve anonymous identifiers by exact stored value (`UserIdentifierMatcher`) and hand notifiers the stored identifier and the account
- Clear a pending reset credential when the identifier or password changes outside the reset flow (`PasswordResetTokenInvalidationListener`)
- Compare reset OTP hashes with `hash_equals`; clear reset credentials after too many failed OTP attempts
- Rate-limit password-reset / magic-login / registration requests via `AuthKitAttemptLimiter` (`cache.app`; serialized per key with `symfony/lock` when `lock.factory` exists, best-effort otherwise)
- Social login: one-time OAuth `state` plus PKCE (`S256`) by default for built-in providers (`social_login.pkce`)
- Require verified IdP email before social auto-link/create (configurable)
- Validate custom OAuth endpoint URLs (HTTPS, no private/loopback hosts)
- Bound outbound OAuth2 token / userinfo calls with configurable deadlines (`http_client.timeout` 10 s, `http_client.max_duration` 20 s) so a slow or hostile identity provider cannot pin PHP threads (resource-exhaustion mitigation, REQ-RUNTIME-001)
- QR deny is a POST form (`QrLoginDenyType`) authorised by the per-challenge approve token in the action URL plus the authenticated phone session
- No automatic modification of `security.yaml` without explicit CLI command
- Optional slide-to-confirm is confirmation UX only; QR approve always uses Form CSRF (`QrLoginApproveType` / `qr_login_approve`)
- Optional device intelligence never authenticates by Device ID; `new_device_notify` is a notice only; QR `approve_require_trusted` requires explicit trust, not auto-trust on login
- Optional OTP input is entry UX only; reset codes are still hashed and compared with `hash_equals`, and `max_code_attempts` still clears the credential

## Password-reset poisoning (Host header)

Reset links, magic links, the QR approve URL and the OAuth `redirect_uri` are absolute URLs. Without configuration Symfony builds them from the request context, i.e. from the incoming `Host` header (and `X-Forwarded-*` from trusted proxies). If the edge forwards any `Host` to the app and `framework.trusted_hosts` is empty, an attacker can submit the reset form for a victim with `Host: evil.example`; the victim then receives a genuine token inside a link to `evil.example`, and one click leaks it.

Mitigations (use at least one, ideally both):

1. `nowo_auth_kit.absolute_url.base_uri: '%env(DEFAULT_URI)%'` (1.24+) — AuthKit builds every out-of-band link against that origin, whatever the request `Host` is. See [CONFIGURATION.md](CONFIGURATION.md#absolute-url-base-mailed--qr-links).
2. `framework.trusted_hosts: ['^app\\.example\\.com$']` — Symfony rejects requests for other hosts (protects every absolute URL the app builds, not only AuthKit's).

Custom notifiers that build their own URLs (e.g. asset URLs in email templates) should use the same base.

## Content Security Policy (CSP)

Every inline `<script>` / `<style>` rendered by the bundle templates carries `nonce="…"` taken from the request attribute **`csp_nonce`** when it is present (nothing is emitted otherwise). Your CSP listener should set it before rendering, e.g. `$request->attributes->set('csp_nonce', $nonce)`, and send the same value in `script-src 'nonce-…'` / `style-src 'nonce-…'`. External scripts (`src=…`) and JSON islands need no nonce, and templates use no inline event handlers (`onclick`, `onsubmit`, …). `tests/Unit/Templates/InlineBlocksDeclareNonceTest.php` enforces this.

## AI security audit

| Field | Value |
| --- | --- |
| Date | **2026-10-27** — first pass of `main` `78c5977` **Fail / High**; fix verification of PR #18 (`993d53d`) → **Pass (good)** for **1.31.0** (supersedes 2026-09-24 / 2026-09-28) |
| Method | Claude Code security review (static, full `src/` + Twig/JS + config defaults + `configure-security` + docs + demo), then diff review of PR #18 with bypass analysis (NFC vs NFKC / fullwidth, Turkish İ, ß, zero-width, trailing dot / PAD SPACE, IDN vs punycode, Kelvin sign, `findBy` candidate limit, multi-profile social / QR / magic link, pre-registration, OTP variants, locale redirect, CSRF disabled) |
| Grade | **Pass (good)** — overall **Low** |
| Fixed in 1.31.0 | **High** H1 look-alike identifier → reset / magic link for the victim mailed to an attacker-owned IDN address (`UserIdentifierMatcher`, stored identifier + `user` in notification contexts, ASCII e-mail on request forms); **High** H2 cross-profile social sign-in (links scoped to `user_class`, new unique index, `instanceof` check); **Medium** M1 social sign-up ignored `registration_mode`, M2 pre-account takeover (`link_existing_account: reset_password`), M3 reset-code lockout bypass (per-account key + `code_rate_limit`); **Low** L1 link replay window / signature without password, L2 firewall-bound login link handler + profile-bound QR cookie, L3 locale whitelist + same-origin redirect, L5 12-character minimum + optional `NotCompromisedPassword`, L6 `Security::login()` on registration + reset credential invalidation; CSRF fails closed (`CsrfProtectionPass`, `FormCsrf`) |
| Open residuals | Request-step timing (deliver mails asynchronously); notifiers must use `identifier` / `user` only; registration auto-login requires `form_login` on the firewall; `password_policy` read from the default profile; host-side credentials (TOTP, API tokens) survive the `reset_password` link; LoginThrottle buckets per typed username; run the 1.31 social schema change with several profiles; `raw_profile` JSON not encrypted |
| Advisories | H1 — affected `>= 1.1.0, < 1.31.0`; H2 — affected `>= 1.9.0, < 1.31.0`; patched `1.31.0` |

See also the monorepo record in [`BUNDLES_SECURITY_ANALYSIS.md`](https://github.com/nowo-tech/bundles/blob/master/BUNDLES_SECURITY_ANALYSIS.md) (AuthKitBundle entry).

## Release security checklist (12.4.1)

Last recorded run: **v1.21.0** (2026-09-24). Re-run it before the next tag (the 1.31 hardening changes behaviour: see the threat model above and UPGRADING).

Before each release, confirm:

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☑ |
| `.env` listed in `.gitignore`; no secrets in repo | ☑ |
| Flex recipe / default config contain no secrets | ☑ |
| User input validated (forms + Symfony validator on registration) | ☑ |
| Output escaped (Twig templates) | ☑ |
| `composer audit` run on bundle and demo | ☑ |
| Logs do not dump credentials | ☑ |
| Password hashing via Symfony hasher (no custom crypto) | ☑ |
| Registration gate prevents unwanted signups per config | ☑ |
| Require Login Throttle bundle + `configure-security` in INSTALLATION/recipe | ☑ |
| AI security audit Pass (good/conditional) recorded (REQ-SEC-004) | ☑ Pass (good) 2026-10-27 (first pass Fail; H1/H2/M1–M3 fixed in 1.31.0) |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for private disclosure.
