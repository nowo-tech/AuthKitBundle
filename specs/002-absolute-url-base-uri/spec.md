# Feature Specification: Absolute URL base for out-of-band links

**Feature Branch**: `002-absolute-url-base-uri`  
**Status**: Implemented (v1.24.0)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Configuration**: `nowo_auth_kit.absolute_url.base_uri`  
**Origin**: Symfony Beacon spec 134 FR-004 (SEC-MAIL-HOST)

---

## Summary

Links that leave the request (password-reset link, reset-code page link, magic link, QR approve URL, OAuth `redirect_uri`) are absolute URLs built from the router request context, i.e. the incoming `Host` header. Behind a proxy that forwards any `Host` and without `framework.trusted_hosts`, an attacker can POST a reset request for a victim with `Host: evil.example` and the victim's email carries a genuine token inside a link to `evil.example` (password-reset poisoning). An optional fixed origin removes the request from the equation.

## User Scenarios

### US-01 — Forged Host does not reach the reset email (P1)

**Given** `absolute_url.base_uri: https://app.example.com`, **When** a reset is requested with `Host: evil.example`, **Then** the notifier receives `https://app.example.com/…/reset/{token}` (or the reset-code page URL for code delivery).

### US-02 — Magic link (P1)

**Given** `base_uri` set, **When** a magic link is requested with a forged `Host`, **Then** the link uses the base origin and keeps `{_locale}` (recipient locale, else request locale, else the router context parameter).

### US-03 — QR and OAuth (P2)

**Given** `base_uri` set, **When** the QR page renders or a social login starts / exchanges the code, **Then** the QR payload and the `redirect_uri` use the base origin. The provider callback must be registered as `{base_uri}` + check path.

### US-04 — Unset keeps behaviour (P1)

**Given** `base_uri` null or empty (default), **Then** URLs are generated exactly as in 1.23.

## Requirements

- **FR-001**: `absolute_url.base_uri` — nullable scalar, default `null`. Literal values MUST be absolute `http`/`https` URLs with a host; optional port and path prefix (trailing `/` dropped); user info, query and fragment rejected (`InvalidConfigurationException`). Env placeholders and empty strings pass config validation.
- **FR-002**: `Routing\AbsoluteUrlGenerator(UrlGeneratorInterface $router, ?string $baseUri)` validates the resolved value at construction (`InvalidArgumentException`); empty = disabled. `generate()` / `withBaseContext(callable, array $contextParameters)` apply a `RequestContext` (base path, host, scheme, http/https port; method, path info, query string and parameters copied from the current context, `$contextParameters` merged) and restore the original context in `finally`. Disabled = pass-through.
- **FR-003**: `AuthKitUrlGenerator` sends `ABSOLUTE_URL` and `NETWORK_PATH` generations through `AbsoluteUrlGenerator` (optional trailing constructor argument, BC). Covers `PasswordResetRequestHandler`, `QrLoginShowController`, `SocialLoginStartController`, `SocialLoginCheckController`.
- **FR-004**: `MagicLoginRequestHandler` (optional trailing `?AbsoluteUrlGenerator`) — when enabled, calls `LoginLinkHandler::createLoginLink($user, null, $lifetime)` inside `withBaseContext()` with `_locale` = recipient locale ?? request locale (omitted when both are null); otherwise unchanged (request copy in recipient locale).
- **FR-005**: Worker safety — no mutable state besides the router context, always restored (also on exceptions). Igor audit clean.

## Non-goals

- No automatic production warning when unset (documented in `docs/SECURITY.md` instead; avoids a stateful one-time logger in worker mode).
- No change to relative URLs (redirects, form actions).

## Tests

- `tests/Unit/Routing/AbsoluteUrlGeneratorTest.php` — host from base, port, path prefix, network path, parameter merge, context restored after success and exception, validation cases, env placeholder detection.
- `tests/Integration/AbsoluteUrlBaseUriTest.php` — real router + handlers + `LoginLinkHandler`: forged `Host` leaks without base, does not with base (reset link, reset-code link, magic link, locale fallbacks).
- `ConfigurationTest`, `NowoAuthKitExtensionTest` (env placeholder compiled and resolved at runtime), `AuthKitUrlGeneratorTest`.
