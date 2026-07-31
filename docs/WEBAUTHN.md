# WebAuthn / passkeys

Passwordless authentication with platform or roaming authenticators (WebAuthn Level 2 / passkeys).

**Status (v1.12.0):** Design only — **not implemented**. This document locks the intended AuthKit surface so host apps (for example Symfony Beacon) do not invent parallel controllers. Runtime work is planned for a later minor.

## Goals

- Register and assert credentials bound to the local user (`user_identifier_field`).
- Prefer discoverable credentials (passkeys) with conditional UI where browsers support it.
- Keep secrets and attestation handling inside AuthKit; hosts only enable the feature and migrate schema.

## Proposed configuration

```yaml
nowo_auth_kit:
    profiles:
        default:
            webauthn:
                mode: disabled          # disabled | enabled
                rp_id: null             # defaults to request host
                rp_name: 'Auth Kit'
                user_verification: preferred
                timeout_ms: 60000
```

## Proposed routes

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/login/webauthn/options` | PublicKeyCredentialRequestOptions (JSON) |
| `POST` | `/login/webauthn/assert` | Verify assertion → `Security::login` |
| `GET` | `/account/webauthn/register/options` | Creation options (authenticated) |
| `POST` | `/account/webauthn/register` | Persist credential |
| `POST` | `/account/webauthn/{id}/delete` | Revoke credential |

## Proposed storage

Table `auth_kit_webauthn_credential` (bundle-owned):

| Column | Notes |
|--------|-------|
| `id` | UUID |
| `user_class` / `user_id` | Local user reference |
| `credential_id` | Binary / base64url unique |
| `public_key` | COSE key |
| `sign_count` | Clone detection |
| `transports` | JSON |
| `aaguid` | Optional |
| `label` | User-facing name |
| `created_at` / `last_used_at` | Audit |

## Security checklist (when implementing)

- Origin / RP ID binding; reject cross-origin assertions.
- Challenge stored server-side with short TTL; single use.
- Rate-limit options + assert endpoints.
- Optional attestation policy (none / direct) configurable per profile.
- Prefer `web-authn/webauthn-lib` (or Symfony Webauthn bundle) instead of a hand-rolled CBOR stack.

## Host integration (Beacon and others)

Do **not** add app-level WebAuthn controllers while this feature is pending in AuthKit. Track progress against this document and enable `webauthn.mode` once the runtime ships.
