# Feature Specification: Invitations instead of open registration

**Feature Branch**: `003-invitations` (`feat/invitations`)  
**Status**: Implemented (v1.32.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Configuration**: `nowo_auth_kit.profiles.<name>.invitations`, `registration_mode: invite_only`  
**Origin**: Symfony Beacon spec 179 (user invitations), audit row KIT-42, OTHER REQ-AUTH-012

---

## Summary

Administrators invite people by e-mail; the invitee creates the account through AuthKit's registration form from a
single-use, expiring link. Generalised from Beacon (no project, group, organization or seat concepts: those are host
grants through an SPI). Off by default (`invitations.mode: disabled`); `registration_mode: invite_only` closes the
open registration page so invitations are the only way in.

## User Scenarios

### US-01 — Invite and accept (P1)

**Given** `invitations.mode: enabled` and an administrator, **When** they invite `ana@example.com` with a grantable
role, **Then** an e-mail carries `https://<base_uri>/invitation/<token>`; opening it shows the registration form with
the e-mail locked; submitting creates the account with `registration_role` + the role, signs it in, and the link stops
working.

### US-02 — Resend, revoke, expiry (P1)

**When** the administrator resends, **Then** a new link and expiry are issued and the previous link answers the
neutral "no longer valid" page; **When** they revoke, the link answers the same page; an expired link (still the
current token) says it expired. No page shows invitee data.

### US-03 — Closed registration (P1)

**Given** `registration_mode: invite_only`, **Then** `/register` redirects to sign-in, social login creates no
account, and invitations are accepted. **Given** `disabled`, invitation acceptance is refused too.

### US-04 — Host grants (P2)

**Given** a service implementing `InvitationGrantInterface`, **When** an invitation is accepted, **Then** it runs in
the acceptance transaction with the invitation payload; throwing `InvitationRefusedException` rolls everything back.

### US-05 — Defaults keep 1.31 behaviour (P1)

**Given** no `invitations` configuration, **Then** no entity mapping change (beyond `register_mapping` rules), no
route, no page, no command, no behaviour change.

## Requirements

- **FR-INV-001** Entity `Invitation` (`auth_kit_invitation`, table prefix aware): public id, profile, normalised
  e-mail, roles, payload, message (≤ 500), locale, SHA-256 token hash (unique), ttl, `expires_at`, `sent_at`,
  `send_count`, inviter / accepter / revoker identifiers, `accepted_at`, `revoked_at`, `created_at`. Status derived
  (revoked, accepted, expired, pending).
- **FR-INV-002** Tokens: 32 random bytes base64url; only the hash stored; `hash_equals`; single use through a
  conditional claim `UPDATE`.
- **FR-INV-003** `InvitationManager`: invite (validates e-mail, roles ⊆ `invitations.roles`; merges into the open
  invitation of the same e-mail and resends), resend (new token), revoke; rate limits per inviter and per
  invitation; outcome sent / link (opt-in) / not delivered / rate limited / not open; audit events.
- **FR-INV-004** Administration pages (UiKit list / form / show scaffolds, FormKit types, CSRF-only actions with
  `<action>/<profile>/<id>` token ids), `invitations.security` (REQ-UI-002: `access_roles` non-empty,
  `access_checker`, literal debug-only `allow_unauthenticated`), `private, no-store`, path-only redirects, bounded
  list.
- **FR-INV-005** Accept page on the registration form: token → session → token-less URL; e-mail pre-filled and
  locked (`lock_email`), payload pre-fill of mapped non-secret fields, CSRF bound to the invitation, neutral notices,
  per-IP rate limit, signed-in visitors refused; `InvitationAcceptor` creates the account
  (`UserRegistrar::registerInvited()`), runs the grants and commits in one transaction.
- **FR-INV-006** `registration_mode: invite_only`; `RegistrationGate::isInvitationAcceptanceAllowed()`.
- **FR-INV-007** Delivery: `InvitationMailerInterface` → `MailerInvitationMailer` (Symfony Mailer, translated subject,
  overridable templates, invitation locale) or `NullInvitationMailer`; `InvitationsPass` wires the fallback and
  requires SecurityBundle; `CsrfProtectionPass` requires form CSRF.
- **FR-INV-008** `nowo:auth-kit:invitations:purge` (bounded batches, `--days`, `--auth-profile`, `--include-accepted`,
  `--dry-run`), `InvitationsPurgedEvent`.
- **FR-INV-009** Opt-in routes from the AuthKit route loader only (`invitations.routes`); a kernel whose routing is
  only `routing.controllers` has none (REQ-SF-004 rule 7).
- **FR-INV-010** No secret material (token, hash) in templates, events or logs: `InvitationSnapshot`,
  `InvitationRow`; the link reaches a page only with `show_link_when_undelivered`.

## Behaviour differences vs Beacon spec 179

- Users are referenced by security identifier (no `User` relations); display names are the host's concern.
- Projects, groups, organizations and seats are host grants (`InvitationGrantInterface` + payload).
- Inviting an e-mail that already has an account is not refused (no enumeration); acceptance then fails neutrally.
- The token moves into the session (Beacon kept it in the accept URL); the accept CSRF token binds the invitation.
- "Link shown once" is opt-in (`show_link_when_undelivered`); Beacon showed it whenever e-mail was off.
- No social-provider acceptance, no in-app notification, no "expired link opened" audit row (hosts listen to events).

## Success criteria

- 100 % line coverage of `src/`; PHPStan, Rector, CS, Igor, Twig lint, translations parity green.
- Kernel tests: flow, neutral pages, single use, CSRF binding, rate limits, purge, rule-7 kernel.
