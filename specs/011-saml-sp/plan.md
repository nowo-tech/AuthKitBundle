# Plan — 011 SAML 2.0 Service Provider sign-in

1. Contract (this spec) committed before code.
2. Entity `SamlProvider` (attributes + XML mapping, parity test), `SamlProviderRepository`, `SamlCertificate` (PEM,
   SHA-256, validity), SQL scripts for four databases, encryption guard extended to the SP private keys.
3. `SamlConfiguration` (`profiles.<name>.saml`), routes in `AuthKitRouteLoader`, `saml.yaml` loaded only with an
   enabled profile; build-time check for `onelogin/php-saml`.
4. Protocol: `SamlSettingsFactory` (onelogin settings, strict, signed requests, two IdP certificates), `SamlServerContext`
   (onelogin's static URL helpers pinned to the ACS URL), `SamlRequestStore` (session + attempt-limiter claim),
   `SamlResponseValidator` (onelogin strict + AuthKit re-checks, decryption with the next key), `SamlAssertion`.
5. Mapping: `SamlProfileMapper` (subject, e-mail, names, groups → `SocialUserProfile` with `samlAssertion`),
   `SamlProviderPolicy` (link policy, MFA rule from `AuthnContextClassRef`); `SocialAccountLinker` reads the SAML row's
   link policy for `saml:` providers.
6. Controllers: start, ACS (relay, checks, test handler / host steps / linker, rollback guard, conflicts), metadata;
   `SamlTestStart`, `SamlSpKeyGenerator`, `SamlIdpMetadataImporter`, `SamlSpMetadata`, `SamlResponseRejectedEvent`.
7. Tests with throwaway keys and fixtures generated at test time (signed response, signed assertion, unsigned,
   wrapped, expired, replayed, wrong audience / destination / recipient / issuer, encrypted, rotation), kernel tests of
   the routes, database tests of the table and the concurrent JIT path; 100 % coverage, PHPStan, CS, Igor.
8. Docs: SAML.md, SSO.md, CONFIGURATION, USAGE, SECURITY, CONCURRENCY, FRANKENPHP-WORKER-AUDIT, CHANGELOG, UPGRADING.
