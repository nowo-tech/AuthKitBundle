# Tasks — 008 Passkeys / WebAuthn

- [x] T001 Configuration, profile settings, extension, routes, lock pass, mapping
- [x] T002 Entity, XML mapping, collation, exact-key declaration, store, SQL scripts
- [x] T003 Ceremonies (webauthn-lib), challenges, counter, attestation policy
- [x] T004 Manager, events, MFA integration (second factor, containment, challenge page, last-factor guard)
- [x] T005 Controllers, forms, templates, script, translations
- [x] T006 Personal data eraser and export section
- [x] T007 Tests: unit, kernel, strict limits, worker, real databases; 100 % coverage; PHPStan / CS / Rector / Igor / Twig
- [x] T008 Docs (WEBAUTHN, CONFIGURATION, MFA, TEMPLATES, CONCURRENCY, FRANKENPHP-WORKER-AUDIT, README, CHANGELOG, UPGRADING, ROADMAP)
- [x] T009 Demo: configuration, menu entry, migration
- [x] T010 2.4.1: `WebauthnErasure` (erasure whatever the mode, ZBX-v-I2), runtime lower-casing of `allowed_origins` (ZBX-v-I3, ZCC-L1), WEBAUTHN.md origin wording (ZBX-v-I1)
- [x] T011 2.5.0: `WebauthnCredentialRenamedEvent` (Beacon audit log), rename under the per-owner lock + label compare-and-set (REQ-RUNTIME-003), kernel race tests + real-database two-connection test, docs
- [x] T012 2.5.0 audit ZCH: bounded rename retry + locking re-read (M1), label cleaning of separators / private-use / unassigned / fillers (L1), actor wording (I2), `WebauthnStore::rename()` `@internal` (I3)
- [x] T013 2.6.0: `WebAuthnRelyingPartyProviderInterface` (default = configuration), per-ceremony resolution and validation (fail closed: 503 `unavailable`, `relying_party`, error log, script state `unavailable`), `allow_http_localhost`
- [x] T014 2.6.0: `rp_id` column (entity, XML mapping, SQL scripts, demo migration, UPGRADING backfill DDL), `rp_id_mismatch` reason, `WebauthnManager::countCredentialsForRpId()` / `countCredentialsOutsideRpId()`
- [x] T015 2.6.0: tests (provider override, fail-closed paths, RP id / origin mismatch, credential and challenge RP id mismatch, legacy null `rp_id`, worker), docs (CHANGELOG, UPGRADING, CONFIGURATION, WEBAUTHN, SECURITY), code inventory
- [x] T016 2.6.0 audit ZCQ: origins follow the resolved RP id (`WebAuthnRelyingPartyContext::withRpId()`, forged-Host test, M1), foreign / malformed origins dropped with a warning (M2), lost-savepoint pattern in `AuthKitSavepoint` + real-database deadlock test (M3), lower-cased stored `rp_id` and `LOWER()` backfill (L1), provider exception logged as class / code / digest (L2), early relying-party check (I1), JSON-encoded messages (I3), explicit default ports refused (I4), docs (I6)
- [x] T017 2.7.1 FR-WA-12: remember-me re-authentication (`Reauthentication`, login / magic-link / passkey sign-in bounce, account page "sign in again" state, `RememberedIdentitySwitchSubscriber`), kernel + unit tests, docs
