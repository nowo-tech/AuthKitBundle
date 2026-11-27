# Plan — 010 RP-initiated logout and enterprise secrets fail closed

1. Discovery: `OidcProviderMetadata::$endSessionEndpoint`, `OidcDiscovery::optionalEndpoint()` (drop unsafe values),
   cache key `v2`; `OidcClient::endSessionEndpoint()` (never throws).
2. Entity `SocialLoginCredential::$rpInitiatedLogout` (attribute and XML mapping).
3. `RpLogoutHint` (value object) and `RpLogoutSession` (sealed session storage, bounds).
4. `SocialLoginCheckController`: after a callback that changed the token, replace or drop the hint.
5. `RpInitiatedLogoutSubscriber` on `LogoutEvent` (priority 16, after the multi-account sign-out) and `PostLogoutRedirectUri` (allow-list, build-time
   validation of `social_login.rp_logout.post_logout_redirect_uris`).
6. `EnterpriseSecretEncryptionGuard` (`onFlush`) and `EnterpriseSecretNotEncryptedException`;
   `doctrine.require_enterprise_secret_encryption`.
7. Tests (unit, SQLite with a real DoctrineEncrypt listener, kernel with doctrine-encrypt-bundle and a fake IdP), 100 %
   coverage, PHPStan, CS, Igor; docs.
