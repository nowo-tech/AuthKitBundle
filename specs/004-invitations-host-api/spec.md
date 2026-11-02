# Feature Specification: Invitations host API (hosts with their own invitation UI)

**Feature Branch**: `004-invitations-host-api` (`feat/1.33.0`)  
**Status**: Implemented (v1.33.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Configuration**: `nowo_auth_kit.profiles.<name>.invitations.{registration_role,merge_key,payload_merge,admin_routes}`  
**Origin**: Symfony Beacon spec 191 (sessions and invitations on the kits, FR-012), kit-extraction plan approved by
the user; builds on [spec 003](../003-invitations/spec.md)

---

## Summary

Lets a host keep AuthKit's invitation storage, accept page and security rules while issuing invitations from its own
pages, sending its own e-mail and accepting invitations through social login. Everything is opt-in; the defaults keep
1.32 behaviour (backwards compatible).

## User Scenarios

### US-01 — Invitees do not inherit the first-user role (P1)

**Given** `registration_role: ROLE_ADMIN` (first user) and `invitations.registration_role: ROLE_MEMBER`, **When** an
invitation is accepted (form or social), **Then** the account gets `ROLE_MEMBER` plus the invitation roles, never
`ROLE_ADMIN`. **Given** `invitations.registration_role: null`, **Then** 1.32 behaviour (`registration_role`).

### US-02 — Dead links are reported (P2)

**When** the current link of an expired or revoked invitation is opened, **Then** `InvitationLinkRejectedEvent`
(snapshot, `expired` / `revoked`) is dispatched once; unknown, replaced and used links dispatch nothing; no token.

### US-03 — Host refuses with a reason (P1)

**Given** a listener of `InvitationAcceptingEvent` calling `refuse(new InvitationRefusal('key'))` (or a grant throwing
`InvitationRefusedException::because(...)`), **When** the invitee submits, **Then** the translated reason is shown,
no account exists and the invitation stays pending. Listeners can only refuse (deny by default).

### US-04 — Host sends its own mail (P1)

**When** the host invites with `returnLink: true` or resends with `resend($actor, $invitation, 14, true)`, **Then**
no e-mail is sent, the outcome is `link` with the URL, the events say `delivery: host`, the TTL is one of the offered
validities (else `ttl_days`). An actor lacking a role of the invitation gets `not_allowed` and no link.

### US-05 — One open invitation per (e-mail, organization) (P1)

**Given** `merge_key: organization` and `payload_merge: deep`, **When** the same e-mail is invited for two
organizations, **Then** two open invitations exist; re-inviting one organization merges into its invitation (maps
recursively, lists unioned). Re-inviting a higher-role invitation's (e-mail, organization) is `not_allowed`.

### US-06 — Social acceptance (P1)

**Given** the invitee opened the link (session entry) and signed in with a provider asserting a verified e-mail equal
to the invited one, **When** the host calls `InvitationAcceptor::acceptPendingForSocial()`, **Then** the account is
created (no password, invited e-mail forced) with the same rules as the form; mismatch, unverified, expired, refused
or missing invitation return the matching status and nothing is created; the session entry is always removed.

### US-07 — No admin pages (P2)

**Given** `admin_routes: false`, **Then** `index`, `new`, `resend`, `revoke` routes are not registered (and their
names are not checked for duplicates); the accept route stays. Routes remain opt-in (REQ-SF-004 rule 7).

## Requirements

- **FR-INVH-001** `invitations.registration_role` (nullable `ROLE_*`) used by `UserRegistrar::registerInvited()`.
- **FR-INVH-002** `InvitationLinkRejectedEvent`, dispatched by `InvitationAcceptor::lookup(..., report: true)` from
  the accept page and the social acceptance.
- **FR-INVH-003** `InvitationAcceptingEvent` (refuse only), `InvitationRefusal` (translatable),
  `InvitationRefusedException::because()`, `InvitationAcceptance` and `InvitationAcceptor::attempt()`; the accept page
  renders the reason on `not_accepted`.
- **FR-INVH-004** `InvitationManager::resend(?UserInterface, Invitation, ?int $ttlDays = null, bool $returnLink =
  false)`, `InvitationRequest::$returnLink`, `InvitationCreatedEvent::DELIVERY_HOST`; the link is returned only to
  an actor holding every role (`holdsAll()`), never logged.
- **FR-INVH-005** `invitations.merge_key`, `invitations.payload_merge` (`replace` | `deep`),
  `InvitationPayloadMerger`, `InvitationRepository::findOpenByEmailAndPayloadKey()` (newest 100 open rows of the
  e-mail, strict comparison).
- **FR-INVH-006** `InvitationAcceptor::pending()`, `acceptPendingForSocial()`, `InvitationAcceptor::SESSION_KEY`
  (same value as `InvitationAcceptController::SESSION_KEY`); registration gate, verified equal e-mail, password
  fields dropped.
- **FR-INVH-007** `invitations.admin_routes` (default `true`) in `AuthKitRouteLoader` and the route-name check.
- **FR-INVH-008** SEC-004 CD fixes hold: role escalation via resend / re-invite (with merge key and returned link),
  roles re-check on acceptance, no secrets in events / logs.

## Success criteria

- 100 % line coverage of `src/`; PHPStan, Rector, CS, Igor, Twig lint, translations parity green.
- Kernel test `InvitationHostApiKernelTest` (host-like kernel) covers US-01..07 and the M1 re-checks.
