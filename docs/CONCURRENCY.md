# Concurrency

How AuthKit stays correct when the same data is written at the same time (nowo-tech REQ-RUNTIME-003): two users, two
workers, or a request sent twice. It covers FrankenPHP worker mode with the kernel reset off, standard Symfony hosts
and DDD hosts. Since 2.0 AuthKit's rows always go through its private entity manager.

The [High load](USAGE.md#high-load) section of USAGE has the operational side: topology, PgBouncer, Galera and DBAL 3.

## Table of contents

- [Write paths](#write-paths)
- [Conflict answer and codes](#conflict-answer-and-codes)
- [Unique keys and other database errors](#unique-keys-and-other-database-errors)
- [Host options](#host-options)
- [Lock order](#lock-order)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Write paths

Every write runs on AuthKit's own runner (`Transaction\AuthKitTransactionRunner`, service
`nowo_auth_kit.transaction_runner`, built on `nowo-tech/transaction-doctrine`). It makes one attempt in one transaction
(no retry inside AuthKit). Events with side effects (mail, `*AcceptedEvent`, notifications) are dispatched after the
commit. After a failure no transaction level stays open. The host's entity manager is never cleared or reset: only
the rows the failed unit touched are detached.

**Account writes and the application's entity manager.** AuthKit's own rows (invitations, QR challenges, MFA, reset
table, social links, vault) go through its private entity manager. The accounts are the application's user entities,
so the units that create or change an account (`runWithAccounts`: registration, invitation acceptance, social
sign-up and linking, administrator creation, password reset) flush the application's manager **inside AuthKit's
transaction**, through the Doctrine user writer (`User\Doctrine\DoctrineUserWriter`), the invitation acceptor and the
user-entity reset store. That flush is what the host asked for when it lets AuthKit store its users through Doctrine;
it commits or rolls back with AuthKit's unit, and after a failure the runner detaches only the rows the unit wrote.
A host that binds its own user port (`UserWriterInterface`) keeps its manager out of registration, administrator
creation, social sign-up and password reset: those units then run on the connection only (`runOnConnection`, no
manager flushed). Invitation acceptance still flushes the application's manager inside its unit, because the
invitation grants (`InvitationGrantHandlerInterface`) write the host's own rows there. Every other `flush()` in `src/` is the
runner's own, inside a unit.

| Path | Transaction | Concurrency control | Conflict answer |
|------|-------------|---------------------|-----------------|
| Registration (`UserRegistrar::register`) | One unit (`runWithAccounts`) | Named lock of the identifier (`AccountLock::forIdentifier`, folded identifier), an "already taken" check under it, the host's unique key plus catch (`DuplicateUserException` or `UniqueConstraintViolationException`: refused as a duplicate). With `first_user_only`, the lock of the user class | 409 `CONFLICT_001` on a lock wait or a deadlock; a duplicate is a refusal (redirect), never 409 |
| Invitation acceptance (`InvitationAcceptor`) | One unit (`runWithAccounts`), under the account lock when it creates an account | Conditional `UPDATE … WHERE accepted_at IS NULL` claim (`InvitationRepository::claim`), so an invitation is accepted once | 409 `CONFLICT_001`; a lost claim is the "invitation used" page |
| Social sign-in, sign-up and linking (`SocialAccountLinker`) | One unit (`runWithAccounts`) | Named lock of the social identity, then of the user class (`first_user_only`), then of the account | 409 `CONFLICT_001`, or `CONFLICT_002` when the unit committed and the sign-in then met the conflict |
| OpenID Connect sign-in (2.3.0: generic, GitLab, Apple, LinkedIn, Slack) | No write of its own: the discovery, JWKS, token and userinfo requests run **before** the unit, outside any lock (rule 9); the sign-in then goes through the social linker unit above | Discovery documents and key sets are cache-pool entries (`oidc.cache_pool`): two workers refreshing the same entry write the same checked document, last write wins; the nonce and PKCE verifier are burnt with the state (single use) | Same as social sign-in |
| Administrator creation (`AdminUserCreator`, `nowo:auth-kit:create-admin`) | One unit (`runWithAccounts`) | Account lock, unique key plus catch (a duplicate result) | Console: the command fails with the transient failure; nothing is written |
| Password reset (request, token, completion) | One unit each (`runWithAccounts`, `runOnConnection`, `forConnection` for the reset table) | The new password and the cleared reset credential are one unit (a failure keeps neither, the link stays usable); the credential columns are re-read from the database before a check. 2.2: the completion claims the credential first (`PasswordResetCredentialClaimInterface`: a conditional `UPDATE` on the stored value this request read, or the `DELETE` of the table rows), so one link stores one password | 409 `CONFLICT_001`; a lost claim is the "invalid link" answer (`PasswordResetLinkUsedException`), nothing written |
| QR login (create, approve, deny, consume, expire) | One unit each (`QrLoginChallengeRepository::transactional`) | Challenge id inserted through `AuthKitRows::insert` (unique key plus catch: a new id is drawn); every state change is a conditional `UPDATE … WHERE status = :from` (`transitionStatus()`), so a challenge is approved, denied or consumed once (`QrLoginChallengeConflictException` for the loser); state is re-read (`findFresh`) in the unit | 409 `CONFLICT_001`; `CONFLICT_002` when the challenge was consumed and the sign-in then met the conflict |
| MFA (enrolment, verification, recovery codes, trusted devices, disabling) | One unit each (`MfaStore`) | `FOR UPDATE` on the credential row first for every change of the factor; first enrolment through `AuthKitRows::insert` (the row another request wrote is read with a lock); single-use secrets by conditional `UPDATE`: a TOTP step (`claimStep`, `last_used_step < :step`), a recovery code (`useRecoveryCode`, `used_at IS NULL`), a trusted-device token rotation (`rotateDevice`, `token_hash = :seen`); attempt counters under `symfony/lock` | 409 `CONFLICT_001` |
| Invitations (create, resend, revoke) | One unit each (`InvitationRepository::save`; 2.0: resend, re-invite and revoke through `saveWhileOpen()`, a conditional UPDATE guarded on the row still being open) | Rate limiter; random public id and token (unique keys); a racing acceptance wins (`NOT_OPEN`, nothing written) | 409 `CONFLICT_001` |
| Several accounts (vault entries) | Single DBAL statements (`AccountVaultStore`; 2.2.1: the id from a sequence, PostgreSQL with DBAL 3, is written explicitly) | Unique token hash; deletes by id | 409 `CONFLICT_001` |
| Personal data erasure (`DoctrineInvitationPersonalData`) | One unit (`forConnection`) | Paged by id | Host's port caller |
| Purge commands (invitations, account vault) | Paged statements (500 rows per statement) | Deletes by id, capped batches | Console: the command fails; the next run continues |

## Conflict answer and codes

`EventSubscriber\StorageFailureSubscriber` answers lock conflicts on AuthKit's own routes (`nowo_auth_kit_*` and any
route served by an AuthKit controller). A lock conflict is any of these, from AuthKit's runner or not:

- a deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01);
- a lock wait timeout (1205, 55P03);
- a serialization failure (MariaDB 1020, PostgreSQL 40001);
- an AuthKit named lock not obtained within `AuthKitNamedLock::TIMEOUT_SECONDS`.

| Code | When | Status | `Retry-After` | Client should |
|------|------|--------|---------------|---------------|
| `CONFLICT_001` | Nothing of the request was committed | 409 (or 503, see below) | Yes (the failure's hint, else `retry_after`) | Send the request again |
| `CONFLICT_002` | Part of the request was already committed: a commit was counted since the request started, or a host write unit gave up after a commit (`ConcurrencyConflict::$committed`) | 409 (or 503) | No | Reload, do not repeat |

- **Every answer** carries the header `X-Conflict-Code: CONFLICT_001` or `CONFLICT_002` (2.0, platform convention),
  so a page rendered by the application's error page still tells the client the code. `Retry-After` comes only with
  `CONFLICT_001`.
- **Pages** get an `HttpException` with the status, the headers and a client-safe message (no SQL). The application's
  error page renders it.
- **JSON clients** (`Accept: application/json`) get `{"ok": false, "error": "unavailable", "code": "CONFLICT_001"}`
  and the same headers. The `ok` and `error` keys are the 1.50 ones; `code` was added in 1.53.0. Clients key on `code`
  or the header, never on `error`.
- **Cross-origin clients.** AuthKit's routes are same-origin pages and their own JSON polls, so AuthKit sets no CORS
  headers. A host that serves them cross-origin lists `X-Conflict-Code` and `Retry-After` in
  `Access-Control-Expose-Headers` (for example `nelmio_cors` `expose_headers`).
- **Host social callback handlers** (`SocialCallbackStepInterface`): since 2.2.1 a lock conflict thrown by `handle()`
  gets the same answer, never the `Failed` refusal ([SOCIAL-LOGIN.md](SOCIAL-LOGIN.md)).
- The codes are transaction-doctrine's `ConflictCode` (`CONFLICT_001` / `CONFLICT_002`), shared by every nowo-tech
  bundle.

**How CONFLICT_002 is detected.** transaction-doctrine's commit tracking DBAL middleware counts every successful
`COMMIT` and every autocommit write. The subscriber reads that count at `kernel.request` of the main request (priority
2048, before the firewall) and again when the conflict reaches it. An AuthKit controller rendered as a sub-request (a
fragment of a page) uses the main request's count (2.2.1), so a commit made earlier in the page counts too. If the count moved, something of this request is already durable.
Two examples: a QR challenge consumed before the sign-in failed, or a registration committed before a listener of the
sign-in deadlocked. AuthKit reuses the ledger of NowoTransactionDoctrineBundle (`commit_detection`) when the host
registers it. Otherwise it registers the middleware itself (`conflict_response.commit_detection`, default true).

## Unique keys and other database errors

A unique-key violation is not a lock conflict. Up to 1.52 the subscriber turned any unique violation on an AuthKit page
into a 409 `{"error": "conflict"}`. Since 1.53.0 no unique violation, optimistic lock or lost connection is mapped. The
"only one" races AuthKit expects are caught where they happen and answered with the domain result:

- account creation: under the account lock, then `DuplicateUserException` / `UniqueConstraintViolationException`
  caught by `UserRegistrar` and `AdminUserCreator` (a refusal, a duplicate result);
- first MFA enrolment and QR challenge ids: `AuthKitRows::insert` writes in a savepoint and returns the violation
  (the existing credential is used, a new challenge id is drawn);
- invitation acceptance: a conditional `UPDATE` claim, never a unique key;
- social links: the social identity's named lock serializes the writers before the unique key is reached.

Any other unique violation is a real error (500, logged), so it is noticed instead of being hidden as a retryable
answer. Connection loss is never retried or mapped either: the outcome of the write is unknown.

## Host options

```yaml
nowo_auth_kit:
    conflict_response:
        status: ~             # 409 or 503; ~ = NowoTransactionDoctrineBundle's conflict_response.status, else 409
        retry_after: ~        # seconds; ~ = NowoTransactionDoctrineBundle's conflict_response.retry_after, else 1
        commit_detection: true
```

- Leave `status` and `retry_after` unset to answer like the rest of the application: AuthKit then reads
  `nowo_transaction_doctrine.conflict_response` when NowoTransactionDoctrineBundle is registered. Setting either one
  gives AuthKit its own policy (the unset one takes its default: 409 or 1 s).
- `status: 503` keeps the answer of 1.50–1.52 (with the code added).
- `commit_detection: false` drops AuthKit's own middleware. `CONFLICT_002` then comes only from a host write unit
  (`ConcurrencyConflict::$committed`), and a conflict after a commit answers `CONFLICT_001`.

The other concurrency settings are in [High load](USAGE.md#high-load): DBAL 3
savepoints and the topology limits.

## Lock order

Account creation takes, in this order: the social identity's lock, the user class lock (`first_user_only`), the
account's lock, the invitation row, then the account row. The second factor locks the credential row first
(`FOR UPDATE`), then its recovery codes, then its trusted devices. Named locks are taken before the unit's transaction
and released after it. No HTTP call, mail or file I/O runs while a lock is held: mail and events follow the commit.

## Tests

The `Load readiness` CI job runs `tests/Database` on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, each with DBAL 4 and
DBAL 3. No test may be skipped. These are required checks on `main`.

- `ConflictAnswerTest`: through the HTTP kernel, a peer session holds the invitation row:
  - the acceptance answers 409 + `Retry-After` + `CONFLICT_001`, as a page and as JSON, writes nothing, and the retry
    accepts once;
  - a request that committed a unit before the lock wait answers `CONFLICT_002` without `Retry-After`, and the
    committed row is stored once.
- `AcceptanceLocksTest`: a deadlock inside the account's nested flush (MySQL's lost savepoint) and a lock wait are
  transient, and the connection is back at level 0. Two acceptances of one invitation have one winner.
- `AccountLocksTest`, `ConcurrentAccountsTest`, `FirstUserOnlyTest`: two sessions and two worker processes never create
  two accounts for one identifier, or two first accounts. A lock held too long is a transient failure.
- `MfaCredentialRaceTest`: two first enrolments of one owner share one credential.
- `MfaSingleUseRaceTest` (2.2.1): a TOTP step, a recovery code and a trusted-device token rotation presented by two
  requests at once: the one that waits on the other's statement is refused when it commits and goes through when it
  rolls back; one that comes after it is refused.
- `QrLoginClaimRaceTest` (2.2.1): two consumes of one approved challenge sign in once; an approval racing a denial of
  one pending challenge: only one applies, with the same held / committed cases.
- `InvitationResendRaceTest`: a resend or a revoke racing an acceptance writes nothing (2.0, XX-I1).
- `HostLoadedEntitiesTest`: AuthKit rows the application loaded through its own manager are written as the existing
  row, and the application's pending changes stay pending.
- `PasswordResetClaimRaceTest` (2.2): two completions of one reset link, on the user entity and in the reset table: the
  one that waits on the other's claim is refused when it commits and goes through when it rolls back; one that comes
  after it is refused. One password is stored per link.
- Unit tests (`StorageFailureSubscriberTest`) cover the mapping of deadlocks, lock waits and serialization failures,
  the `X-Conflict-Code` and `Retry-After` headers, the 503 option, `CONFLICT_002` from the ledger (also for a
  sub-request after a commit of the main request) and from `ConcurrencyConflict::$committed`, and the errors that are
  not mapped. `HostExtensionsKernelTest` covers a host social handler's lock conflict (409, sign-in undone).

## Residual risks

- **Commit detection is per process.** The ledger counts the commits of the PHP process. A FrankenPHP worker thread or
  a Messenger consumer handles one request at a time, so the difference belongs to the request. A host that runs
  several requests concurrently in one PHP process (fibers, async servers) can see another request's commit and answer
  `CONFLICT_002` where `CONFLICT_001` was enough. That is the conservative side: the client reloads instead of
  retrying.
- **Any commit counts.** A write the request made before AuthKit's unit (session storage, remember-me rotation, a
  login listener) turns a later conflict into `CONFLICT_002`. Again, the client reloads.
- **No retry inside AuthKit.** A conflict is answered, not replayed. Hosts that want automatic replays wrap their own
  controllers with transaction-doctrine's `#[AsWriteUnit]` (AuthKit's routes are one attempt).
- **A host reset store without the claim.** Since 2.2 two completions of one reset link store one password (the
  claim, bound to the validated link token or code). A host `PasswordResetTokenManagerInterface` (or decorator) that
  does not implement `PasswordResetCredentialClaimInterface`, or a host call of `PasswordResetCompleter::complete()`
  without the credential, keeps the 2.1 behaviour: both can store a password and the last one wins.
- **A user AuthKit cannot address** in the user-entity store: a class the injected manager does not map, reset
  fields that are not mapped columns, a composite or missing id. The claim then clears the credential as in 2.1,
  unguarded. A user the manager does not hold (a host user directory's object) is still claimed by id.
- **Magic links** are Symfony's `login_link`: with `max_uses`, the use count lives in Symfony's expired-signature
  storage (a cache), whose check and increment are not atomic, so two simultaneous uses of one link can both sign in
  (the same person, holding the link). This is Symfony's behaviour; AuthKit keeps `max_uses: 1` and a short `lifetime`
  as its documented defaults.
- **Social link after an accepted invitation.** When an invitation is accepted with a social provider, the account is
  created and committed first; the social link is a second unit. If that second unit fails (a host lookup, or even a
  lock conflict), it rolls back on its own and the accepted account still signs in; the failure is logged and the
  provider can be linked at the next social sign-in. Nothing half-saved is reported: the acceptance is complete
  without the link, and no unit stays open, so this is not a swallowed failure inside a unit (rule 8).
- **Console commands** (`create-admin`, purges) fail with the transient failure instead of answering a code. They
  write nothing (create) or continue on the next run (purges).
- **Known limits of the account locks** (PgBouncer transaction pooling, Galera, a failed release inside a host
  transaction): see [High load](USAGE.md#high-load).
