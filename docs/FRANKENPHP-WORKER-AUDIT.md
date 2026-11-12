# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/auth-kit-bundle` (`symfony-bundle`) |
| Audited revision | working tree (post `v1.20.1` + Unreleased FrankenPHP remediations); MFA memo addendum on `v1.45.0` + Unreleased (W-05) |
| Audit date | 2026-09-24 (re-audit + residual hardening); 2026-11-11 (W-05, REQ-RUNTIME-002 sweep); 2026-11-12 (1.46.0: framework-free ports, private entity manager, reset table) |
| Method | Manual review of every file under `src/` (controllers, services, form types, Twig extensions, event subscriber, repositories, DI extension, compiler passes, `Resources/config/services.yaml`) |
| **Verdict** | ✅ **100% compatible for bundle-owned security decisions under scenario B** — shared services keep no per-request state; QR / reset / social credentials / link lookups re-read rows (`HINT_REFRESH` / `refresh()`); closed EntityManagers are recovered after failed flushes; embed ignores tokens outside a security-enabled firewall. The application remains responsible for a global EntityManager `clear()`/`reset` if it needs freshness for *its own* entities outside Auth Kit paths, and for a shared `cache.app` |
| Remediation (2026-09-23) | W-01 … W-04 resolved: `QrLoginChallengeRepository::findFresh()` / `transitionStatus()`, `QrLoginChallengeConflictException`, `Doctrine\EntityManagerRecovery`, refresh in `PasswordResetTokenManager`, firewall guard in `AuthEmbedContextFactory`, OAuth timeouts. Regression tests: `QrLoginChallengeManagerWorkerTest`, `PasswordResetTokenManagerWorkerTest`, `EntityManagerRecoveryTest`, worker cases in `AuthEmbedContextFactoryTest`, `UserRegistrarTest`, `OAuth2ClientTest` and the QR controller tests |
| Remediation (2026-11-11) | W-05 resolved: `MfaPasswordChangeListener` empties its memo at every `onFlush`, at the main `kernel.request`, `kernel.terminate`, `console.terminate` and the Messenger worker events, not only through `kernel.reset`. Tests: `MfaPasswordChangeWorkerKernelTest` (kept-alive kernel, 500 requests, two users), `MfaPasswordChangeListenerTest` (boundaries + `reset()`) |
| Addendum (2026-11-12, 1.46.0) | New state reviewed: `Internal\HiddenValues` (static `WeakMap` keyed by the per-call port VOs: entries die with their VO, nothing to reset), the port gates (`Port\*`, readonly, built per call or once by the container), `Doctrine\AuthKitEntityManager` + `UnitOfWorkBoundaries` (opt-in private manager: dropped at every outermost request / Messenger / console boundary and at `kernel.reset`), reset table services (readonly, DBAL reads per call). New worker tests: `TwoIdentitiesWorkerKernelTest` (500 rounds, two identities, no services resetter), `PrivateEntityManagerWorkerKernelTest` (600 requests, two users), `ResetTableKernelTest::testWorkerModeAlternatingUsersNeverCrossAndMemoryStaysFlat` (600 iterations) |
| Hardening (2026-09-24) | Residual identity-map reads closed: `SocialLoginCredentialRepository` / `SocialLoginAccountRepository` use `HINT_REFRESH`; `SocialAccountLinker`, `MagicLoginUserResolver`, `PasswordResetUserResolver` refresh managed users after lookup |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
  - [W-01 — Stale Doctrine identity map drives QR login and reset-code decisions (Medium)](#w-01--stale-doctrine-identity-map-drives-qr-login-and-reset-code-decisions-medium)
  - [W-02 — EntityManager closed after a failed flush is never reopened (Medium)](#w-02--entitymanager-closed-after-a-failed-flush-is-never-reopened-medium)
  - [W-03 — Security token is read from `security.token_storage`, which only `kernel.reset` clears (Medium)](#w-03--security-token-is-read-from-securitytoken_storage-which-only-kernelreset-clears-medium)
  - [W-04 — OAuth HTTP calls have no bundle-level timeout (Low)](#w-04--oauth-http-calls-have-no-bundle-level-timeout-low)
  - [Info](#info)
  - [W-05 — MFA password-change memo survives a failed flush (Medium, security)](#w-05--mfa-password-change-memo-survives-a-failed-flush-medium-security)
- [Worker tests](#worker-tests)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | All services use `readonly` constructor properties. Only three mutable properties exist: `ProfileRegistry::$resolveCache` (bounded, immutable values), `AuthKitRouteLoader::$loaded` (route loading only) and `MfaPasswordChangeListener::$changed` (users of the flush in progress, emptied at every boundary, W-05) |
| Static properties / `static` locals | ✅ | No static properties. `Config/*`, `AuthKitFormLoginParameters`, `ProfileSettings::fromConfig()` and `AuthEmbedOptions::fromArray()` are pure static helpers |
| `ResetInterface` / `kernel.reset` coverage | ✅ | One reset hook: `MfaPasswordChangeListener` (`kernel.reset`, tested), which is also emptied at the request / console / Messenger boundaries, so correctness never depends on `kernel.reset` (W-05). The bundle no longer depends on the `doctrine` / `security.token_storage` resets for its own decisions (W-01, W-02, W-03 resolved) |
| Request / user / locale captured in services | ✅ | Request, session, token and locale are read on each call (`RequestStack`, `TokenStorageInterface`, `Request` arguments); nothing is stored |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used. `getcwd()` only appears in the CLI command `ConfigureSecurityCommand` |
| Doctrine / EntityManager | ✅ | Fresh reads for QR challenges, social credentials/accounts, reset/magic user lookups; atomic QR status transitions; closed managers reset after failed flushes |
| Output, headers, `exit`, shutdown functions | ✅ | None. Cookies and headers are set through `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | None. HTTP goes through the injected Symfony `HttpClientInterface` |
| Memory growth across requests | ✅ | No accumulating arrays. `ProfileRegistry::$resolveCache` is bounded by the number of user classes |
| Blocking I/O and timeouts | ✅ | OAuth token/userinfo calls use `timeout` 10 s / `max_duration` 20 s (W-04 resolved) |
| Third-party static state | ✅ | `endroid/qr-code` builder and writers are created per call; `nowo-tech/doctrine-encrypt-bundle` is only used through the `#[Encrypted]` attribute on entities (audit it separately) |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

Worker demo: `demo/symfony8/Caddyfile` runs `php_server` with a `worker` block (`file /app/public/index.php`, `watch`).

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Profile\ProfileRegistry` | yes | `$resolveCache` keyed by class name, holds `readonly` `ProfileSettings` | ✅ | ✅ |
| `Profile\RequestProfileResolver` | yes | none (reads `_auth_kit_profile` from the Request argument) | ✅ | ✅ |
| `Security\AuthKitAttemptLimiter` | yes | none (counters live in `cache.app`) | ✅ | ✅ |
| `Security\RegistrationGate`, `Security\UserRegistrar` | yes | none (EntityManager + readonly deps) | ✅ | ✅ |
| `PasswordReset\PasswordResetTokenManager` | yes | none | ✅ | ✅ |
| `PasswordReset\PasswordResetRequestHandler`, `PasswordResetCompleter`, `PasswordResetUserResolver`, `PasswordResetGate` | yes | none | ✅ | ✅ |
| `MagicLogin\MagicLoginRequestHandler`, `MagicLoginUserResolver`, `MagicLoginGate` | yes | none | ✅ | ✅ |
| `QrLogin\QrLoginChallengeManager` | yes | none (`$kernelSecret` is config) | ✅ | ✅ |
| `QrLogin\QrLoginRateLimiter`, `QrLoginGate`, `QrLoginUserResolver`, `NullQrLoginStepUp`, `DeviceIntelligenceQrLoginStepUp` (decorator) | yes | none | ✅ | ✅ |
| `QrLogin\NullQrCodeGenerator`, `QrLogin\EndroidQrCodeGenerator` | yes | none (new `Builder` per call) | ✅ | ✅ |
| `SocialLogin\OAuth2Client`, `ProviderEndpointCatalog`, `OAuthEndpointUrlValidator` | yes | none | ✅ | ✅ |
| `SocialLogin\SocialAccountLinker` | yes | none | ✅ | ✅ |
| `SocialLogin\SocialLoginStateStore` | yes | none (state kept in the session) | ✅ | ✅ |
| `SocialLogin\SocialLoginGate` | yes | none (queries enabled credentials on every call, no cache) | ✅ | ✅ |
| `Repository\QrLoginChallengeRepository`, `SocialLoginAccountRepository`, `SocialLoginCredentialRepository` | yes | none besides the EntityManager reference | ✅ | ✅ |
| `Mfa\EventSubscriber\MfaPasswordChangeListener` (Doctrine `onFlush` / `postFlush`, 1.34) | yes | `$changed`: users whose password changed in the flush in progress, per unit of work (1.45.2). Emptied in `postFlush`, by `onClear`, when its unit of work is gone, at the main `kernel.request`, `kernel.terminate`, `console.terminate`, `WorkerMessageReceived/Handled/FailedEvent`, and by `reset()` | ✅ | ✅ (since 1.45.1, W-05) |
| `Doctrine\EntityManagerRecovery` | yes | none (`ManagerRegistry` reference) | ✅ | ✅ |
| `Routing\AuthKitRouteLoader` (`routing.loader`) | yes | `$loaded` flag, used only when routes are compiled | ✅ | ✅ |
| `Routing\AuthKitRouteLocaleParameters`, `Routing\AuthKitUrlGenerator` | yes | none (locale read from `RequestStack` per call) | ✅ | ✅ |
| `Embed\AuthEmbedContextFactory` | yes | none | ✅ | ✅ |
| `Twig\AuthKitUiExtension` (globals from readonly config), `Twig\AuthEmbedExtension`, `Twig\AuthKitRoutingExtension` | yes | none | ✅ | ✅ |
| `EventSubscriber\NewDeviceLoginSubscriber` (`LoginSuccessEvent`) | yes | none | ✅ | ✅ |
| `DeviceIntelligence\DeviceIntelligenceContext`, `Null*Notifier`, `Logging*Notifier`, `Mailer\AlwaysOutboundMailReadyChecker` | yes | none | ✅ | ✅ |
| 10 form types + 5 form resolvers/builders (`Form/*`) | yes | none (readonly deps and closures) | ✅ | ✅ |
| 17 controllers (`Controller/*`, public) | yes | none (readonly deps) | ✅ | ✅ (token read behind the firewall, see W-03) |
| `Locale\RecipientLocale`, `Locale\PreferredLocaleAwareRecipientLocaleResolver` (1.23) | yes | none; `run()` switches locale through Symfony `LocaleSwitcher::runWithLocale()`, which restores translator / router context / ICU locale in `finally` | ✅ | ✅ |
| `Flash\AuthKitFlashMessenger`, `Twig\AuthKitFlashExtension` (1.23) | yes | none (locale from the `Request` argument; catalogues cached by the translator) | ✅ | ✅ |
| `Admin\AdminUserCreator` (1.23) | yes | none (EntityManager + readonly deps, `EntityManagerRecovery`) | ✅ | ✅ |
| `Command\ConfigureSecurityCommand`, `Command\CreateAdminCommand` | CLI only | none | N/A | N/A |

Entities (`QrLoginChallenge`, `SocialLoginAccount`, `SocialLoginCredential`), enums and value objects (`ProfileSettings`, `AuthEmbedOptions`, `AuthEmbedContext`, `SocialUserProfile`, notification contexts, events) are created per request or loaded by Doctrine; none is stored in a service property.

## Findings

### W-01 — Stale Doctrine identity map drives QR login and reset-code decisions (Medium)

- **Where:**
  - `src/QrLogin/QrLoginChallengeManager.php:81-84` (`find()` → `EntityRepository::find()`, which returns the managed instance from the identity map without querying the database when it is already loaded).
  - Callers that take decisions on the in-memory status: `src/Controller/QrLoginStatusController.php:29-43`, `src/Controller/QrLoginCompleteController.php:42-73`, `src/Controller/QrLoginApproveController.php:56-67`, `src/Controller/QrLoginDenyController.php:38-45`.
  - `src/PasswordReset/PasswordResetTokenManager.php:90-115` reads the stored code hash from the user object returned by `findOneBy()`; `validateExpiry()` (`:169-187`) reads the expiry from the same object. Doctrine does not overwrite fields of an entity that is already managed.
- **Worker impact:** under **A** the `doctrine` registry is reset between requests (DoctrineBundle clears the managers), so every request reads fresh rows. Under **B** the EntityManager and its identity map live for the whole worker. Each worker then keeps its own view of a QR challenge or a user:
  - the desktop poll can keep reporting `pending` after another worker approved it, so the QR login never completes;
  - a worker that still holds a challenge as `approved` can run `consume()` and log in again after another worker already consumed it (the one-time guarantee is lost; the desktop cookie is still required);
  - a worker that loaded the user before a new reset code was issued, or before the code was cleared, compares against the old hash and expiry, so a new code can be rejected or an already used/cleared code can be accepted.
- **Recommendation:** run with `services_resetter` (scenario A). If B must be supported, the bundle should call `EntityManager::refresh()` (or `clear()`) on the challenge/user before security checks, or read status with a query that bypasses the identity map (for example `Query::HINT_REFRESH`).
- **Status:** Resolved — `QrLoginChallengeManager::find()` uses the new `QrLoginChallengeRepository::findFresh()` (`Query::HINT_REFRESH`). `approve()`, `deny()`, `consume()` and the expiry path of `isExpiredOrInvalid()` first run `QrLoginChallengeRepository::transitionStatus()` (`UPDATE … SET status = :to WHERE id = :id AND status = :from`, checked on affected rows) and throw `QrLoginChallengeConflictException` when another request won; the approve/deny controllers answer `409`, the complete controller redirects to login without calling `Security::login()`. If the expiry transition loses, the challenge is re-read so an approval from another worker is not overwritten. `PasswordResetTokenManager` refreshes the user (`EntityManager::refresh()` when managed) before comparing the code hash/expiry and adds `Query::HINT_REFRESH` to the link-token lookup. Two concurrent completions of the same reset code are a classic race (not worker-specific) and remain out of scope. Tests: `QrLoginChallengeManagerWorkerTest`, `PasswordResetTokenManagerWorkerTest`.

### W-02 — EntityManager closed after a failed flush is never reopened (Medium)

- **Where:** `flush()` calls in `src/Security/UserRegistrar.php:75` and `:81`, `src/PasswordReset/PasswordResetTokenManager.php:59` and `:149`, `src/PasswordReset/PasswordResetCompleter.php:37`, `src/SocialLogin/SocialAccountLinker.php:52`, `:96` and `:157`, `src/Repository/QrLoginChallengeRepository.php:24`. `src/Controller/RegisterController.php:100` only catches `RuntimeException`, so a DBAL exception (for example a unique constraint violation on a duplicate identifier) is not handled.
- **Worker impact:** any exception during `flush()` closes the EntityManager. Under **A** the Doctrine resetter replaces the closed manager for the next request. Under **B** every later request of that worker that touches Doctrine fails with "The EntityManager is closed" until the worker restarts. `QrLoginChallengeRepository` keeps the injected `EntityManagerInterface` in its parent property; this is fine under A because DoctrineBundle resets its lazy manager service in place.
- **Recommendation:** keep `services_resetter` enabled. Add a `UniqueEntity` constraint (or equivalent) on the user identifier so duplicates are rejected before `flush()`. Do not inject a manually created EntityManager into these services.
- **Status:** Resolved — new `src/Doctrine/EntityManagerRecovery.php` (autowired with `ManagerRegistry`) wraps every bundle flush (`UserRegistrar`, `PasswordResetTokenManager`, `PasswordResetCompleter`, `SocialAccountLinker`, QR writes in `QrLoginChallengeManager`); on any exception it calls `ManagerRegistry::resetManager()` for closed entity managers and rethrows. DoctrineBundle resets lazy managers in place, so injected references (including the repository's) stay valid. `UserRegistrar` converts `UniqueConstraintViolationException` into a `RuntimeException`, which `RegisterController` already turns into a redirect. The new collaborator is an optional trailing constructor argument (BC). The `UniqueEntity` recommendation still applies to the application's user entity. Tests: `EntityManagerRecoveryTest`, `UserRegistrarTest::testDuplicateUserFlushFailureReopensEntityManagerAndThrowsRuntimeException`, `QrLoginChallengeManagerWorkerTest::testFailedWriteResetsClosedEntityManager`. Since 1.43.0 only the bundle's own managers are reset (the one the failed write went through, the manager of the AuthKit entities, and `doctrine.entity_manager` when set); a closed host manager is never touched. With the option set: `tests/Integration/Worker/DddEntityManagerKernelTest.php`. Kept-alive kernel without `kernel.reset`: `tests/Integration/Worker/WorkerEntityManagerRecoveryKernelTest.php` (one kernel, requests through `http_kernel`, failed write, 20 more requests).

### W-03 — Security token is read from `security.token_storage`, which only `kernel.reset` clears (Medium)

- **Where:** `TokenStorageInterface::getToken()` in `src/Embed/AuthEmbedContextFactory.php:56` (the identifier is rendered at `:97`), `src/Controller/LoginController.php:49`, `src/Controller/RegisterController.php:52`, `src/Controller/MagicLoginRequestController.php:40`, `src/Controller/ResetPasswordRequestController.php:40`, `src/Controller/ResetPasswordController.php:43`, `src/Controller/ResetPasswordCodeController.php:44`, `src/Controller/QrLoginApproveController.php:79`, `src/Controller/QrLoginDenyController.php:52`. `src/Controller/RegisterController.php:110` writes a token with `setToken()`.
- **Worker impact:** the bundle does not store the user itself. Symfony's token storage is tagged `kernel.reset` (method `setToken`), so under **A** it is cleared on every request. Under **B** it is not. On routes behind a stateful firewall the `ContextListener` sets the token again (or `null` when there is no session), so the bundle reads the right user. On pages **outside** any firewall (or with `security: false`), the `auth_kit_dropdown` Twig function can still see the previous request's token and render another user's identifier. This is a cross-user leak, but only under B.
- **Recommendation:** do not run scenario B. Render `auth_kit_dropdown` only on routes covered by a stateful firewall. QR approve/deny routes must stay behind the firewall (they already require an authenticated user).
- **Status:** Resolved for the dropdown — `AuthEmbedContextFactory` (optional `RequestStack` + `Security`, autowired) only reads the token when the main request carries `_firewall_context` and that firewall has security enabled; otherwise it renders the guest panel. Accepted for the controllers: Auth Kit routes are registered behind the configured firewall, whose `ContextListener` sets (or clears) the token on every request; QR approve/deny additionally require an authenticated user. Residual framework responsibility: on a **stateless** firewall that does not authenticate the current request, Symfony itself would keep the previous token under scenario B; this is outside the bundle's control. Tests: `AuthEmbedContextFactoryTest::testStaleTokenIsIgnoredOnNextRequestOutsideFirewallWithoutReset` and related cases.

### W-04 — OAuth HTTP calls have no bundle-level timeout (Low)

- **Where:** `src/SocialLogin/OAuth2Client.php:49` (token endpoint) and `:83` (userinfo endpoint) call `HttpClientInterface::request()` without `timeout` or `max_duration`.
- **Worker impact:** Symfony HttpClient then falls back to `default_socket_timeout` (60 s by default) for idle time and has no total limit. A slow identity provider pins one of the limited worker threads during the social login callback. No state leaks.
- **Recommendation:** set `framework.http_client.default_options.timeout` / `max_duration`, or use a scoped client for OAuth, and cap queueing with FrankenPHP `max_wait_time`.
- **Status:** Resolved — `OAuth2Client` passes `timeout` (default 10 s) and `max_duration` (default 20 s) on both calls; both come from `nowo_auth_kit.http_client.timeout` / `max_duration` (since 1.28; previously only overridable as the `$timeout` / `$maxDuration` service arguments). Test: `OAuth2ClientTest::testTokenAndUserinfoCallsUseExplicitTimeouts`.

### W-05 — MFA password-change memo survives a failed flush (Medium, security)

- **Where:** `src/Mfa/EventSubscriber/MfaPasswordChangeListener.php` (1.34.0 – 1.45.0). `onFlush` stored every MFA user
  whose password field changed in `$changed`; only `postFlush` and `reset()` (`kernel.reset`) emptied it.
- **Worker impact:** a flush that throws after `onFlush` (constraint violation, lost connection, a host listener) never
  reaches `postFlush`. Under **A** the services resetter emptied the memo; under **B** (kernel reset false) the user
  stayed in it, and the next `postFlush` in that worker, from any request and any user, called
  `MfaManager::forgetDevices()` on the stale user: the **wrong user's** trusted devices were forgotten (they had to
  pass MFA again) and the stale entity was retained.
- **Status:** Resolved in 1.45.1 — the memo is keyed by entity manager, emptied for that manager at the start of every
  `onFlush`, and fully emptied at the main `kernel.request`, `kernel.terminate`, `console.terminate` and the Messenger
  `WorkerMessageReceivedEvent` / `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (the listener is also a
  `kernel.event_subscriber`). `kernel.reset` still calls `reset()`. Tests: `tests/Integration/Mfa/MfaPasswordChangeWorkerKernelTest.php`,
  `tests/Unit/Mfa/MfaPasswordChangeListenerTest.php`.
- **Update 1.45.2 (audit MD-A-L1):** the memo is keyed by unit of work instead of entity manager, so a nested flush
  of the same unit of work (from a lifecycle callback) keeps the outer flush's users; an entry whose unit of work is
  gone (`WeakReference` check against a reused object id) is dropped, and `onClear` (also run when a failed commit
  closes the manager) drops that unit of work's users.

### Info

- `src/Profile/ProfileRegistry.php:18` and `:64-78`: `resolveForObject()` memoizes the profile per user class (including Doctrine proxy classes). The map is bounded and only holds immutable `ProfileSettings`, so it is safe across requests.
- `src/Routing/AuthKitRouteLoader.php:43` and `:66-70`: the `$loaded` flag throws if the same loader instance is asked to load twice. Route loading happens during router cache warm-up, not per request. It could only trigger if the router rebuilds its collection inside a long-lived container (debug mode); the demo's `watch` option restarts workers on file changes.
- `src/Security/AuthKitAttemptLimiter.php`: rate limits and OTP lockout counters live in `cache.app`. With the default filesystem pool (or Redis) all worker threads share them. If `cache.app` is an in-memory `ArrayAdapter`, each worker would keep its own counters.
- `src/Security/LoginTarget/*` and `src/Security/SessionExpired/SessionExpiredResponder.php` (1.44): readonly, stateless listeners; the target saved before a background call lives in a request attribute, and `LoginTargetPolicy` matches routes with a GET copy of the router context (the shared one is never changed). Kept-alive kernel test: `SessionExpiredKernelTest::testKeptAliveKernelKeepsNoStateBetweenSignIns`; 3,000-call memory loops in `LoginTargetGuardTest` and `SessionExpiredResponderTest`.
- `src/Twig/AuthKitUiExtension.php:38-54`: Twig globals are built from readonly config and `class_exists()` checks; nothing is added per request.

## 1.46.0 stateful services

| Service | Shared | Mutable state | Cleared at | Test |
|---|---|---|---|---|
| `Doctrine\AuthKitEntityManager` (opt-in) | yes | the private manager, rollback bookkeeping (transaction levels), stale flag, repository cache (≤ 8 entries) | dropped at every outermost boundary by `PrivateEntityManagerSubscriber` (main `kernel.request` 4096 / `kernel.terminate` -1024, Messenger received 4096 / handled / failed -1024, outermost `console.command` / `console.terminate`) and `kernel.reset`; recreated when closed / stale / other connection | `PrivateEntityManagerWorkerKernelTest` (600 requests, two users, 4 MiB), `PrivateEntityManagerPartsTest`, `PrivateEntityManagerSubscriberTest` |
| `Doctrine\UnitOfWorkBoundaries` (opt-in) | yes | stack of running console commands, main-request count, message-in-flight flag, per-event verdicts (`WeakMap`) | process-lifetime by design (describes the running units); self-heals failed frames at the next unit | `UnitOfWorkBoundariesTest` |
| `Doctrine\AuthKitManagerRegistry` (opt-in) | yes | none (readonly) | — | `PrivateEntityManagerPartsTest` |
| `Internal\HiddenValues` | static | `WeakMap` VO => hidden user / request readers | collected with each per-call VO (no request data outlives it) | `AuthUserRefTest`, `AuthRequestContextTest` |
| `Port\*Gate`, `Port\Ports`, `TablePasswordResetTokenManager`, `PasswordResetRequestRepository`, `ProfilePasswordResetTokenManager`, `PasswordResetTableInvalidationListener` | yes / static helpers | none (readonly) | — | unit tests |

## Worker tests

Kept-alive kernel, services resetter never called (requests go through the `http_kernel` service, so `kernel.reset`
does not run between them):

| Test | What it serves | N | Users / tenants | Memory |
|------|----------------|---|-----------------|--------|
| `tests/Integration/Mfa/MfaPasswordChangeWorkerKernelTest.php` (debug off) | `GET /login` + an MFA user's password flush per iteration; every 10th iteration first a failed flush of the other user | 500 (each iteration is an HTTP request plus DB writes) | alice (`member@example.com`) and bob (`support@example.com`) alternating, both with MFA and trusted devices | < 4 MB growth between iteration 50 and the end |
| `tests/Integration/Worker/TwoIdentitiesWorkerKernelTest.php` (debug off, 1.46.0) | per round: `/whoami` of the signed-in user, `GET` + `POST /reset-password` from a signed-out browser; every 50 rounds `GET /login` + form login from a new browser | 500 rounds (about 1,600 requests) | alice and bob alternating, each with its own session | < 4 MB growth between round 50 and the end |
| `tests/Integration/PrivateEntityManager/PrivateEntityManagerWorkerKernelTest.php` (1.46.0, `private_entity_manager: true`) | AuthKit writes through the private manager | 600 | two users alternating | < 4 MiB |
| `tests/Integration/PasswordReset/ResetTableKernelTest.php::testWorkerModeAlternatingUsersNeverCrossAndMemoryStaysFlat` (1.46.0, `storage: table`) | issue / resolve by link and code | 600 | two users | < 2 MiB |
| `tests/Integration/Worker/WorkerEntityManagerRecoveryKernelTest.php` | `GET /login` + QR challenge writes after a failed write | 20 | one flow | — |
| `tests/Integration/LoginTarget/SessionExpiredKernelTest.php::testKeptAliveKernelKeepsNoStateBetweenSignIns` | sign-ins alternating a page target and a machine target | 120 | two sign-in targets | asserted in the unit loops below |
| `tests/Unit/Security/LoginTarget/LoginTargetGuardTest.php`, `tests/Unit/Security/SessionExpired/SessionExpiredResponderTest.php` | service calls | 3,000 | alternating users | flat |

Static checks: `composer igor` and the PHPStan FrankenPHP worker ruleset (`phpstan.neon.dist`) pass in CI.

## Usage recommendations in worker mode

- Keeping `services_resetter` enabled (default in the Symfony FrankenPHP runtime) is still recommended, but the bundle no longer depends on it: its services are stateless and its security decisions re-read the database.
- Under scenario B, clearing the application's EntityManager identity map between requests remains useful for *application* entities Auth Kit does not touch; Auth Kit security paths re-read their own rows.- Keep all Auth Kit routes behind a stateful firewall. `auth_kit_dropdown` is safe on public pages (it shows the guest panel there).
- Use a shared `cache.app` backend (filesystem on a single host, Redis/Memcached across hosts) so rate limits apply to all workers.
- Adjust `nowo_auth_kit.http_client.timeout` / `max_duration` if your identity provider is slow, keep PHP `max_execution_time` and the Caddy write timeout above them, and set FrankenPHP `max_wait_time` (see [CONFIGURATION.md](CONFIGURATION.md#http-timeouts-social-login)).
- Add a `UniqueEntity` constraint on the user identifier field to avoid EntityManager-closing flush errors.
- Custom `PasswordResetNotifierInterface`, `MagicLoginNotifierInterface`, `NewDeviceLoginNotifierInterface`, `QrLoginStepUpInterface` and `OutboundMailReadyCheckerInterface` implementations must stay stateless or implement `ResetInterface`. Listeners on `QrLogin*Event` and `PasswordResetRequestedEvent` receive entities and must not keep them in properties (1.46: prefer `challengeRef()` / `userRef()`, and never store the VOs either).
- Hosts that bypass the services resetter clear their own entity managers at every boundary (REQ-RUNTIME-002 rule 7). With `doctrine.private_entity_manager: true` AuthKit drops its own manager at those boundaries itself.

## Re-audit triggers

Re-run this audit when a change adds: a mutable property to any service, a cache of users/challenges/credentials (for example caching `SocialLoginGate` or `findEnabledOrdered()`), a new Doctrine listener, a `ResetInterface` implementation, direct use of `$_SERVER` / `$_ENV` / `$_SESSION`, or a new outbound HTTP call.
