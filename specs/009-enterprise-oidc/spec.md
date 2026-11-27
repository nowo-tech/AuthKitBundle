# Feature Specification: Enterprise OpenID Connect per organization

**Feature Branch**: `009-enterprise-oidc` (`feat/2.7.0-enterprise-oidc`)  
**Status**: Implemented (target v2.7.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Data**: `auth_kit_social_credential.listed`, `.link_existing_account`, `.mfa_first_factor`, `.mfa_accepted_amr`  
**Origin**: Symfony Beacon request `2026-11-27-authkit-enterprise-oidc` (spec 221 blocks 0 + A, T001 / T003), design
[`docs/SSO.md`](../../docs/SSO.md#enterprise-oidc-per-organization-270)

---

## Summary

Organization administrators connect their own identity provider; the host owns the organization side (credential per
organization, verified domains, home-realm discovery, provisioning), AuthKit owns the protocol. Discovery and ID token
validation exist since 2.3.0 (request items 1, 2) and `enterpriseSso` / callback handlers since 2.0 (item 7a). 2.7.0
adds the gaps: unlisted credentials with `login_hint` (item 3), per-credential `link_existing_account` and MFA
first-factor policy (item 4), the validated ID token claims for host handlers and the host-minted test sign-in (item 7b).
Item 5 (client secret at rest) is documentation only; item 6 (RP-initiated logout) is planned for 2.8.0.

## User Scenarios

### US-01 — Unlisted credential started by name (P1)

**Given** a credential with `listed = false`, **When** the login page or the invitation accept page renders, **Then** it
is not offered; **When** the host redirects to `nowo_auth_kit_social_login_start` with `provider` and
`login_hint=<e-mail>`, **Then** the sign-in starts and the provider receives `login_hint` (only a valid e-mail of at
most 254 bytes without white space or control characters; anything else is dropped).

### US-02 — Never link an existing account by e-mail (P1)

**Given** a credential with `link_existing_account = deny`, **When** its first sign-in asserts the verified e-mail of an
existing local account, **Then** the sign-in is refused, nothing is linked and the local password is untouched, for
AuthKit's own sign-in and for any caller of `SocialAccountLinker`. A null value keeps the profile's policy. The
invitation accept page does not offer a credential whose effective policy is `deny`.

### US-03 — MFA first-factor policy per credential (P1)

**Given** `mfa.enabled` and a user with a second factor, **When** the user signs in with a credential whose policy is
`exempt`, **Then** no second step is asked; `required`: it is asked; `exempt_when_amr` with accepted values: exempt only
when the `amr` claim of the validated ID token names one of them. A userinfo `amr`, an OAuth2 credential, a non-list
`amr` or an unknown stored policy never exempt. Null keeps `mfa.first_factors.social`. Forced enrolment always applies.

### US-04 — Validated claims for host handlers (P1)

**Given** an OpenID Connect credential, **When** a `SocialCallbackStepInterface` handles the callback, **Then**
`$context->socialProfile->idTokenClaims` holds the validated ID token claims alone (`nonce` removed), never userinfo.

### US-05 — Test sign-in (P1)

**Given** a registered `SocialLoginTestHandlerInterface`, **When** an administrator action calls
`SocialLoginTestStart::start($request, $provider, $hint)`, **Then** a one-shot marker is stored with the OAuth state in
the session and the authorization URL is returned; **When** the provider calls back with that state, **Then** every
check of a real sign-in runs and only the test handler receives the context: no callback step runs, nothing is linked
or created and nobody is signed in (a sign-in answer is refused). A replayed state, a URL parameter (`?test=1`) or a
normal start never reach the test handler. Without a handler no test starts and a pending test state is burned.

## Requirements

- **FR-001** Four nullable columns; null = 2.6.0 behaviour (no BC break, SQL for MySQL / MariaDB / PostgreSQL / SQLite in
  UPGRADING).
- **FR-002** Fail closed on unknown stored values (`link_existing_account` → `deny`, `mfa_first_factor` → `required`).
- **FR-003** `amr` read from the validated ID token claims only (`SocialCredentialPolicy::amrAccepted()`).
- **FR-004** Test mode bound to server-side state only; no request parameter starts or marks a test.
- **FR-005** Worker mode: no per-request state in services (REQ-RUNTIME-002/003); the marker lives in the session.
- **FR-006** `client_secret` encryption at rest documented as conditional on DoctrineEncrypt (SECURITY, UPGRADING).

## Out of scope

- RP-initiated logout (`end_session_endpoint`, `id_token_hint`): 2.8.0.
- Refusing to store enterprise secrets without DoctrineEncrypt (fail closed): 2.8.0.
