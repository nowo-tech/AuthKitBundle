# SAML 2.0 Service Provider sign-in (2.10.0)

Organizations connect an identity provider that speaks SAML 2.0. AuthKit is the Service Provider (SP): it publishes the
SP metadata, sends a signed AuthnRequest and checks the response at its Assertion Consumer Service (ACS). A valid
response then takes the same path as an enterprise OpenID Connect callback ([SSO.md](SSO.md)): host callback steps, the
test handler, account linking and just-in-time creation, the MFA first-factor policy and the conflict answers.

Opt-in per profile (`saml.mode: disabled` by default). Only SP-initiated sign-in; responses the identity provider sends
on its own (IdP-initiated) are refused. Single Logout is not offered (sign-out is local).

Contract: [specs/011-saml-sp/spec.md](../specs/011-saml-sp/spec.md).

## Table of contents

- [Install](#install)
- [Configuration](#configuration)
- [Provider rows](#provider-rows)
  - [Create a row](#create-a-row)
  - [IdP metadata](#idp-metadata)
  - [Certificate rollover and SP key rotation](#certificate-rollover-and-sp-key-rotation)
  - [Certificate expiry](#certificate-expiry)
- [Routes and the sign-in flow](#routes-and-the-sign-in-flow)
- [Accounts: linking and just-in-time creation](#accounts-linking-and-just-in-time-creation)
- [Host callback steps and the test sign-in](#host-callback-steps-and-the-test-sign-in)
- [MFA after a SAML sign-in](#mfa-after-a-saml-sign-in)
- [Refusals and audit](#refusals-and-audit)
- [Security checks](#security-checks)
- [Worker mode and concurrency](#worker-mode-and-concurrency)
- [Not covered](#not-covered)

## Install

```bash
composer require onelogin/php-saml:^4.6 symfony/lock
```

The library is optional for the bundle and required by the feature: a container with a profile that sets
`saml.mode: enabled` fails to build without it, without symfony/lock (`lock.factory`), or, outside the `test`
environment, with an `attempt_limiter.cache_pool` kept in process memory (`cache.adapter.array`): the single-use claim
of a pending request must be seen by every worker and every replica. A filesystem pool serves the workers of one host;
several hosts need a shared pool (Redis, database), which the build cannot check. When the pool is unreachable the ACS
fails (an error, never a sign-in without the claim). The provider table `auth_kit_saml_provider` is mapped with the other
AuthKit entities; create it with a migration (`doctrine:migrations:diff`) or the shipped scripts in
`src/Resources/sql/saml/` (MySQL, MariaDB, PostgreSQL, SQLite; see [UPGRADING.md](UPGRADING.md#to-2100)).

The SP private keys are stored with nowo-tech/doctrine-encrypt-bundle: with
`nowo_auth_kit.doctrine.require_enterprise_secret_encryption` (default `true`) a row holding an SP private key is
refused at flush when that bundle does not encrypt on the entity manager.

## Configuration

```yaml
nowo_auth_kit:
    absolute_url:
        base_uri: 'https://app.example.com' # recommended: the ACS and entity id never follow the request Host
    social_login:
        create_user_if_missing: true        # just-in-time creation (also for SAML)
        require_verified_email: true        # default
    saml:
        mode: enabled
        clock_skew: 60          # seconds on NotBefore / NotOnOrAfter / SessionNotOnOrAfter (0-180)
        request_lifetime: 600   # seconds a started sign-in may take at the identity provider (60-3600)
        start_rate_limit: 20    # starts per client IP and window (0 disables)
        start_rate_window: 60
        routes:
            start:    { path: '/login/saml/{provider}',      name: nowo_auth_kit_saml_login_start }
            acs:      { path: '/login/saml/{provider}/acs',  name: nowo_auth_kit_saml_acs }
            metadata: { path: '/saml/{provider}/metadata',   name: nowo_auth_kit_saml_metadata }
```

Every route path holds `{provider}`. The SAML routes are never localized, also with `locale.in_path`: their URLs are
registered at the identity provider. Allow them anonymously in `security.yaml` (`^/login/saml` and `^/saml/` with
`PUBLIC_ACCESS`, on the firewall of the profile).

## Provider rows

One `Nowo\AuthKitBundle\Entity\SamlProvider` row per identity provider (table `auth_kit_saml_provider`). The key
(`provider`, `[a-z0-9_-]{1,59}`) is the route key; identities are linked as `saml:<key>` in `auth_kit_social_account`.
`scope` is an opaque host value (tenant, organization id) for `SamlProviderRepository::findByScope()`; AuthKit never
decides on it. A new row is disabled until `setEnabled(true)`.

| Field | |
|---|---|
| `idp_entity_id` | the only accepted `Issuer` |
| `idp_sso_url` | the IdP's SingleSignOnService of the HTTP-Redirect binding (`https://` only) |
| `idp_certificate`, `idp_next_certificate` | accepted signing certificates (plain PEM), each with `_sha256`, `_not_before`, `_not_after` |
| `sp_entity_id` | null: the absolute metadata URL |
| `sp_private_key` / `sp_certificate` | current SP pair (encrypted): signs AuthnRequests, decrypts assertions |
| `sp_next_private_key` / `sp_next_certificate` | next SP pair (encrypted): published and accepted for decryption during a rotation |
| `name_id_format` | requested `NameIDPolicy/@Format` (null: unspecified) |
| `attribute_mapping` | `subject`, `email`, `first_name`, `last_name`, `display_name`, `groups` → attribute `Name` (or `FriendlyName`) |
| `email_verified` | true: the identity provider is authoritative for the e-mail it asserts (default false) |
| `want_encrypted_assertions` | true: plain assertions are refused (default false; encrypted ones are always accepted) |
| `want_assertions_signed` | true: the Assertion itself must be signed (default false: a signature on the Response alone is accepted) |
| `require_persistent_subject` | true: without a `subject` attribute only a persistent NameID is a subject (never an e-mail or unspecified NameID) |
| `link_existing_account` | `reset_password` / `deny` / `keep`; null: the profile's `social_login.link_existing_account` |
| `mfa_first_factor`, `mfa_accepted_authn_contexts` | see [MFA after a SAML sign-in](#mfa-after-a-saml-sign-in) |

### Create a row

```php
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Saml\SamlIdpMetadataImporter;
use Nowo\AuthKitBundle\Saml\SamlSpKeyGenerator;

$pair = $keyGenerator->generate('app.example.com');      // SamlSpKeyGenerator: RSA 3072, self-signed, 730 days
$row  = (new SamlProvider())
    ->setProvider('org-1f0c')                             // the route key
    ->setScope($organizationId)
    ->setLabel('Example Corp')
    ->setSpKeyPair($pair->privateKeyPem, $pair->certificatePem)
    ->setAttributeMapping(['email' => 'mail', 'first_name' => 'givenName', 'last_name' => 'sn', 'groups' => 'memberOf'])
    ->setEmailVerified(true)                              // only for an IdP that owns the asserted addresses
    ->setLinkExistingAccount('deny');                     // never take over a local account by e-mail alone
$metadataImporter->import($row, $uploadedIdpMetadataXml); // or setIdpEntityId(), setIdpSsoUrl(), setIdpCertificates()
$row->setEnabled(true);
$em->persist($row);
$em->flush();
```

Never log, display or export the private key; the row's setters validate every value (the key must belong to its
certificate; certificates must carry an RSA key of at least 2048 bits). `SamlCertificate::fromPem()` (`pem`, `sha256`,
`notBefore`, `notAfter`) is public API, for example to show a certificate before storing it.

Persist the SP entity id at creation (`setSpEntityId('https://app.example.com/saml/<key>/metadata')`, the metadata URL, or your own URI): left null it is
computed per request from the metadata route, so it changes if the host name or `absolute_url.base_uri` changes. Without
`absolute_url.base_uri` the ACS URL and the default entity id follow the request's Host header.

The IdP entity id of a row is locked while `saml:<key>` links exist (`Saml\SamlIssuerGuard`): a flush that changes it is
refused, `SamlIssuerGuard::entityIdChangeError()` lets an administration screen say so first, and the metadata import
refuses it too. Unlink the accounts first, or create a row with a new key.

### IdP metadata

`SamlIdpMetadataImporter::import($row, $xml, $entityId = null)` reads the entity id, the HTTP-Redirect
SingleSignOnService and the signing certificates of an IdP metadata document (an aggregate needs `$entityId`) and
returns them (`SamlIdpMetadata`). Identical certificates count once; more than two distinct signing certificates are
refused (a row accepts the current one and one for a rollover). The row changes only when every value is accepted.
`parse($xml, $entityId)` is the dry run: the same checks and result, nothing written. AuthKit does not fetch metadata URLs: fetch the
document with your own HTTP client and SSRF policy (and refresh it on a schedule if you want), or let the administrator
upload it.

### Certificate rollover and SP key rotation

- **IdP certificate**: `setIdpCertificates($current, $next)` accepts signatures by either certificate; drop the old one
  once the identity provider switched.
- **SP key**: `setNextSpKeyPair($key, $certificate)` publishes the next certificate next to the current one in the SP
  metadata and decrypts with it too; after the overlap the identity provider needs (for example 14 days),
  `promoteNextSpKeyPair()` makes it current (AuthnRequests are then signed with it) and empties the next slot. The old
  key is dropped at once: an assertion still encrypted for it fails, so schedule the promotion after the identity
  provider switched (the overlap is the host's job).

### Certificate expiry

The expiry dates are plain columns, so a daily job can warn before a certificate expires:

```sql
SELECT provider, scope FROM auth_kit_saml_provider
WHERE idp_certificate_not_after < :soon OR idp_next_certificate_not_after < :soon
   OR sp_certificate_not_after < :soon OR sp_next_certificate_not_after < :soon;
```

## Routes and the sign-in flow

| Method | Path | Name | |
|---|---|---|---|
| `GET` | `/login/saml/{provider}` | `nowo_auth_kit_saml_login_start` | optional `?login_hint=<e-mail>`, `?force_authn=1` |
| `POST` | `/login/saml/{provider}/acs` | `nowo_auth_kit_saml_acs` | HTTP-POST binding |
| `GET` | `/saml/{provider}/metadata` | `nowo_auth_kit_saml_metadata` | `application/samlmetadata+xml` |

1. The host sends the browser to the start route (home-realm discovery stays in the host, as for OpenID Connect). A
   valid `login_hint` is forwarded to the identity provider; `force_authn=1` asks it to authenticate again
   (`ForceAuthn="true"`, also `SamlTestStart::start(..., forceAuthn: true)`). The start is a GET, as the OAuth start:
   another site can make a visitor start a sign-in (login CSRF at the start). The pending request is bound to the
   visitor's session, so only an identity the visitor authenticates at the identity provider can complete it.
2. AuthKit stores the pending request (request id, a random RelayState, expiry) in the session and redirects with a
   signed AuthnRequest (RSA-SHA256, current SP key).
3. The identity provider posts the response to the ACS. That cross-site POST does not carry a SameSite session cookie,
   so the first POST is answered with a small page that posts the same fields again from the application's own origin
   (automatically, or with *Continue*); the session is not touched before that.
4. The relayed POST is checked ([Security checks](#security-checks)), the pending request is used once, and the identity
   `saml:<provider>` is signed in (or handed to a host step).

The metadata route answers for any complete row, enabled or not, so it can be set up at the identity provider before it
is enabled; 404 for an unknown or incomplete row. It lists `AuthnRequestsSigned="true"`, `WantAssertionsSigned="true"`,
the requested NameID format and the current certificate for signing and encryption (plus the next one during a
rotation).

## Accounts: linking and just-in-time creation

A SAML identity follows exactly the rules of an enterprise OpenID Connect credential (`SocialAccountLinker`):

- **subject**: the mapped `subject` attribute, else the NameID unless it is transient, of another format than the row's
  `name_id_format`, or (with `require_persistent_subject`) not persistent. Prefer a persistent NameID or an immutable
  subject attribute: an e-mail address can later be given to someone else. A response without a stable subject, or a
  subject attribute with several values, is refused (`unusable_subject`).
- **e-mail**: the mapped `email` attribute (one value), else an `emailAddress` NameID; required (`missing_email`). It counts as
  verified only with `email_verified: true` on the row.
- an existing link of the subject signs in (the link remembers the IdP entity id: after the row moves to another entity
  id the old links no longer sign in);
- no link: an account with the e-mail is linked only with a verified-equivalent e-mail (`require_verified_email`) and
  per the row's `link_existing_account` (else the profile's);
- no account: created only with `social_login.create_user_if_missing: true`, a verified-equivalent e-mail and a
  `registration_mode` that allows it.

The link stores the mapped values (`email`, `name`, `first_name`, `last_name`, `groups`), never the assertion. The
SessionIndex and the NameID are not stored (a future Single Logout would need them).

## Host callback steps and the test sign-in

- A `SocialCallbackStepInterface` whose `supports()` accepts `saml:<provider>` owns the callback (for example to ask an
  existing account to prove itself before linking). `$context->socialProfile->samlAssertion` holds the checked
  assertion: `issuer`, `name_id`, `name_id_format`, `session_index`, `authn_context_class_refs`, `attributes`, `groups`,
  `assertion_id`. `verifiedEmail()` answers the e-mail only for an `email_verified` row.
- **Test sign-in**: an administrator action (POST with a CSRF token) calls
  `Nowo\AuthKitBundle\Saml\SamlTestStart::start($request, $provider, $hint)` and redirects to the returned URL. The
  response runs every check, then reaches only the host's `SocialLoginTestHandlerInterface` (provider `saml:<key>`); it
  never links, creates or signs in. A disabled row can be tested; no URL parameter starts a test.

## MFA after a SAML sign-in

A SAML sign-in is the `social` first factor (`mfa.first_factors.social`) unless the row sets its own policy:

| `mfa_first_factor` | |
|---|---|
| null | the profile's `mfa.first_factors.social` |
| `required` | the user's second step is asked |
| `exempt` | no second step |
| `exempt_when_authn_context` | no second step only when the assertion's `AuthnContextClassRef` is one of `mfa_accepted_authn_contexts` |

Unknown stored values ask for the second step. Forced enrolment still applies.

## Refusals and audit

Every refused relayed POST is logged at warning level with the provider key and a reason code only, and dispatched as
`Nowo\AuthKitBundle\Saml\Event\SamlResponseRejectedEvent` (`profile`, `provider`, `reason`):

`unsolicited`, `invalid_state`, `expired_request`, `replayed`, `malformed`, `status`, `signature`, `in_response_to`,
`destination`, `subject_confirmation`, `audience`, `issuer`, `timing`, `encryption_required`, `decryption`,
`unusable_subject`, `missing_email`, `unavailable`.

The reason code is also on the request (attribute `_nowo_auth_kit_saml_failure`,
`SamlLoginController::FAILURE_ATTRIBUTE`); the event is always dispatched before a host step's `handleFailure()`, in the
same request.

The user sees the social error messages (`social.error.invalid_state` for an unsolicited or crafted POST,
`social.error.failed` otherwise). A host step gets `handleFailure()` with `SocialCallbackFailure::Unavailable` (row
unknown, disabled or incomplete) or `Failed`, only once the pending request was proved.

## Security checks

onelogin/php-saml runs in strict mode, then AuthKit checks the same document again, exactly and with the configured skew:

- **SP-initiated only**: `InResponseTo` (on the Response and on a bearer `SubjectConfirmationData`) must equal the
  request id stored in this session for this provider; no pending request: `unsolicited`.
- **single use**: the pending request is removed from the session and its id is claimed once in the attempt limiter's
  store under its lock before the response is parsed, so parallel posts of one session cannot both use it; it expires
  after `request_lifetime`. A RelayState that does not match leaves it untouched.
- **signature always required** on the Response or the Assertion, verified with the row's certificates only (never a
  certificate the document carries). A signature on the Response alone is accepted although the SP metadata says
  `WantAssertionsSigned="true"` (many identity providers sign only the Response); `want_assertions_signed` on the row
  enforces the stricter rule. Exactly one assertion (encrypted or not) in the whole document, one signature per
  signed element referencing its parent's ID, and the data is read from the signed assertion (signature wrapping
  refused). SHA-1 signatures and digests are refused.
- **Destination** required and equal to the ACS URL; **Recipient** equal to the ACS URL; **Audience** present and equal
  to the SP entity id in every AudienceRestriction; every **Issuer** equal to `idp_entity_id`; `NotBefore`,
  `NotOnOrAfter` and `SessionNotOnOrAfter` with `clock_skew` (the library also applies its own fixed drift).
- **encryption**: encrypted assertions are decrypted with the current or the next SP key; RSA 1.5 key transport is
  refused; `want_encrypted_assertions` refuses plain ones. CBC data encryption (`aes*-cbc`) has no integrity of its own:
  it is accepted only inside a signed Response and refused before decryption otherwise; GCM is always accepted. The SHA-1
  refusal concerns signatures only (an OAEP key transport naming a SHA-1 mask digest is fine).
- **XML** is parsed without DTD or external entities; a response over 512 KiB is refused before decoding; parse errors
  never surface as PHP warnings.
- **logs**: never the response, the assertion, NameIDs, attribute values or keys.
- the ACS needs no CSRF token: the RelayState and `InResponseTo` bound to the visitor's session are its CSRF protection.

## Worker mode and concurrency

The services keep no per-request state (FrankenPHP worker mode with `kernel.reset: false`): the pending request lives in
the session, its single-use claim in the attempt limiter's cache pool (shared by every worker and replica; it is kept for
`request_lifetime` + 180 s + 60 s, longer than any response that could still pass). onelogin's
static URL helpers are pinned to the ACS URL for one validation and reset in `finally`.

Linking and creation run in AuthKit's unit of work under the named locks of the social pipeline (REQ-RUNTIME-003 rule
6): two first sign-ins of one identity never create two accounts; a deadlock, lock wait or lock not obtained answers
`409` with `CONFLICT_001` and `Retry-After` (or `CONFLICT_002` when part of the request was already committed), nothing
of the failed unit is kept, and the host entity manager is never cleared, reset or closed. See
[CONCURRENCY.md](CONCURRENCY.md).

## Not covered

IdP-initiated sign-in, Single Logout, the artifact binding, fetching IdP metadata by URL, signed SP metadata and role or
group decisions (groups reach host steps only).
