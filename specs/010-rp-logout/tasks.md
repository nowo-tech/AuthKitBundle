# Tasks — 010 RP-initiated logout and enterprise secrets fail closed

- [x] T001 `end_session_endpoint` in discovery (safe or dropped), cache key v2, `OidcClient::endSessionEndpoint()`
- [x] T002 Column `rp_initiated_logout` (attribute, XML mapping)
- [x] T003 Sealed session hint (`RpLogoutHint`, `RpLogoutSession`) written after a sign-in by the callback
- [x] T004 `RpInitiatedLogoutSubscriber`, `PostLogoutRedirectUri`, configuration `social_login.rp_logout`
- [x] T005 `EnterpriseSecretEncryptionGuard`, `doctrine.require_enterprise_secret_encryption`
- [x] T006 Tests: unit, SQLite + DoctrineEncrypt listener, kernel (doctrine-encrypt-bundle, fake IdP); 100 % coverage
- [x] T007 Docs: SSO, SECURITY, CONFIGURATION, FRANKENPHP-WORKER-AUDIT, CHANGELOG, UPGRADING (SQL for 4 databases)
