# Plan — 009 Enterprise OpenID Connect

1. Entity `SocialLoginCredential`: `listed`, `link_existing_account`, `mfa_first_factor`, `mfa_accepted_amr` (attributes
   and XML mapping, validated setters, fail-closed getters).
2. `SocialCredentialPolicy` (static): effective link policy, MFA rule from the validated ID token `amr`.
3. `LoginHint` (static): validation and the `login_hint` parameter; start route, `OAuth2Client::buildAuthorizeUrl()`,
   `OidcClient::begin()` (trailing optional arguments).
4. `SocialUserProfile::$idTokenClaims` filled by `OidcClient` with the validated claims only.
5. `SocialAccountLinker` reads the credential policy (optional repository argument); `InvitationAcceptController`
   skips unlisted and `deny` credentials; `LoginController` skips unlisted ones.
6. MFA: `MfaFirstFactor::markRule()` / `ruleOf()`, marked by `SocialLoginCheckController`, read by
   `MfaAuthenticationSubscriber` for the `social` factor.
7. Test sign-in: state store marker (`issue(..., $test)`, `isTestPending()`, `consumePending()['test']`),
   `SocialLoginTestStart`, port `SocialLoginTestHandlerInterface` (tag, autoconfiguration), internal
   `SocialLoginTestGate`, callback routing in `SocialLoginCheckController`.
8. Tests (unit, kernel with a fake IdP and a host test handler, MFA kernel), 100 % coverage, PHPStan, CS; docs.
