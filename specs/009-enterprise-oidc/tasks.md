# Tasks — 009 Enterprise OpenID Connect

- [x] T001 Entity columns, XML mapping, validated setters, fail-closed getters
- [x] T002 `SocialCredentialPolicy`, `LoginHint`, `SocialUserProfile::$idTokenClaims`
- [x] T003 Unlisted credentials (login page, invitation accept page) and `login_hint` on the start route / clients
- [x] T004 Per-credential `link_existing_account` in the linker; run-time accept-page check (KL-L3 counterpart)
- [x] T005 Per-credential MFA first-factor policy (`markRule()` / `ruleOf()`, subscriber), ID-token-only `amr`
- [x] T006 Test sign-in: state marker, `SocialLoginTestStart`, `SocialLoginTestHandlerInterface`, gate, callback routing
- [x] T007 Tests: unit, kernel (fake IdP, host test handler), MFA kernel; 100 % coverage; PHPStan / CS
- [x] T008 Docs: SSO, SECURITY, MFA, SOCIAL-LOGIN, CONFIGURATION, USAGE, CHANGELOG, UPGRADING (SQL for 4 databases)
