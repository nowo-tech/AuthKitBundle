# Invitations

Invite people by e-mail instead of (or besides) the open registration. Opt-in per profile since **1.32**
(`invitations.mode: disabled` by default: no entity, no route, no page).

## Table of contents

- [How it works](#how-it-works)
- [Enable](#enable)
- [Registration modes](#registration-modes)
- [Configuration reference](#configuration-reference)
- [Administration pages](#administration-pages)
- [Accept page](#accept-page)
- [E-mail delivery](#e-mail-delivery)
- [PHP API](#php-api)
- [Host grants (InvitationGrantInterface)](#host-grants-invitationgrantinterface)
- [Host with its own invitation UI (1.33)](#host-with-its-own-invitation-ui-133)
- [Audit events](#audit-events)
- [Purge](#purge)
- [Database schema](#database-schema)
- [Security notes](#security-notes)

## How it works

1. An administrator invites `ana@example.com` (optionally with roles listed in `invitations.roles` that the
   administrator holds, a validity and a personal message). Nothing exists in the user table yet.
2. AuthKit stores a bundle-owned `Invitation` (`auth_kit_invitation`, table prefix aware) with the **SHA-256 hash**
   of a 32-byte random token, and e-mails the single-use link `https://<absolute_url.base_uri>/invitation/<token>`.
3. Opening the link moves the token hash into the session and redirects to `/invitation` (the token never stays in
   the address bar, the history, a `Referer` or a rendered page).
4. The page is AuthKit's **registration form** with the invited e-mail pre-filled (read-only with
   `lock_email: true`). Submitting creates the account (registration fields, hashing, `registration_role` plus the
   invitation roles), claims the invitation (one conditional `UPDATE`: single use), runs the host
   [grants](#host-grants-invitationgrantinterface) — all in one transaction — then signs the account in.
5. Resend issues a new token (the previous link stops working at once) and a new expiry; revoke stops the link.
   Expiry is derived from `expires_at` (no scheduled job); `nowo:auth-kit:invitations:purge` deletes old closed rows.

## Enable

```yaml
# config/packages/nowo_auth_kit.yaml
nowo_auth_kit:
    user_class: App\Entity\User
    registration_mode: invite_only          # accounts only from invitations (or keep first_user_only / always)
    absolute_url:
        base_uri: '%env(DEFAULT_URI)%'      # required: links are never built from the request Host
    invitations:
        mode: enabled
        roles: [ROLE_EDITOR]                # grantable roles (the inviter must hold each one)
        security:
            access_roles: [ROLE_ADMIN]      # who may invite, resend and revoke
```

```yaml
# config/packages/security.yaml — firewall layer for the administration pages (REQ-UI-002)
security:
    access_control:
        - { path: ^/admin/invitations, roles: ROLE_ADMIN }
```

Run the [schema change](#database-schema). The routes come from the AuthKit route import you already have
(`@NowoAuthKitBundle/Resources/config/routing.yaml`, type `nowo_auth_kit`); they are **never** registered by the
Symfony `routing.controllers` import (REQ-SF-004 rule 7).

Requirements when `mode: enabled` (the container refuses to compile otherwise): `absolute_url.base_uri`, a
registration field for the e-mail, SecurityBundle (unless `security.allow_unauthenticated`), and
`framework.csrf_protection` + `framework.form.csrf_protection`.

## Registration modes

| `registration_mode` | `/register` | Invitation acceptance (`invitations.mode: enabled`) |
| --- | --- | --- |
| `disabled` | redirects to sign-in | refused (404) — closed |
| `first_user_only` (default) | until the first user exists | allowed (users may already exist) |
| `always` | open | allowed |
| `invite_only` (1.32) | redirects to sign-in (route skipped with `routing.skip_disabled_features`) | allowed — the only way in |

`invite_only` requires `invitations.mode: enabled`. Social login never creates accounts while the registration page
is closed (`invite_only` included).

## Configuration reference

All keys live under `profiles.<name>.invitations` (or the flat `invitations` of the default profile).

| Key | Default | Description |
| --- | --- | --- |
| `mode` | `disabled` | `enabled` registers the entity, routes, pages and command |
| `ttl_days` | `7` | Validity of a link in days (1–90); resend restarts it |
| `ttl_choices` | `[1, 3, 7, 14, 30]` | Validities offered in the invite form (`ttl_days` always included) |
| `lock_email` | `true` | Invited e-mail read-only on the accept page (enforced server-side). `false`: pre-filled, editable |
| `email_field` | `null` | Registration field receiving the e-mail (null: the field mapped to `user_identifier_field`, else `email`) |
| `roles` | `[]` | Roles an inviter may grant (`ROLE_*`); intersected with this list again on acceptance |
| `registration_role` | `null` | 1.33: base role of an invited account instead of the profile `registration_role` (e.g. `ROLE_USER` when `registration_role` is `ROLE_ADMIN` for the first user). null: `registration_role` |
| `merge_key` | `null` | 1.33: payload key that, with the e-mail, identifies the open invitation a re-invite updates (one open invitation per e-mail and `payload[merge_key]`, compared as strings: `7` ≡ `'7'`, `true` ≡ `'1'`, `false` ≡ `'0'`; an absent key is `null`, so key-less invitations merge with each other and never with a keyed one; `''` and `'0'` never equal `null`; the value must be a scalar or null). More than 500 open invitations of one e-mail: `TOO_MANY_OPEN` (nothing created). null: one per e-mail |
| `payload_merge` | `replace` | 1.33: how a re-invite merges its payload. `replace`: top-level keys replaced (1.32). `deep`: maps merged recursively, lists unioned, **a later scalar wins** (a re-invite can downgrade e.g. a project role from `manager` to `member`; pre-merge your grants and use `replace` if that matters) |
| `admin_routes` | `true` | 1.33: `false` leaves the administration routes (`index`, `new`, `resend`, `revoke`) unregistered for hosts with their own UI; the accept route stays |
| `show_link_when_undelivered` | `false` | When the e-mail cannot be sent, show the link once to the inviter (no-store page) |
| `purge_after_days` | `90` | Age used by the purge command |
| `invite_rate_limit` / `invite_rate_window` | `20` / `3600` | Invitations created or resent per inviter and window |
| `resend_rate_limit` / `resend_rate_window` | `3` / `3600` | Sends of one invitation per window (a re-invite counts) |
| `accept_rate_limit` / `accept_rate_window` | `10` / `900` | Accept page hits (opening a link, submitting) per client IP |
| `mail.from` | `null` | Sender (null: the mailer default headers / envelope) |
| `mail.html_template` / `mail.text_template` | `@NowoAuthKitBundle/email/invitation.{html,txt}.twig` | E-mail bodies |
| `admin.per_page` | `25` | Page size of the list (10, 25, 50 or 100; `?per_page=` picks among them) |
| `admin.layout_template` | `null` | Layout of the administration pages (null: `nowo_ui_kit.layouts.admin`) |
| `admin.templates.index` / `new` / `link` | bundle templates | Page template overrides |
| `security.access_roles` | `[ROLE_ADMIN]` | Required roles (any). An empty list is refused (it would let every signed-in user invite) |
| `security.access_checker` | `null` | Service id implementing `InvitationsAccessCheckerInterface` (custom rules) |
| `security.allow_unauthenticated` | `false` | **DEV/DEMO only**, literal boolean, refused unless `kernel.debug` is true |
| `routes.accept` | `/invitation/{token}`, `nowo_auth_kit_invitation_accept` | Accept page (the token-less `/invitation` is the same route). The path must end with `/{token}` (1.33); token: 43 base64url characters. With `locale.in_path`: `false` → `/invitation/{token}`; `true` → only `/{_locale}/invitation/{token}` (a bare link is 404); `both` → `/{_locale}/…` plus the bare path, which redirects to the localized URL (`unlocalized: redirect`, token kept in the path) or serves it in the default locale (`serve`) |
| `routes.index` / `new` / `resend` / `revoke` | `/admin/invitations…`, `nowo_auth_kit_invitations…` | Administration routes (paths must start with a single `/`) |

Route names must be unique across profiles (checked for the routes a profile registers).

## Administration pages

UiKit scaffolds (REQ-UI-003) on `nowo_ui_kit.layouts.admin`, FormKit forms (REQ-TWIG-005 rule 11, profile `auth_kit`):

| Route | Page |
| --- | --- |
| `GET /admin/invitations` | List (`?q=` e-mail search, `?status=open|pending|expired|accepted|revoked|all`, bounded pagination) with Resend / Revoke on open rows |
| `GET, POST /admin/invitations/new` | Invite form (`InvitationFormType`: e-mail, roles, validity, message) |
| `POST /admin/invitations/{id}/resend` | New link and expiry (FormKit CSRF-only form, token id `nowo_auth_kit_invitation_resend/<profile>/<id>`) |
| `POST /admin/invitations/{id}/revoke` | Stop the link (token id `nowo_auth_kit_invitation_revoke/<profile>/<id>`) |

Every action checks the access checker first (anonymous: the firewall entry point; refused: 403); every POST needs
its CSRF token (fail closed: 403). Responses are `private, no-store`; redirects are application paths only. Rows
carry no token and no hash. Inviting an e-mail that already has an account looks exactly like inviting a new one (no
account enumeration); the invitee then sees "could not be accepted — sign in" on the accept page.

**Role rule.** An administrator hands out only roles of `invitations.roles` they hold (role hierarchy), and may
resend, re-invite (which merges into the open invitation of the same address) or revoke only an invitation whose
**every** role they hold: Resend / Revoke are hidden on other rows, a forced request is refused ("it grants a role you
do not hold"), and the link shown once (`show_link_when_undelivered`) is never returned to an actor lacking a role.
"Resend" re-issues the invitation's original roles. Nothing is sent while `registration_mode` is `disabled` (a link
could not be accepted) or when `absolute_url.base_uri` resolves empty at runtime (the link would follow the request
Host): the administrator sees why. Sends by one administrator to one address — invite, re-invite, resend, and invite
again after a revoke — are limited to `resend_rate_limit + 1` per `resend_rate_window` (per administrator, so one
cannot block an address for the others); a refusal carries `retryAfter` (the latest retry time, shown in the flash).

## Accept page

`/invitation/{token}` → session → `/invitation`: the registration page (`web_ui.templates.invitation_accept`, extends
`register.html.twig`) with the message of the inviter above the fields and the form CSRF token bound to the invitation
(`nowo_auth_kit_invitation_accept/<profile>/<public id>`). Form-less notices (`web_ui.templates.invitation_invalid`):

| Kind | When |
| --- | --- |
| `invalid` | Unknown, used, revoked or replaced token — one neutral page for all |
| `expired` | Expired, still the current token |
| `rate_limited` | `accept_rate_limit` reached (429) |
| `signed_in` | A signed-in visitor (sign out first; no account merge) |
| `not_accepted` | The account could not be created (the e-mail got an account, a grant refused) |

None shows invitee data; all are `private, no-store`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`.
Scalar `payload` values pre-fill the mapped, non-password registration fields of the same name.

Things to know:

- **One invitation per browser.** Opening a link stores it in the session (one per profile); the last link opened
  wins, and a form of another invitation left open in a tab is refused (its CSRF token names that invitation).
  Opening a link from a third-party page shows that link's invitation — always with its invited address — so a
  visitor should only accept an invitation for their own address.
- **Existing account, any case.** If an account exists for the invited address in any letter case
  (`Mixed@Example.com` for `mixed@example.com`), the acceptance is refused (`not_accepted`), also on case-sensitive
  databases. Residual: with `registration_mode: always` on a case-sensitive database, an open registration of a case
  variant committed between that check and the insert can still produce two accounts (use `invite_only`, or store
  identifiers lower-cased).
- **`lock_email: false`** lets the link holder register another address; typing an address that already has an
  account answers `not_accepted` (an existence check bounded by `accept_rate_limit`). Keep the default `true` unless
  needed.
- **Sign-in after the commit.** The account is committed before `Security::login()`: a user checker that refuses the
  sign-in (e.g. accounts awaiting verification) sends the invitee to the sign-in page, keeps the account and consumes
  the invitation — as with the open registration. The profile firewall needs `form_login`.

## E-mail delivery

`InvitationMailerInterface` is `MailerInvitationMailer` (Symfony Mailer: subject `invitation.email.subject`, bodies
from `mail.html_template` / `mail.text_template`, invitation locale) when `symfony/mailer` is wired, else
`NullInvitationMailer`. Override by aliasing the interface:

```yaml
# config/services.yaml
services:
    Nowo\AuthKitBundle\Invitation\InvitationMailerInterface: '@App\Mailer\BrandedInvitationMailer'
```

Templates receive `invitation` (`InvitationSnapshot`, no hash), `accept_url`, `resend` and `brand`. A transport
error is logged (public id and exception class only) and reported as "not sent" (or the link shown once with
`show_link_when_undelivered`). Override the texts in `translations/NowoAuthKitBundle.<locale>.yaml`
(`invitation.email.*`).

## PHP API

```php
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;

$outcome = $manager->invite($this->getUser(), 'default', new InvitationRequest(
    email: 'ana@example.com',
    roles: ['ROLE_EDITOR'],          // must be in invitations.roles (InvalidArgumentException otherwise)
    ttlDays: 14,
    message: 'Welcome to the team',
    locale: 'es',                    // one of locale.enabled, else the default
    payload: ['projects' => ['3f…' => 'member']],
));

match ($outcome->status) {
    InvitationOutcome::SENT, InvitationOutcome::LINK => …,  // LINK: $outcome->url, show once
    InvitationOutcome::NOT_DELIVERED => …,
    default => …,                    // RATE_LIMITED, NOT_OPEN
};
```

`InvitationManager::resend()` and `revoke()` take an `Invitation` (`InvitationRepository::findOneByPublicId()`);
`revoke()` returns an outcome (`REVOKED`, `NOT_OPEN`, `NOT_ALLOWED`). The API enforces `invitations.roles` (forged
roles throw) and the role rule above against the given actor (`InvitationsAccessGuard::holdsAll()`, role hierarchy):
`NOT_ALLOWED` when the actor lacks a role of the request or of the open invitation — a `null` actor (console) can
only handle invitations without roles. Other refusals: `RATE_LIMITED` (with `retryAfter`), `CLOSED`, `NOT_CONFIGURED`.

**Pass the signed-in user as the actor** (`Security::getUser()`, or the refreshed token user), never a user object
built or modified from request data: `holdsAll()` trusts the roles of the object it is given (it builds a
`UsernamePasswordToken` from `$actor->getRoles()` on the firewall name `nowo_auth_kit_invitations` and asks the access
decision manager, so the role hierarchy applies; custom voters answering `ROLE_*` from the token type or firewall see
that synthetic token).

`InvitationAcceptor::accept()` clears the default entity manager when an acceptance fails (nothing of it may be
flushed later): call it with no pending changes of your own.

### Demoted or removed inviters

On acceptance the roles are re-checked against `invitations.roles` (the configuration), **not** against the inviter.
When an inviter is demoted, disabled or deleted, revoke their open invitations from your listener:

```php
$manager->revokeOpenBy($inviter->getUserIdentifier());   // returns the count; one InvitationRevokedEvent each
```

## Host grants (InvitationGrantInterface)

Implement `Nowo\AuthKitBundle\Invitation\InvitationGrantInterface` (autoconfigured, tag
`nowo_auth_kit.invitation_grant`) to apply what the payload carries — memberships, groups, a seat. It runs inside the
acceptance transaction after the account is persisted; throw `InvitationRefusedException` to refuse (everything is
rolled back, the invitee sees `not_accepted`). Re-check there anything the inviter may no longer grant.

## Host with its own invitation UI (1.33)

For a host (e.g. Symfony Beacon) that issues invitations from its own pages and sends its own e-mail, keeping
AuthKit's storage, accept page and rules:

```yaml
nowo_auth_kit:
    registration_mode: invite_only        # the intended production mode: accounts only by invitation
    registration_role: ROLE_ADMIN         # first-user path (when used)
    invitations:
        mode: enabled
        registration_role: ROLE_USER    # invitees never get registration_role
        roles: [ROLE_MANAGER, ROLE_DEVELOPER]
        merge_key: organization           # one open invitation per (e-mail, payload.organization)
        payload_merge: deep               # re-invites merge maps and union lists
        admin_routes: false               # no /admin/invitations pages (REQ-SF-004 r7: still opt-in per feature)
```

`registration_mode` decides whether invitations work: `invite_only` closes `/register` and social sign-up while
invitations are sent and accepted; `first_user_only` and `always` accept them too; **`disabled` refuses both
sending (`InvitationOutcome::CLOSED`) and accepting** (the accept page answers 404, the social acceptance
`no_invitation`).

**Authorise every action (required when invitations carry no roles).** AuthKit authorises by Symfony roles only;
for an invitation without roles (e.g. Beacon: base role from `registration_role`, membership and org role in the
payload) that check always passes, so any actor could resend another organization's invitation or merge into it.
Implement `Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface` (autoconfigured, tag
`nowo_auth_kit.invitation_action_authorizer`):

```php
public function isGranted(?UserInterface $actor, string $action, Invitation|InvitationRequest $subject, ?InvitationRequest $request = null): bool
```

It is asked for `invite` (the request), `reinvite` (the open invitation a re-invite would merge into, with the
request), `resend`, `revoke` (the invitation) and `return_link` (request or invitation). Every authorizer must grant
(deny only; AuthKit's role rule still applies); a denial answers `NOT_ALLOWED`. Check the actor on
`payload[merge_key]` and on every payload grant — with `payload_merge: deep`, on what the merge would produce.
`Invitation::getLastSentBy()` / `InvitationSnapshot::$lastSentBy` record who issued the current link (invite,
re-invite or resend); `invitedBy` stays the creator, so grants can re-check the right person.

**Issue and resend, the host sends the mail.** `returnLink: true` sends no e-mail and returns the link
(`InvitationOutcome::LINK`, `$outcome->url`; the events carry `delivery: host`). A returned link needs a non-null
actor (`NOT_ALLOWED` otherwise) and the `return_link` consent of the authorizers. The token is never logged; keep the
URL out of your own logs and flash messages too. The role rules are unchanged: an actor lacking a role of the
request or of the open invitation gets `NOT_ALLOWED` and no link.

```php
$outcome = $manager->invite($this->getUser(), 'default', new InvitationRequest(
    email: 'ana@example.com', roles: ['ROLE_MANAGER'], payload: ['organization' => $org->getId()], returnLink: true,
));
$outcome = $manager->resend($this->getUser(), $invitation, ttlDays: 14, returnLink: true); // ttl among ttl_choices
```

**Pre-accept refusal.** Listen to `InvitationAcceptingEvent` (before the transaction) and call
`$event->refuse(new InvitationRefusal('invitations.no_seat_left', ['%org%' => $name], 'messages'))`; or, inside a
grant (same transaction as the account), throw `InvitationRefusedException::because(new InvitationRefusal(…))`. The
invitee sees the translated reason on the `not_accepted` page, nothing is created and the invitation stays pending.
A listener cannot accept anything: deny by default stays (pending invitation, current token, `invitations.roles`
re-check, grants); an exception thrown by a listener refuses the acceptance (logged as an error; nothing created). Use translation keys,
never request data, as reasons. `InvitationAcceptor::attempt()` returns the `InvitationAcceptance` (status, user,
refusal) for hosts that drive the acceptance themselves; `accept()` keeps returning `?UserInterface`.

**Dead links.** `InvitationLinkRejectedEvent` (`invitation` snapshot, `reason`: `expired` / `revoked`,
`invitationId()`, `profile()`) is dispatched when the current link of an expired or revoked invitation is opened on
the accept page, at most once per invitation per hour (whatever the client IP); unknown, replaced and used links
dispatch nothing. No token, hash or link.

**Social acceptance.** Do not read `_nowo_auth_kit_invitation.<profile>` from the session. After the invitee opened
the link (AuthKit's accept page stores it) and came back from your social provider callback (you verified the OAuth
state and code):

```php
$pending = $acceptor->pending($request->getSession(), 'default');  // ?InvitationSnapshot, e.g. to show the button
$result  = $acceptor->acceptPendingForSocial($request->getSession(), 'default', $socialProfile, ['nickname' => $name]);
if ($result->isAccepted()) {
    $linker->linkOrCreate($profile, $provider, $socialProfile, $tokens);   // finds the new account by e-mail
    $security->login($result->user, 'form_login', $profile->firewall);
}
```

The provider must assert a **verified** e-mail equal to the invited one (`email_mismatch` otherwise): an ASCII
address compares case-insensitively (ASCII only), any other is NFC-normalised and compared byte for byte, so no
compatibility character (e.g. U+212A KELVIN SIGN) matches an ASCII letter; password fields
are dropped, the invited e-mail is forced; the pre-accept event, roles re-check, grants and single use apply; the
session entry is removed whatever the outcome (`accepted`, `no_invitation`, `expired`, `email_mismatch`,
`refused`, `not_accepted`).

## Audit events

Dispatched after every mutation, with an `InvitationSnapshot` (never the token, its hash or the link), the actor and
the time: `InvitationCreatedEvent` / `InvitationResentEvent` (`delivery`: `email`, `link`, `host` (1.33), `none`),
`InvitationRevokedEvent`, `InvitationAcceptedEvent` (`user`), and `InvitationsPurgedEvent` (count). 1.33 hooks:
`InvitationAcceptingEvent` (pre-accept, may refuse) and `InvitationLinkRejectedEvent` (expired / revoked link
opened).

## Purge

```bash
php bin/console nowo:auth-kit:invitations:purge                 # every enabled profile, purge_after_days each
php bin/console nowo:auth-kit:invitations:purge --days=30 --auth-profile=default --dry-run
php bin/console nowo:auth-kit:invitations:purge --include-accepted
```

Deletes revoked invitations and expired ones never accepted whose last change is older than the age (accepted ones
only with `--include-accepted`), in batches of 500. Schedule it (cron, Scheduler).

## Database schema

One table, `auth_kit_invitation` (prefixed by `doctrine.table_prefix`), mapped with the other AuthKit entities
(`doctrine.register_mapping: auto` maps them when invitations are enabled). Generate a migration in the host:

```bash
php bin/console doctrine:migrations:diff
```

Columns: `public_id` (unique), `profile`, `email`, `roles` (JSON), `payload` (JSON), `message`, `locale`, `token_hash`
(unique, SHA-256), `ttl_days`, `expires_at`, `sent_at`, `send_count`, `invited_by`, `accepted_at`, `accepted_by`,
`revoked_at`, `revoked_by`, `created_at`. Users are referenced by their security identifier.

## Security notes

See [SECURITY.md](SECURITY.md#threat-model) (rows "Invitations"). In short: hash-only tokens compared with
`hash_equals`, single use by a conditional update, expiry, one neutral answer for invalid links, no enumeration,
per-inviter / per-invitation / per-IP rate limits, CSRF bound to action and target (fail closed), path-only redirects,
links built on `absolute_url.base_uri`, no secret in templates, events or logs, bounded lists.
