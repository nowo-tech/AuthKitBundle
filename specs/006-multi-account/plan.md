# Plan — 006 multi-account

1. S0 spike (session hop on native / PDO / mock storages, FrankenPHP worker): GO.
2. UserKit 1.11 (PR #31): `sessionState(s)`, `endSession`, `rekey`, pending sign-ins.
3. Configuration, extension (`multi_account.yaml`, per-firewall listeners), `MultiAccountPass` (UserKit, stateful
   firewall, user checkers, contexts, remember-me cookies), CSRF pass, routes, mapping.
4. Vault: entity, store, crypto, cookie, session hopper, `AccountVault`, events, gate, presenter, subscriber.
5. Controller, Twig view and extension, templates (switcher, chooser, sign-in list), translations, purge command.
6. Tests (kernels: default, host choices; unit), docs, demo e2e.
