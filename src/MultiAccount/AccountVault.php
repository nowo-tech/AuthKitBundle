<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountAddedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountRemovedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchingEvent;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionStatus;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function hash_equals;
use function in_array;
use function is_array;
use function is_string;
use function random_bytes;
use function serialize;
use function sprintf;
use function unserialize;
use function usort;

/**
 * Several accounts signed in at once in one browser (1.34, `multi_account`). Every account keeps the session of its
 * own complete sign-in; the inactive ones are **parked**: their session id and remember-me cookie are sealed in the
 * vault under the account's cookie token. Switching re-opens the target's session in the same request (no new
 * authentication is created), re-runs the firewall's user checker, regenerates the session id and moves UserKit's
 * row with it. UserKit (≥ 1.11, required) tells whether a parked session is still alive.
 *
 * Session attributes (per profile): the account this session belongs to (`SLOT_ATTRIBUTE`) and the "add another
 * account" mode (`ADD_ATTRIBUTE`). Stateless between requests (worker safe).
 */
final readonly class AccountVault
{
    public const SLOT_ATTRIBUTE = '_nowo_auth_kit_accounts_slot';

    public const ADD_ATTRIBUTE = '_nowo_auth_kit_accounts_add';

    /** Request attribute: the remember-me cookie to write (`firewall`, `value`; null value = clear). */
    public const REMEMBER_ME_ATTRIBUTE = '_nowo_auth_kit_accounts_remember_me';

    /** Seconds the "add another account" mode lasts. */
    public const ADD_LIFETIME = 900;

    /** UserKit custom revocation reason when a copied vault cookie is detected (audit DE-M2). */
    public const REUSE_REVOCATION = 'vault_token_reuse';

    /**
     * @param UserProviderInterface<UserInterface> $users the chain of the security user providers (fallback)
     * @param ContainerInterface $userCheckers user checker of each firewall
     * @param ContainerInterface|null $userProviders user provider of each firewall that names one (audit DE-I5)
     * @param ContainerInterface $gates enrolment gate of each profile (fallback: {@see DefaultEnrolmentGate})
     * @param ContainerInterface $presenters presenter of each profile (fallback: {@see IdentifierAccountPresenter})
     * @param array<string, array{name: string, path: string, domain: string|null, secure: bool|string|null, samesite: string|null, lifetime: int}|null> $rememberMe remember-me cookie of each firewall
     * @param array<string, string> $contexts security context of each firewall (`_security_<context>`)
     */
    public function __construct(
        private ProfileRegistry $profiles,
        private AccountVaultStore $store,
        private TokenStorageInterface $tokens,
        private UserProviderInterface $users,
        private ContainerInterface $userCheckers,
        private UserSessionManager $sessions,
        private ClockInterface $clock,
        private AuthKitAttemptLimiter $limiter,
        private ContainerInterface $gates,
        private ContainerInterface $presenters,
        private array $rememberMe = [],
        private array $contexts = [],
        private ?EventDispatcherInterface $dispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?ContainerInterface $userProviders = null,
    ) {
    }

    /**
     * The multi-account profile of a request's firewall user class, or null.
     */
    public function profile(string $name): ?ProfileSettings
    {
        $profile = array_key_exists($name, $this->profiles->all()) ? $this->profiles->getByName($name) : null;

        return $profile?->multiAccountEnabled() === true ? $profile : null;
    }

    /**
     * The entries of the browser's vault cookie. Expired entries are removed; a previous token presented within
     * `rotation_grace` (a concurrent tab) opens its entry under a fresh token; one presented later (reuse: the cookie
     * was copied) removes the entry and signs the account out everywhere. Unknown tokens are dropped and counted
     * (`rate_limit.invalid`, logged only: known tokens are never refused because of them, audit DE-M3).
     *
     * @return list<VaultEntry>
     */
    public function entries(Request $request, ProfileSettings $profile): array
    {
        $tokens = VaultCookie::tokens($request, $profile);
        if ($tokens === []) {
            return [];
        }
        $crypto = $this->crypto($profile);
        $byHash = [];
        foreach ($tokens as $token) {
            $byHash[$crypto->hash($token)] = $token;
        }
        $now     = $this->now();
        $grace   = (int) ($profile->multiAccount['rotation_grace'] ?? 30);
        $entries = [];
        $kept    = [];
        $known   = [];
        $changed = false;
        foreach ($this->store->find($profile->name, array_map('strval', array_keys($byHash))) as $row) {
            $current       = isset($byHash[$row['token_hash']]);
            $token         = $current ? $byHash[$row['token_hash']] : $byHash[(string) $row['previous_token_hash']];
            $known[$token] = true;
            if ($row['expires_at'] <= $now) {
                $this->remove($request, $profile, [$row['id']], $row['public_id'], $row['user_identifier'], AccountRemovalReason::Expired);
                $changed = true;

                continue;
            }
            if ($current) {
                $entries[] = new VaultEntry($row['id'], $row['public_id'], $row['user_identifier'], $token, $row['token_hash'], $row['sealed'], $row['expires_at']);

                continue;
            }
            $changed = true;
            if ($now->getTimestamp() - $row['rotated_at']->getTimestamp() > $grace) {
                $this->reused($request, $profile, $row);

                continue;
            }
            // Within the grace period. Another browser rotated it (a thief's switch with a copied cookie): ignored,
            // the token stays, so presenting it after the grace period still reveals the reuse (DE-M5).
            $browser = $this->browser($request, $profile);
            if ($row['rotated_by'] === null || $browser === null || !hash_equals($row['rotated_by'], $browser)) {
                $kept[] = $token;

                continue;
            }
            // This browser's concurrent request rotated it a moment ago: the cookie takes the current token, so both
            // racing responses write the same value (DE-L3, DE-L7).
            $entry = $this->catchUp($profile, $row, $token);
            if ($entry instanceof VaultEntry) {
                $entries[] = $entry;
            } elseif ($entry === true) {
                // Rotated twice within the grace period: the blob cannot be opened with this token now, keep it.
                $kept[] = $token;
            }
        }
        $unknown = array_values(array_filter($tokens, static fn (string $token): bool => !isset($known[$token])));
        if ($unknown !== []) {
            $this->countUnknown($request, $profile);
        }
        if ($unknown !== [] || $changed) {
            VaultCookie::queue($request, $profile, [...array_map(static fn (VaultEntry $entry): string => $entry->token, $entries), ...$kept]);
        }

        return $entries;
    }

    /**
     * The accounts of the switcher, the active one first; parked accounts whose session was revoked (or that cannot
     * come back) are removed on the way. Memoised per request.
     *
     * @return list<AccountState>
     */
    public function accounts(Request $request, ProfileSettings $profile): array
    {
        $memo = $request->attributes->get('_nowo_auth_kit_accounts_list');
        if (is_array($memo) && is_array($memo[$profile->name] ?? null)) {
            /** @var list<AccountState> $cached */
            $cached = $memo[$profile->name];

            return $cached;
        }
        $entries = $this->entries($request, $profile);
        $active  = $this->active($request, $profile, $entries);
        $crypto  = $this->crypto($profile);
        $revive  = ($profile->multiAccount['revive_with_remember_me'] ?? true) === true;
        $parked  = [];
        $users   = [];
        $states  = [];
        foreach ($entries as $entry) {
            $user = $this->loadUser($profile, $entry->userIdentifier);
            if (!$user instanceof UserInterface) {
                $this->remove($request, $profile, [$entry->id], $entry->publicId, $entry->userIdentifier, AccountRemovalReason::UserRemoved, $entries);

                continue;
            }
            $users[$entry->publicId] = $user;
            if ($entry->publicId === $active?->publicId) {
                $states[] = new AccountState($entry->publicId, $user, $this->presenter($profile)->present($user), AccountState::ACTIVE);

                continue;
            }
            if ($entry->sealed === null) {
                // Active in another browser that holds a copy of this cookie (or its session ended there): not
                // listed here, and not removed either (audit DE-I6).
                continue;
            }
            $payload                  = $crypto->unseal($entry->token, $this->associatedData($profile, $entry->publicId, $entry->userIdentifier), $entry->sealed);
            $parked[$entry->publicId] = ['entry' => $entry, 'sid' => is_string($payload['sid'] ?? null) ? $payload['sid'] : '', 'rm' => is_string($payload['rm'] ?? null) ? $payload['rm'] : null];
        }
        $sessionStates = $this->sessions->sessionStates(array_values(array_map(static fn (array $p): array => [$p['sid'], $users[$p['entry']->publicId]], $parked)));
        $i             = 0;
        foreach ($parked as $publicId => $p) {
            $state = $sessionStates[$i++];
            if ($state->isLive()) {
                $states[] = new AccountState($publicId, $users[$publicId], $this->presenter($profile)->present($users[$publicId]), AccountState::SIGNED_IN);
            } elseif ($state->status === SessionStatus::Expired && $revive && $p['rm'] !== null) {
                $states[] = new AccountState($publicId, $users[$publicId], $this->presenter($profile)->present($users[$publicId]), AccountState::SIGNED_OUT);
            } else {
                $this->remove($request, $profile, [$p['entry']->id], $publicId, $p['entry']->userIdentifier, $state->status === SessionStatus::Expired ? AccountRemovalReason::Expired : AccountRemovalReason::Revoked, $entries, $users[$publicId]);
            }
        }
        usort($states, static fn (AccountState $a, AccountState $b): int => ($b->state === AccountState::ACTIVE) <=> ($a->state === AccountState::ACTIVE));
        $memo                 = is_array($memo) ? $memo : [];
        $memo[$profile->name] = $states;
        $request->attributes->set('_nowo_auth_kit_accounts_list', $memo);

        return $states;
    }

    /**
     * Whether one more account fits in this browser.
     */
    public function canAdd(Request $request, ProfileSettings $profile): bool
    {
        $entries = $this->entries($request, $profile);
        $current = $this->currentUser();
        $slots   = count($entries) + ($current instanceof UserInterface && !$this->entryOf($entries, $current->getUserIdentifier()) instanceof VaultEntry ? 1 : 0);

        return !$this->tokens->getToken() instanceof SwitchUserToken && $slots < (int) ($profile->multiAccount['max_accounts'] ?? 5);
    }

    /**
     * "Add another account": parks the current account (if any), clears the remember-me cookie and opens an empty
     * session in add mode; the caller redirects to the sign-in page. False when the browser is full, impersonating or
     * the current sign-in is not complete.
     */
    public function add(Request $request, ProfileSettings $profile): bool
    {
        if (!$this->canAdd($request, $profile)) {
            return false;
        }
        $session = $request->getSession();
        if ($this->currentUser() instanceof UserInterface && !$this->park($request, $profile)) {
            return false;
        }
        SessionHopper::fresh($session);
        $this->tokens->setToken(null);
        $session->set(self::ADD_ATTRIBUTE, [$profile->name => $this->now()->getTimestamp() + self::ADD_LIFETIME]);
        $this->queueRememberMe($request, $profile, null);

        return true;
    }

    /**
     * Switches to a parked account of this browser.
     */
    public function switch(Request $request, ProfileSettings $profile, string $publicId): SwitchOutcome
    {
        if ($this->tokens->getToken() instanceof SwitchUserToken || $this->tokens->getToken() instanceof MfaPendingToken) {
            return SwitchOutcome::refused('accounts.flash.not_now');
        }
        $entries = $this->entries($request, $profile);
        $target  = null;
        foreach ($entries as $entry) {
            if ($entry->publicId === $publicId) {
                $target = $entry;
            }
        }
        $active = $this->active($request, $profile, $entries);
        if (!$target instanceof VaultEntry || $target->sealed === null || $target->publicId === $active?->publicId) {
            return SwitchOutcome::notFound();
        }
        $user    = $this->loadUser($profile, $target->userIdentifier);
        $payload = $this->crypto($profile)->unseal($target->token, $this->associatedData($profile, $target->publicId, $target->userIdentifier), $target->sealed);
        $sid     = is_string($payload['sid'] ?? null) ? $payload['sid'] : '';
        $rm      = is_string($payload['rm'] ?? null) ? $payload['rm'] : null;
        if (!$user instanceof UserInterface) {
            $this->remove($request, $profile, [$target->id], $target->publicId, $target->userIdentifier, AccountRemovalReason::UserRemoved, $entries);

            return SwitchOutcome::gone();
        }
        $state = $this->sessions->sessionState($sid, $user);
        if (!$state->isLive()) {
            // Only an expired session comes back through its remember-me cookie; a revoked one never does (DE-M1).
            if ($state->status !== SessionStatus::Expired || $rm === null || ($profile->multiAccount['revive_with_remember_me'] ?? true) !== true) {
                $this->remove($request, $profile, [$target->id], $target->publicId, $target->userIdentifier, $state->status === SessionStatus::Expired ? AccountRemovalReason::Expired : AccountRemovalReason::Revoked, $entries, $user);

                return SwitchOutcome::gone();
            }

            return $this->revive($request, $profile, $target, $rm, $sid, $user);
        }

        return $this->reopen($request, $profile, $target, $sid, $rm, $user);
    }

    /**
     * Signs one account of the browser out: a parked one is ended here (its UserKit row deleted, its session data
     * emptied); for the active one the caller runs the firewall sign-out with `logout_scope: current`.
     *
     * @return bool|null true: a parked account ended; null: it is the active account; false: unknown
     */
    public function signOut(Request $request, ProfileSettings $profile, string $publicId): ?bool
    {
        $entries = $this->entries($request, $profile);
        $active  = $this->active($request, $profile, $entries);
        if ($active?->publicId === $publicId) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry->publicId === $publicId) {
                $this->end($request, $profile, $entry, $entries, AccountRemovalReason::SignedOut);

                return true;
            }
        }

        return false;
    }

    /**
     * Ends every parked account of the browser and removes every entry (the active one included, the caller signs it
     * out through the firewall). `$reason`: signed_out_all or logout.
     */
    public function endAll(Request $request, ProfileSettings $profile, AccountRemovalReason $reason): void
    {
        $entries = $this->entries($request, $profile);
        $active  = $this->active($request, $profile, $entries);
        foreach ($entries as $entry) {
            if ($entry->publicId !== $active?->publicId) {
                $this->end($request, $profile, $entry, $entries, $reason);
            }
        }
        if ($active instanceof VaultEntry) {
            $this->remove($request, $profile, [$active->id], $active->publicId, $active->userIdentifier, $reason, $entries, $this->currentUser());
        }
        VaultCookie::queue($request, $profile, []);
    }

    /**
     * The firewall sign-out of the active account with `logout_scope: current`: only its entry goes. Returns whether
     * other accounts remain.
     */
    public function forgetActive(Request $request, ProfileSettings $profile): bool
    {
        $entries = $this->entries($request, $profile);
        $active  = $this->active($request, $profile, $entries);
        if ($active instanceof VaultEntry) {
            $this->remove($request, $profile, [$active->id], $active->publicId, $active->userIdentifier, AccountRemovalReason::Logout, $entries, $this->currentUser());
            $entries = array_values(array_filter($entries, static fn (VaultEntry $entry): bool => $entry->id !== $active->id));
        }

        return $entries !== [];
    }

    /**
     * A completed sign-in (after any second factor) joins the switcher: a known account of this browser is re-bound
     * (its session, a fresh token, a new `max_lifetime`); a new account only in "add another account" mode and when
     * it fits. Admin-issued magic links never enrol (D8).
     */
    public function enrol(Request $request, ProfileSettings $profile, TokenInterface $token, bool $adminLink = false): void
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface || !$this->gate($profile)->isComplete($token) || !$request->hasSession()) {
            return;
        }
        $session  = $request->getSession();
        $entries  = $this->entries($request, $profile);
        $existing = $this->entryOf($entries, $user->getUserIdentifier());
        $now      = $this->now();
        $expires  = $now->modify(sprintf('+%d seconds', (int) ($profile->multiAccount['max_lifetime'] ?? 2592000)));
        $crypto   = $this->crypto($profile);
        $fresh    = VaultCrypto::newToken();
        if ($existing instanceof VaultEntry) {
            // Re-bound to this new sign-in: the parked session it replaces is ended, not orphaned (DE-L4).
            $this->endParked($request, $profile, $existing, $user);
            if ($this->rotate($request, $profile, $existing, $fresh, null, $now, $expires)) {
                $this->replaceToken($request, $profile, $existing->token, $fresh);
            }
            $this->setSlot($session, $profile, $existing->publicId);

            return;
        }
        $mode = $session->get(self::ADD_ATTRIBUTE);
        $session->remove(self::ADD_ATTRIBUTE);
        $inAddMode = is_array($mode) && ($mode[$profile->name] ?? 0) >= $now->getTimestamp();
        if (!$inAddMode || $adminLink || count($entries) >= (int) ($profile->multiAccount['max_accounts'] ?? 5)) {
            return;
        }
        $publicId = bin2hex(random_bytes(16));
        $this->store->insert($publicId, $profile->name, $user->getUserIdentifier(), $crypto->hash($fresh), null, $now, $expires);
        VaultCookie::queue($request, $profile, [...array_map(static fn (VaultEntry $entry): string => $entry->token, $entries), $fresh]);
        $this->setSlot($session, $profile, $publicId);
        $this->dispatcher?->dispatch(new AccountAddedEvent($profile->name, $publicId, $request, $user));
        $this->logger->info('Account added to the browser switcher.', ['profile' => $profile->name, 'account' => $publicId]);
    }

    /**
     * Removes one account from this browser's switcher only: a sign-in that failed after it had enrolled (GY-L6). The
     * account stays in its other browsers, and the other accounts of this browser stay.
     */
    public function forgetInBrowser(Request $request, ProfileSettings $profile, string $userIdentifier): void
    {
        $entries = $this->entries($request, $profile);
        $entry   = $this->entryOf($entries, $userIdentifier);
        if ($entry instanceof VaultEntry) {
            $this->remove($request, $profile, [$entry->id], $entry->publicId, $entry->userIdentifier, AccountRemovalReason::SignedOut, $entries);
        }
    }

    /**
     * Removes a user of a profile from every browser's switcher (signed out everywhere, disabled, removed).
     */
    public function forgetUser(ProfileSettings $profile, string $userIdentifier): int
    {
        return $this->store->deleteForUser($profile->name, $userIdentifier);
    }

    /**
     * Whether the request holds a complete sign-in (the switcher then shows full identifiers).
     */
    public function isSignedIn(): bool
    {
        return $this->currentUser() instanceof UserInterface;
    }

    /**
     * The remember-me cookie options of a firewall (null without remember-me).
     *
     * @return array{name: string, path: string, domain: string|null, secure: bool|string|null, samesite: string|null, lifetime: int}|null
     */
    public function rememberMeCookie(string $firewall): ?array
    {
        return $this->rememberMe[$firewall] ?? null;
    }

    private function reopen(Request $request, ProfileSettings $profile, VaultEntry $target, string $sid, ?string $rm, UserInterface $user): SwitchOutcome
    {
        $session  = $request->getSession();
        $from     = $this->currentUser();
        $previous = $this->leave($request, $profile);
        try {
            SessionHopper::open($session, $sid);
            [$token, $refusal, $parameters] = $this->admit($request, $profile, $target, $from);
        } catch (Throwable $e) {
            // A host listener, the user provider or the session storage failed: the browser goes back to its
            // previous session and token, the target stays parked (audit DE-M4).
            $this->back($session, $previous);
            $this->logger->error('Account switch failed; the browser stays on its previous account.', ['profile' => $profile->name, 'account' => $target->publicId, 'exception' => $e]);

            return SwitchOutcome::refused('accounts.flash.not_now');
        }
        if (!$token instanceof TokenInterface) {
            // Roll back: the target's session ends, its entry goes, the browser stays on its previous session.
            $session->clear();
            $this->sessions->endSession($sid, $user);
            $this->back($session, $previous);
            $this->remove($request, $profile, [$target->id], $target->publicId, $target->userIdentifier, AccountRemovalReason::Refused, null, $user);
            $this->logger->info('Account switch refused.', ['profile' => $profile->name, 'account' => $target->publicId]);

            return SwitchOutcome::refused($refusal ?? 'accounts.flash.gone', $parameters);
        }
        $session->migrate(true);
        // The target's own token from here on: whatever fails below, the firewall writes it into its own session.
        $this->tokens->setToken($token);
        $this->sessions->rekey($session, $sid);
        $this->setSlot($session, $profile, $target->publicId);
        $fresh = VaultCrypto::newToken();
        if ($this->rotate($request, $profile, $target, $fresh, null, $this->now())) {
            $this->replaceToken($request, $profile, $target->token, $fresh);
        }
        $this->queueRememberMe($request, $profile, $rm);
        $this->dispatcher?->dispatch(new AccountSwitchedEvent($profile->name, $target->publicId, $request, $from, $token->getUser() ?? $user));
        $this->logger->info('Account switched.', ['profile' => $profile->name, 'account' => $target->publicId]);

        return SwitchOutcome::switched($user);
    }

    /**
     * Admits the parked session now open: its own token, for the entry's user (DE-I2), refreshed, accepted by the
     * firewall's user checker and by host listeners.
     *
     * @return array{0: TokenInterface|null, 1: string|null, 2: array<string, mixed>} the token, or null with the refusal
     */
    private function admit(Request $request, ProfileSettings $profile, VaultEntry $target, ?UserInterface $from): array
    {
        $serialized = $request->getSession()->get('_security_' . $this->context($profile));
        $token      = is_string($serialized) ? unserialize($serialized, ['allowed_classes' => true]) : null;
        if (!$token instanceof TokenInterface || $token instanceof MfaPendingToken || !$token->getUser() instanceof UserInterface || $token->getUserIdentifier() !== $target->userIdentifier) {
            return [null, 'accounts.flash.gone', []];
        }
        try {
            $fresh = $this->provider($profile)->refreshUser($token->getUser());
            // @igor-ignore - The parked session's own token (unserialized for this request), not a shared service.
            $token->setUser($fresh);
            $checker = $this->userCheckers->get($profile->firewall);
            if ($checker instanceof UserCheckerInterface) {
                $checker->checkPreAuth($fresh);
                $checker->checkPostAuth($fresh, $token);
            }
            $event = new AccountSwitchingEvent($profile->name, $target->publicId, $request, $from, $fresh);
            $this->dispatcher?->dispatch($event);
            if ($event->refusal() !== null) {
                return [null, $event->refusal(), $event->refusalParameters()];
            }
        } catch (AuthenticationException $e) {
            // The user checker (disabled, locked…) or the provider (user gone) refused the account.
            return [null, $e->getMessageKey(), $e->getMessageData()];
        }

        return [$token, null, []];
    }

    /**
     * Back to the session left by {@see self::leave()}, with its token.
     *
     * @param array{id: string, token: TokenInterface|null} $previous
     */
    private function back(SessionInterface $session, array $previous): void
    {
        SessionHopper::open($session, $previous['id']);
        $this->tokens->setToken($previous['token']);
    }

    /**
     * The parked session expired but the account had a remember-me cookie (D9): a fresh session with that cookie;
     * the firewall's remember-me sign-in on the next request brings the account back (user checker, UserKit epoch).
     */
    private function revive(Request $request, ProfileSettings $profile, VaultEntry $target, string $rm, string $sid, UserInterface $user): SwitchOutcome
    {
        $this->leave($request, $profile);
        $this->sessions->endSession($sid, $user);
        SessionHopper::fresh($request->getSession());
        $this->tokens->setToken(null);
        $this->setSlot($request->getSession(), $profile, $target->publicId);
        $fresh = VaultCrypto::newToken();
        if ($this->rotate($request, $profile, $target, $fresh, null, $this->now())) {
            $this->replaceToken($request, $profile, $target->token, $fresh);
        }
        $this->queueRememberMe($request, $profile, $rm);

        return SwitchOutcome::revived($user);
    }

    /**
     * Leaves the current session before a switch: a complete sign-in is parked (or ended when it cannot be parked).
     * The token storage is emptied, so the firewall never writes this account's token into another session (DE-M4).
     *
     * @return array{id: string, token: TokenInterface|null} the session left and the token to restore on a roll-back
     */
    private function leave(Request $request, ProfileSettings $profile): array
    {
        $session = $request->getSession();
        $token   = $this->tokens->getToken();
        $user    = $this->currentUser();
        $id      = $session->getId();
        if ($user instanceof UserInterface && !$this->park($request, $profile)) {
            // Not parkable (incomplete sign-in or no room): this session is signed out before leaving it.
            $this->sessions->endSession($id, $user);
            $session->clear();
            $token = null;
        }
        SessionHopper::close($session);
        $this->tokens->setToken(null);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * Parks the current account: its session id and remember-me cookie are sealed under a fresh token of its entry
     * (created when missing and there is room). False when the sign-in is not complete or the browser is full.
     */
    private function park(Request $request, ProfileSettings $profile): bool
    {
        $token   = $this->tokens->getToken();
        $user    = $token?->getUser();
        $entries = $this->entries($request, $profile);
        $entry   = $user instanceof UserInterface ? $this->entryOf($entries, $user->getUserIdentifier()) : null;
        $fits    = $entry instanceof VaultEntry || count($entries) < (int) ($profile->multiAccount['max_accounts'] ?? 5);
        if (!$token instanceof TokenInterface || !$user instanceof UserInterface || !$fits || !$this->gate($profile)->isComplete($token)) {
            return false;
        }
        $session = $request->getSession();
        // The firewall writes the token into the session only at kernel.response: write it now (S0).
        $session->set('_security_' . $this->context($profile), serialize($token));
        $cookie  = $this->rememberMeCookie($profile->firewall);
        $rmValue = $cookie !== null ? $request->cookies->get($cookie['name']) : null;
        $payload = ['sid' => $session->getId(), 'rm' => is_string($rmValue) && $rmValue !== '' ? $rmValue : null];
        $crypto  = $this->crypto($profile);
        $fresh   = VaultCrypto::newToken();
        $now     = $this->now();
        if ($entry instanceof VaultEntry) {
            if (!$this->rotate($request, $profile, $entry, $fresh, $crypto->seal($fresh, $this->associatedData($profile, $entry->publicId, $entry->userIdentifier), $payload), $now)) {
                // Another request rotated the entry first (a race of two requests): nothing was sealed, the account
                // cannot be parked.
                return false; // @codeCoverageIgnore
            }
            $this->replaceToken($request, $profile, $entry->token, $fresh);

            return true;
        }
        $publicId = bin2hex(random_bytes(16));
        $sealed   = $crypto->seal($fresh, $this->associatedData($profile, $publicId, $user->getUserIdentifier()), $payload);
        $this->store->insert($publicId, $profile->name, $user->getUserIdentifier(), $crypto->hash($fresh), $sealed, $now, $now->modify(sprintf('+%d seconds', (int) ($profile->multiAccount['max_lifetime'] ?? 2592000))));
        VaultCookie::queue($request, $profile, [...array_map(static fn (VaultEntry $e): string => $e->token, $entries), $fresh]);
        $this->dispatcher?->dispatch(new AccountAddedEvent($profile->name, $publicId, $request, $user));

        return true;
    }

    /**
     * Ends a parked account: its UserKit row and its session data go, then its entry.
     *
     * @param list<VaultEntry> $entries
     */
    private function end(Request $request, ProfileSettings $profile, VaultEntry $entry, array $entries, AccountRemovalReason $reason): void
    {
        $user = $this->loadUser($profile, $entry->userIdentifier);
        $this->endParked($request, $profile, $entry, $user);
        $this->remove($request, $profile, [$entry->id], $entry->publicId, $entry->userIdentifier, $reason, $entries, $user);
    }

    /**
     * Ends the parked session of an entry, if any (its UserKit row and its session data), unless it is the request's
     * own session.
     */
    private function endParked(Request $request, ProfileSettings $profile, VaultEntry $entry, ?UserInterface $user): void
    {
        $payload = $entry->sealed !== null ? $this->crypto($profile)->unseal($entry->token, $this->associatedData($profile, $entry->publicId, $entry->userIdentifier), $entry->sealed) : null;
        $sid     = is_string($payload['sid'] ?? null) ? $payload['sid'] : '';
        if ($sid === '' || !$request->hasSession() || $sid === $request->getSession()->getId()) {
            return;
        }
        if ($user instanceof UserInterface) {
            $this->sessions->endSession($sid, $user);
        }
        $session = $request->getSession();
        SessionHopper::wipe($session, $sid, $session->getId());
    }

    /**
     * A previous token came back after the grace period: the cookie was copied. The entry goes for everyone and the
     * account is signed out everywhere (with its remember-me cookies, through UserKit's epoch), so a thief who
     * switched to it first loses it too (audit DE-M2). UserKit then removes the user from every browser.
     *
     * @param array{id: int, public_id: string, user_identifier: string} $row
     */
    private function reused(Request $request, ProfileSettings $profile, array $row): void
    {
        $user = $this->loadUser($profile, $row['user_identifier']);
        $this->remove($request, $profile, [$row['id']], $row['public_id'], $row['user_identifier'], AccountRemovalReason::TokenReuse, null, $user);
        if ($user instanceof UserInterface) {
            $this->sessions->revokeAll($user, null, RevocationReason::Everywhere, customReason: self::REUSE_REVOCATION, context: ['profile' => $profile->name]);
        }
        $this->logger->warning('Account vault token reused after rotation; the account was removed from the browser and signed out everywhere.', ['profile' => $profile->name, 'account' => $row['public_id']]);
    }

    /**
     * Opens an entry through its previous token (within `rotation_grace`, same browser): the current token, sealed
     * under the previous one at rotation, without rotating again. True when it cannot be opened now (keep the token);
     * null when the blob is forged.
     *
     * @param array{id: int, public_id: string, user_identifier: string, token_hash: string, sealed: string|null, previous_sealed: string|null, expires_at: DateTimeImmutable} $row
     */
    private function catchUp(ProfileSettings $profile, array $row, string $token): VaultEntry|true|null
    {
        if ($row['previous_sealed'] === null) {
            return true;
        }
        $crypto  = $this->crypto($profile);
        $payload = $crypto->unseal($token, $this->associatedData($profile, $row['public_id'], $row['user_identifier']), $row['previous_sealed']);
        $current = is_string($payload['t'] ?? null) ? $payload['t'] : null;
        if ($current === null || !hash_equals($row['token_hash'], $crypto->hash($current))) {
            return null;
        }

        return new VaultEntry($row['id'], $row['public_id'], $row['user_identifier'], $current, $row['token_hash'], $row['sealed'], $row['expires_at']);
    }

    /**
     * Rotates an entry to `$fresh` (conditional on its current token), sealing `$fresh` under the old token for this
     * browser's concurrent requests and recording which browser rotated it.
     */
    private function rotate(Request $request, ProfileSettings $profile, VaultEntry $entry, string $fresh, ?string $sealed, DateTimeImmutable $now, ?DateTimeImmutable $expires = null): bool
    {
        $crypto   = $this->crypto($profile);
        $previous = $crypto->seal($entry->token, $this->associatedData($profile, $entry->publicId, $entry->userIdentifier), ['t' => $fresh]);

        return $this->store->rotate($entry->id, $entry->tokenHash, $crypto->hash($fresh), $sealed, $previous, $this->browser($request, $profile), $now, $expires);
    }

    /**
     * Keyed hash of the session id the request arrived with (the browser that rotates), null without one.
     */
    private function browser(Request $request, ProfileSettings $profile): ?string
    {
        $id = $request->hasSession() ? $request->cookies->get($request->getSession()->getName()) : null;

        return is_string($id) && $id !== '' ? $this->crypto($profile)->hash('browser|' . $id) : null;
    }

    /**
     * Counts the unknown tokens of a client and logs when there are many (stale cookies are normal; many mean
     * guessing). Never refuses: 256-bit tokens cannot be guessed, and a shared IP must not empty other vaults (DE-M3).
     */
    private function countUnknown(Request $request, ProfileSettings $profile): void
    {
        $limit = $profile->multiAccount['rate_limit']['invalid'] ?? ['limit' => 20, 'window' => 600];
        $key   = 'accounts:invalid:' . ($request->getClientIp() ?? 'unknown');
        $this->limiter->hit($key, (int) $limit['window']);
        if (!$this->limiter->isAllowed($key, (int) $limit['limit'])) {
            $this->logger->warning('Many unknown account vault tokens from one client.', ['profile' => $profile->name]);
        }
    }

    /**
     * Deletes entries, rewrites the cookie (when the entries are known) and dispatches the removal.
     *
     * @param list<int> $ids
     * @param list<VaultEntry>|null $entries
     */
    private function remove(Request $request, ProfileSettings $profile, array $ids, string $publicId, string $userIdentifier, AccountRemovalReason $reason, ?array $entries = null, ?UserInterface $user = null): void
    {
        $this->store->delete($ids);
        if ($entries !== null) {
            $tokens = VaultCookie::tokens($request, $profile);
            $gone   = array_map(static fn (VaultEntry $entry): string => $entry->token, array_filter($entries, static fn (VaultEntry $entry): bool => in_array($entry->id, $ids, true)));
            VaultCookie::queue($request, $profile, array_values(array_filter($tokens, static fn (string $token): bool => !in_array($token, $gone, true))));
        }
        $request->attributes->remove('_nowo_auth_kit_accounts_list');
        $this->dispatcher?->dispatch(new AccountRemovedEvent($profile->name, $publicId, $request, $user ?? $this->loadUser($profile, $userIdentifier), $reason));
        $this->logger->info('Account removed from the browser switcher.', ['profile' => $profile->name, 'account' => $publicId, 'reason' => $reason->value]);
    }

    private function replaceToken(Request $request, ProfileSettings $profile, string $old, string $new): void
    {
        $tokens = VaultCookie::tokens($request, $profile);
        $tokens = array_map(static fn (string $token): string => $token === $old ? $new : $token, $tokens);
        VaultCookie::queue($request, $profile, $tokens);
        $request->attributes->remove('_nowo_auth_kit_accounts_list');
    }

    /**
     * @param list<VaultEntry> $entries
     */
    public function active(Request $request, ProfileSettings $profile, array $entries): ?VaultEntry
    {
        $user = $this->currentUser();
        if (!$user instanceof UserInterface || !$request->hasSession()) {
            return null;
        }
        $slot = $request->getSession()->get(self::SLOT_ATTRIBUTE);
        $id   = is_array($slot) ? ($slot[$profile->name] ?? null) : null;
        foreach ($entries as $entry) {
            if ($entry->publicId === $id && $entry->userIdentifier === $user->getUserIdentifier()) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<VaultEntry> $entries
     */
    private function entryOf(array $entries, string $userIdentifier): ?VaultEntry
    {
        foreach ($entries as $entry) {
            if ($entry->userIdentifier === $userIdentifier) {
                return $entry;
            }
        }

        return null;
    }

    private function setSlot(SessionInterface $session, ProfileSettings $profile, string $publicId): void
    {
        $slot                 = $session->get(self::SLOT_ATTRIBUTE);
        $slot                 = is_array($slot) ? $slot : [];
        $slot[$profile->name] = $publicId;
        // @igor-ignore - The request's own session (not an injected service).
        $session->set(self::SLOT_ATTRIBUTE, $slot);
    }

    private function queueRememberMe(Request $request, ProfileSettings $profile, ?string $value): void
    {
        if ($this->rememberMeCookie($profile->firewall) !== null) {
            $request->attributes->set(self::REMEMBER_ME_ATTRIBUTE, ['firewall' => $profile->firewall, 'value' => $value]);
        }
    }

    private function currentUser(): ?UserInterface
    {
        $token = $this->tokens->getToken();

        return $token instanceof MfaPendingToken ? null : $token?->getUser();
    }

    private function loadUser(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        try {
            return $this->provider($profile)->loadUserByIdentifier($identifier);
        } catch (AuthenticationException) {
            return null;
        }
    }

    /**
     * The user provider of the profile's firewall (the chain when the firewall names none).
     *
     * @return UserProviderInterface<UserInterface>
     */
    private function provider(ProfileSettings $profile): UserProviderInterface
    {
        $provider = $this->userProviders?->has($profile->firewall) === true ? $this->userProviders->get($profile->firewall) : null;

        return $provider instanceof UserProviderInterface ? $provider : $this->users;
    }

    private function gate(ProfileSettings $profile): EnrolmentGateInterface
    {
        $gate = $this->gates->has($profile->name) ? $this->gates->get($profile->name) : null;

        return $gate instanceof EnrolmentGateInterface ? $gate : new DefaultEnrolmentGate();
    }

    private function presenter(ProfileSettings $profile): AccountPresenterInterface
    {
        $presenter = $this->presenters->has($profile->name) ? $this->presenters->get($profile->name) : null;

        return $presenter instanceof AccountPresenterInterface ? $presenter : new IdentifierAccountPresenter();
    }

    private function context(ProfileSettings $profile): string
    {
        return $this->contexts[$profile->firewall] ?? $profile->firewall;
    }

    /**
     * Associated data of a sealed blob: it opens only for its profile, entry and user (DE-I2).
     */
    private function associatedData(ProfileSettings $profile, string $publicId, string $userIdentifier): string
    {
        return $profile->name . '|' . $publicId . '|' . $userIdentifier;
    }

    private function crypto(ProfileSettings $profile): VaultCrypto
    {
        return new VaultCrypto((string) ($profile->multiAccount['hash_secret'] ?? ''));
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
