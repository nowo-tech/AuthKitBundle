# Plan — 008 Passkeys / WebAuthn

1. Configuration `webauthn` (`WebauthnConfiguration`), profile settings, extension (`webauthn.yaml` only when enabled,
   second-step controller with MFA, mapping, route names, lock requirement), route loader, containment routes,
   `mfa.first_factors.webauthn: exempt`.
2. Storage: entity + XML mapping, exact-key collation listener and declaration, `WebauthnStore` (DBAL, detached
   records, conditional counter), SQL scripts generated from the mapping.
3. Ceremonies on web-auth/webauthn-lib 5.3 (`WebauthnCeremonies`): options JSON, session challenges with the limiter
   claim, creation and assertion validators with exact origins and required user verification.
4. `WebauthnManager` (owner key, HMAC user handle, limits, per-owner lock, events), `MfaManager::hasSecondFactor()`.
5. Controllers, forms (hidden credential in a FormKit form), templates, script, translations (7 locales).
6. Personal data hooks; tests (unit, kernel, worker, real databases); docs; demo.
