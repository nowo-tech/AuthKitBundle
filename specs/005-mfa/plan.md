# Plan — 005 MFA

1. Configuration node `mfa` (MfaConfiguration), profile settings, extension (`mfa.yaml` loaded only when enabled,
   per-firewall subscribers, policy locator, compile checks), CSRF pass, route loader, register_mapping.
2. Core: `Totp`, `Base32`, `RecoveryCodes`, entities + `MfaStore` (ORM for the encrypted secret, DBAL check-and-set),
   `MfaOwnerResolver`, `MfaManager`, events.
3. Two-step token: `MfaPendingToken` (not an AbstractToken), `MfaVoter`, `MfaAuthenticationSubscriber`,
   `MfaContainmentSubscriber`, `MfaLoginCompleter`; AuthKit login controllers name their first factor.
4. Trusted devices (`MfaTrustedDevices`), UserKit subscriber.
5. Controllers (challenge / setup, account, admin), form type, templates, Twig extension, translations.
6. Tests (unit + kernels: default, UserKit sessions, no CSRF), QA, docs (MFA, CONFIGURATION, SECURITY, TEMPLATES,
   README, CHANGELOG, UPGRADING, ROADMAP).
7. Demo + Playwright / axe (M4, with multi-account).
