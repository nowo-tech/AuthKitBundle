# Feature Specification: SAML 2.0 Service Provider sign-in

**Feature Branch**: `011-saml-sp` (`feat/2.10.0-saml-sp`)  
**Status**: Contract (target v2.10.0, unreleased)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Data**: new table `auth_kit_saml_provider`; linked identities reuse `auth_kit_social_account` (provider `saml:<key>`)  
**Library**: `onelogin/php-saml` `^4.6` (optional: `suggest` + `conflict <4.6`; required only when a profile enables SAML)  
**Origin**: host request *SAML 2.0 SP* (organization single sign-on, block C), design [`docs/SAML.md`](../../docs/SAML.md)

---

## Summary

Organization administrators connect an identity provider that speaks SAML 2.0. The host owns the organization side
(which tenant owns which provider row, verified domains, home-realm discovery, provisioning policy); AuthKit owns the
protocol: SP metadata, the AuthnRequest (HTTP-Redirect binding), the Assertion Consumer Service (HTTP-POST binding) and
every check of the response. A valid response becomes the same `SocialUserProfile` an OpenID Connect sign-in produces
and goes through the same pipeline: host callback steps (`SocialCallbackStepInterface`), the test handler
(`SocialLoginTestHandlerInterface`), `SocialAccountLinker` (account linking, just-in-time creation, named locks,
REQ-RUNTIME-003 rule 6), MFA first-factor policy and the conflict answers. Opt-in, no BC break: nothing changes until a
profile sets `saml.mode: enabled` and a provider row exists.

Only SP-initiated sign-in. IdP-initiated (unsolicited) responses are refused. Single Logout is out of scope (2.10.0).

## User scenarios

### US-01 — SP-initiated sign-in (P1)

**Given** an enabled provider row `acme` and a profile with `saml.mode: enabled`, **When** the host redirects the
browser to `nowo_auth_kit_saml_login_start` (`/login/saml/acme`, optional `?login_hint=<e-mail>`), **Then** AuthKit
stores a pending request (request id, RelayState, expiry) in the session and redirects to the IdP's SSO URL with a
signed AuthnRequest (HTTP-Redirect). **When** the IdP posts the response to the ACS, **Then** the cross-site POST is
relayed same-site (no session touched on the first POST), every check below runs, and the user is linked / created /
signed in exactly as by an enterprise OpenID Connect credential.

### US-02 — Refusals (P1)

An unsigned document, a signature that does not verify with the configured IdP certificate(s), a wrapped document (more
than one assertion, signature not on the Response or the Assertion, reference not the signed element's ID), a wrong or
missing `InResponseTo`, a replayed response (the pending request is single use), an expired request, a wrong
`Destination` / `Recipient` / `Audience` / `Issuer`, an assertion outside `NotBefore` / `NotOnOrAfter` (+ skew), an IdP
status other than Success, an unsolicited response, a transient or missing NameID (without a `subject` attribute), or a
missing e-mail where one is needed: refused, nothing linked, nobody signed in, the reason logged at warning level by
code only and dispatched as `SamlResponseRejectedEvent`.

### US-03 — Test sign-in (P1)

**Given** a registered `SocialLoginTestHandlerInterface`, **When** an administrator action calls
`SamlTestStart::start($request, $provider, $hint)`, **Then** the pending request carries a one-shot test marker; its
response runs every check and reaches only the test handler (`SocialCallbackContext` with provider `saml:<key>`): never
links, provisions or signs in. A disabled provider can be tested.

### US-04 — Metadata and rotation (P1)

**Given** a provider row, **When** the IdP administrator downloads `/saml/acme/metadata`, **Then** the XML lists the SP
entity id, the ACS (HTTP-POST), `AuthnRequestsSigned="true"`, `WantAssertionsSigned="true"` and the signing / encryption
certificate; during a rotation (next slot filled) both certificates. `promoteNextSpKeyPair()` makes the next pair
current.

### US-05 — Certificate expiry in SQL (P2)

The host queries `idp_certificate_not_after`, `idp_next_certificate_not_after`, `sp_certificate_not_after` and
`sp_next_certificate_not_after` (plain columns) to warn before a certificate expires.

## Contract

### Entity `Nowo\AuthKitBundle\Entity\SamlProvider` — table `auth_kit_saml_provider`

| Column | Type | Rules |
|---|---|---|
| `id` | int, generated | |
| `provider` | varchar(59), unique `uniq_auth_kit_saml_provider_key` | `[a-z0-9_-]{1,59}`; the route key. Linked identities use `saml:<provider>` (≤ 64, the `auth_kit_social_account.provider` column; the colon never appears in an OAuth provider key, so the two namespaces never collide) |
| `scope` | varchar(191) null, index `idx_auth_kit_saml_provider_scope` | opaque host value (tenant, organization id); AuthKit never decides on it |
| `label` | varchar(128) | |
| `enabled` | bool, default false | a new row signs nobody in until enabled (deny by default) |
| `idp_entity_id` | varchar(512) | the only accepted `Issuer` |
| `idp_sso_url` | varchar(512) | `https://` only; HTTP-Redirect binding |
| `idp_certificate` | text | PEM, plain |
| `idp_certificate_sha256` | char(64) | lowercase hex SHA-256 of the DER |
| `idp_certificate_not_before` / `_not_after` | datetime_immutable | from the certificate |
| `idp_next_certificate` (+ `_sha256`, `_not_before`, `_not_after`) | nullable | second accepted signing certificate (rollover) |
| `sp_entity_id` | varchar(512) null | null = the absolute metadata URL |
| `sp_private_key` / `sp_certificate` | text, `#[Encrypted]` | current SP pair (signs AuthnRequests, decrypts assertions) |
| `sp_certificate_not_after` | datetime_immutable | plain copy for SQL |
| `sp_next_private_key` / `sp_next_certificate` | text null, `#[Encrypted]` | next pair (published, decrypts) |
| `sp_next_certificate_not_after` | datetime_immutable null | |
| `name_id_format` | varchar(128) null | requested `NameIDPolicy/@Format`; null = unspecified |
| `attribute_mapping` | json | keys `subject`, `email`, `first_name`, `last_name`, `display_name`, `groups` → attribute names (`Name` or `FriendlyName`) |
| `email_verified` | bool, default false | true: the IdP is authoritative for the asserted e-mail (it counts as `email_verified`) |
| `want_encrypted_assertions` | bool, default false | true refuses plain assertions |
| `link_existing_account` | varchar(16) null | `reset_password` / `deny` / `keep`; null = the profile's `social_login.link_existing_account`; unknown = `deny` |
| `mfa_first_factor` | varchar(32) null | `required` / `exempt` / `exempt_when_authn_context`; null = `mfa.first_factors.social`; unknown = `required` |
| `mfa_accepted_authn_contexts` | json null | `AuthnContextClassRef` values that exempt (≤ 16, ≤ 255 bytes each) |
| `created_at` / `updated_at` | datetime_immutable | |

Write rules: setters validate (key pattern, HTTPS SSO URL, PEM certificates parse, the private key matches its
certificate, mapping keys known). With `doctrine.require_enterprise_secret_encryption` (default true) a row holding an
SP private key is refused at flush unless nowo-tech/doctrine-encrypt-bundle encrypts on that manager (as enterprise
OIDC secrets, 2.8.0). SQL scripts for MySQL, MariaDB, PostgreSQL and SQLite in `src/Resources/sql/saml/`.

### Configuration `nowo_auth_kit.profiles.<name>.saml`

| Key | Default | |
|---|---|---|
| `mode` | `disabled` | `enabled` loads the SAML services and routes for the profile |
| `clock_skew` | `60` | seconds, 0–180, on `NotBefore` / `NotOnOrAfter` / `SessionNotOnOrAfter` |
| `request_lifetime` | `600` | seconds, 60–3600, of a pending AuthnRequest |
| `start_rate_limit` / `start_rate_window` | `20` / `60` | starts per client IP (attempt limiter); 0 disables |
| `routes.start` | `/login/saml/{provider}`, `nowo_auth_kit_saml_login_start` | GET |
| `routes.acs` | `/login/saml/{provider}/acs`, `nowo_auth_kit_saml_acs` | POST |
| `routes.metadata` | `/saml/{provider}/metadata`, `nowo_auth_kit_saml_metadata` | GET, `application/samlmetadata+xml` |

Linking and provisioning follow the profile's `social_login.create_user_if_missing`, `require_verified_email`,
`link_existing_account` and `registration_mode` (the provider row's `link_existing_account` wins), exactly as for an
OpenID Connect credential; `social_login.mode` itself does not need to be enabled. Building a container with a SAML
profile without `onelogin/php-saml` fails with a clear message.

### Services (public API)

- `SamlTestStart::start(Request, string $provider, ?string $loginHint = null): string` — test sign-in URL; throws
  `SocialLoginTestException` (`no_handler`, `saml_disabled`, `unknown_provider`).
- `SamlSpKeyGenerator::generate(string $commonName, int $validDays = 730): SamlSpKeyPair` — RSA 3072 key and
  self-signed certificate (PEM) for a slot.
- `SamlIdpMetadataImporter::import(SamlProvider, string $xml, ?string $entityId = null): void` — entity id, HTTP-Redirect
  SSO URL and up to two signing certificates from IdP metadata XML (the host fetches the document; no URL fetch in
  2.10.0).
- `SamlSpMetadata::build(SamlProvider, Request): string` — the metadata XML.
- `SamlProvider::setIdpCertificates()`, `setSpKeyPair()`, `setNextSpKeyPair()`, `promoteNextSpKeyPair()`.
- `SamlProviderRepository::findOneByProvider()`, `findByScope()`.

### Event

`Nowo\AuthKitBundle\Saml\Event\SamlResponseRejectedEvent` (`profile`, `provider`, `reason`): every refused ACS request
that matched a pending request of this session, and every unsolicited response. Carries no assertion data.

### Reason codes (`SamlFailure`)

`unsolicited`, `invalid_state`, `expired_request`, `replayed`, `malformed`, `status`, `signature`,
`in_response_to`, `destination`, `subject_confirmation`, `audience`, `issuer`, `timing`, `encryption_required`,
`decryption`, `unusable_subject`, `missing_email`, `unavailable`. Users see the social flash messages
(`social.error.invalid_state`, `social.error.failed`); host handlers get `SocialCallbackFailure::Failed` /
`Unavailable`.

### HTTP answers

- start: 302 to the IdP; 302 to the login page when SAML is off, the provider unknown / disabled or the start limit hit.
- ACS: first (cross-site) POST → 200 relay page (no session, `no-store`, `no-referrer`, `noindex`); relayed POST → 302
  to the login success route, or to the login page with a flash; lock conflicts while linking → 409 `CONFLICT_001` +
  `Retry-After` (or `CONFLICT_002` once part was committed) through `StorageFailureSubscriber`; the host EM is never
  cleared, reset or closed.
- metadata: 200 XML for an existing row (enabled or not, so it can be set up before enabling), 404 otherwise.

## Security rules

- **S-01** SP-initiated only: the response must carry `InResponseTo` equal to the request id stored in this session for
  this provider (Response and the bearer `SubjectConfirmationData`); no pending request → `unsolicited`.
- **S-02** Single use: the pending request is removed from the session and claimed once in the attempt limiter's store
  under its lock before the response is parsed (parallel requests of one session cannot both use it). Expired after
  `request_lifetime`. Worker-safe: session and cache pool, never process memory.
- **S-03** RelayState must equal the random value stored with the pending request (constant-time); a mismatch leaves the
  pending request untouched (a crafted POST cannot cancel the real sign-in).
- **S-04** A valid XML signature on the Response **or** the Assertion is always required; unsigned documents are always
  refused. Exactly one assertion (encrypted or not) in the whole document; at most one signature on each of Response and
  Assertion, each referencing its parent's ID; the data read is the signed assertion (anti-wrapping, onelogin strict
  mode + AuthKit's own re-check). SHA-1 signatures and digests are refused.
- **S-05** `Destination` required and equal to the ACS URL; `Recipient` equal to the ACS URL; `Audience` present and
  containing the SP entity id; every `Issuer` equal to `idp_entity_id`; `Conditions/@NotBefore`, `@NotOnOrAfter`,
  `SubjectConfirmationData/@NotOnOrAfter` and `AuthnStatement/@SessionNotOnOrAfter` with `clock_skew`.
- **S-06** Encrypted assertions accepted (current or next SP key), required with `want_encrypted_assertions`. RSA 1.5
  key transport refused.
- **S-07** Subject: the mapped `subject` attribute, else a NameID whose format is not transient; must pass
  `ExactIdentifier` (≤ 255). Issuer stored on the link: a link made under another IdP entity id no longer signs in.
- **S-08** E-mail: linking to an existing account or creating one needs a verified-equivalent e-mail (provider
  `email_verified` true) under `require_verified_email`, as for OpenID Connect.
- **S-09** Never logged at info level or above: keys, certificates' private parts, the response, the assertion,
  NameIDs, attribute values. Warnings carry the provider key and the reason code only.
- **S-10** XML parsed through onelogin's loader (no external entities, no DTD); responses over 512 KiB refused before
  decoding.
- **S-11** No per-request state in services (REQ-RUNTIME-003, FrankenPHP worker mode); onelogin's static URL helpers
  are pinned to the ACS URL for one validation and restored.

## Out of scope (2.10.0)

- IdP-initiated sign-in, Single Logout, artifact binding, IdP metadata fetched by URL, group / role mapping decisions
  (groups are passed to host handlers only), signed metadata.
