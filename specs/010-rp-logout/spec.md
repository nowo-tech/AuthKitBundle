# Feature Specification: OpenID Connect RP-initiated logout and enterprise secrets fail closed

**Feature Branch**: `010-rp-logout` (`feat/2.8.0-rp-logout`)  
**Status**: Implemented (target v2.8.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Data**: `auth_kit_social_credential.rp_initiated_logout`; session key `_nowo_auth_kit_rp_logout` (sealed)  
**Origin**: Symfony Beacon request `2026-11-27-authkit-enterprise-oidc` items 5 and 6 (spec 221 block B), deferred from
[spec 009](../009-enterprise-oidc/spec.md); design [`docs/SSO.md`](../../docs/SSO.md#rp-initiated-logout-280)

---

## Summary

Organization administrators who enforce single sign-on need a sign-out of the application to end the IdP session too
(OpenID Connect RP-Initiated Logout 1.0), and organization client secrets must never be written in clear text. 2.8.0
adds RP-initiated logout as a per-credential opt-in (deny by default, local sign-out always first) and makes the write
of an enterprise client secret fail closed without nowo-tech/doctrine-encrypt-bundle.

## User Scenarios

### US-01 — Sign-out ends the IdP session (P1)

**Given** an OpenID Connect credential with `rp_initiated_logout = true` whose discovery document publishes a public
HTTPS `end_session_endpoint`, **When** a user signs in with it (AuthKit's linker or a host handler that signs in) and
later signs out, **Then** the local session and token are gone and the browser is redirected to the
`end_session_endpoint` with `id_token_hint` (the ID token of that sign-in, unless larger than 8 KiB), `client_id` and,
when the host configured an allow-list, `post_logout_redirect_uri` (the sign-out target when listed, else the first
entry).

### US-02 — Any doubt keeps the local sign-out (P1)

**Given** a hint in the session, **When** the signing-out user is not the one it was issued for, or the credential is
gone, disabled, opted out or has another client id, or the endpoint no longer passes the HTTPS / SSRF rules, or the
sign-out is answered with something other than a redirect, or anything fails, **Then** the sign-out is the plain local
one, the hint is removed, and the failure is logged by class only.

### US-03 — Enterprise secrets are never stored in clear (P1)

**Given** `doctrine.require_enterprise_secret_encryption` (default true), **When** a credential with `enterprise_sso`
true and a non-empty client secret is inserted or updated through an entity manager on which DoctrineEncrypt's
listener is not registered or has no encryptor, **Then** `EnterpriseSecretNotEncryptedException` is thrown before the
flush opens its transaction: nothing is written, the manager stays open, and the message names the provider only.
Consumer credentials and enterprise credentials without a secret are not affected.

## Requirements

- **FR-001** One nullable column (`rp_initiated_logout`); null = 2.7.0 behaviour; SQL for MySQL / MariaDB / PostgreSQL /
  SQLite in UPGRADING.
- **FR-002** `end_session_endpoint` read from discovery through the same HTTPS / SSRF rules (no fragment, no
  credentials, ≤ 2048 characters); an unsafe or absent value is dropped without failing the discovery.
- **FR-003** The hint is sealed (XChaCha20-Poly1305, key derived from `kernel.secret`), bounded, bound to the signed-in
  user, never logged, removed at sign-out and replaced or removed by the next social sign-in.
- **FR-004** `post_logout_redirect_uri` only from the host's allow-list (validated at build time), never from the request.
- **FR-005** The sign-out never makes an outbound request and never throws; the local sign-out always completes.
- **FR-006** Worker mode: no per-request state in services; the hint lives in the session (REQ-RUNTIME-002/003).
- **FR-007** Enterprise client secrets fail closed (`onFlush`, before the transaction); escape hatch for tests only.

## Out of scope

- Front-channel and back-channel logout (the provider calling the application).
- RP-initiated logout for OAuth2-only credentials (no ID token).
- A `state` round trip (the application session is gone when the provider redirects back).
