# Feature Specification: Invitation follow-ups (existing accounts, batches, social on the accept page)

**Feature Branch**: `007-invitation-followups` (`feat/invitation-followups`)  
**Status**: Implemented (target v1.42.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Configuration**: `nowo_auth_kit.attempt_limiter.cache_pool`,
`nowo_auth_kit.profiles.<name>.invitations.{batch,social,routes.social}`  
**Origin**: Symfony Beacon request `2026-11-10-authkit-invitations-followups` (spec 191 T008 – T012 on AuthKit 1.41);
builds on [spec 004](../004-invitations-host-api/spec.md)

---

## Summary

Six follow-ups that let a host stop working around the invitation API: a supported acceptance by an existing account,
a bounded bulk invite, a configurable pool for the attempt counters, the invitation kept after a social e-mail
mismatch, the invitation and the social providers on the accept page, and a host check when the link is opened. All
opt-in; the defaults keep 1.41 behaviour.

## User Scenarios

### US-01 — An existing account accepts (P1)

**Given** a pending invitation to `member@example.com` without roles and the signed-in account with that verified
e-mail, **When** the host calls `InvitationAcceptor::acceptExisting($user, $invitation, $email)`, **Then** the
invitation is claimed once (conditional update of the current link) inside the grants' transaction, and
`InvitationAcceptedEvent` (`existingAccount: true`, scalar ids) is dispatched after the commit; no account is created.
Another e-mail (`email_mismatch`), an expired (`expired`), accepted or revoked (`no_invitation`) invitation, a role on
it, a stale copy after a concurrent claim or a resend (`not_accepted`), a pre-accept refusal, a failing listener or a
grant refusal (`refused`) leave it pending.

### US-02 — Bulk invite (P1)

**Given** `batch.max_size: 3`, `batch.rate_limit: 1`, **When** the host calls `inviteBatch()` with three requests,
**Then** they are invited with one inviter slot (`invite_rate_limit` untouched), each still checked (roles held,
authorizers, address and invitation limits); a second batch in the window is `rate_limited` and sends nothing; four
requests, an invalid e-mail, a forged role or a bad merge value throw before anything is sent; `max_size: 0` (default)
throws `LogicException`.

### US-03 — Counters in a configured pool (P2)

**Given** `attempt_limiter.cache_pool: <pool>`, **Then** `AuthKitAttemptLimiter` stores every counter in that pool.

### US-04 — Retry after a social mismatch (P2)

**Given** `social.keep_pending_on_mismatch: true`, **When** the provider does not assert the invited e-mail, **Then**
the session keeps the invitation (retry with another provider account or the form); any other outcome removes it.

### US-05 — Snapshot and providers on the accept page (P1)

**Then** the accept template receives `invitation` (`InvitationSnapshot`, no token or hash). **Given**
`social.accept_page: true`, **Then** the page lists the enabled social providers (not SSO) as CSRF forms bound to the
invitation; the start (rate limited, signed-in refused, pre-check) hands over to AuthKit's OAuth flow; the verified
callback accepts the pending invitation, links the identity and signs in; mismatch, provider failure, expiry,
revocation, refusal and a sign-in that cannot happen answer their notices and consume nothing.

### US-06 — Check when the link opens (P1)

**Given** a listener refusing `InvitationOpeningEvent`, **When** the current link of a pending invitation is opened,
**Then** the `unavailable` page shows the reason, nothing is consumed and the link works once the host allows it;
unknown, used, revoked, replaced and expired links never dispatch it (no oracle); a failing listener refuses.

## Requirements

- **FR-INVF-001** `InvitationAcceptor::acceptExisting()`, `InvitationAcceptingEvent::$user` / `isExistingAccount()`,
  `InvitationAcceptedEvent::$existingAccount`, `$userClass`, `$userId`, `$userIdentifier`, `invitationId()`,
  `profile()`.
- **FR-INVF-002** `InvitationManager::inviteBatch()`, `invitations.batch.{max_size,rate_limit,rate_window}`.
- **FR-INVF-003** `attempt_limiter.cache_pool` (default `cache.app`).
- **FR-INVF-004** `invitations.social.keep_pending_on_mismatch` in `acceptPendingForSocial()`.
- **FR-INVF-005** `invitation` / `invitation_social_providers` template variables, `InvitationAcceptor::snapshot()`,
  `invitations.social.{accept_page,name_field}`, `invitations.routes.social`, `InvitationAcceptController::social()` and
  its `SocialCallbackHandlerInterface` methods (tag added only with `accept_page`), notices `social_mismatch` /
  `social_failed`.
- **FR-INVF-006** `InvitationOpeningEvent`, `InvitationAcceptor::precheck()`, notice `unavailable`.

## Success criteria

- 100 % line coverage of `src/`; PHPStan, Rector, CS, Igor, Twig lint, translations parity green.
- Kernel test `InvitationFollowupsKernelTest` (host-like kernel with the follow-ups on) covers US-01..06; the defaults
  are asserted by `InvitationFollowupsConfigurationTest` and the 1.33 tests.
