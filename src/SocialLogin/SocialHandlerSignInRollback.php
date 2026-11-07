<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\Sessions\Model\SessionStatus;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener as RememberMeResponseListener;
use Throwable;

use function array_key_exists;

/**
 * A sign-in failed after the token was set, whether in a social callback handler, a `LoginSuccessEvent` listener or a
 * commit (GY-L6, GY-L10): a session that existed before it goes on, and nothing of the failed sign-in stays reachable.
 *
 * - The earlier token is back in the token storage (the firewall stores it in the session on the response).
 * - Session attributes the failed sign-in added or changed are undone: the MFA "recent sign-in" time, the switcher slot,
 *   the new-device flag, pending flags. Attributes it removed stay removed, among them the CSRF tokens the session
 *   strategy cleared and the pending entry the handler already took.
 * - UserKit: the failed account's row and marker go. The earlier row went with `Security::login()`, so the earlier
 *   account registers again on its next request.
 * - Switcher: only the failed account's entry goes from this browser, if its sign-in was complete (a pending one never
 *   enrolled, so an entry of that account is an older, healthy one). The account that was signed in keeps its entry.
 * - The session id changes once more, so nothing keyed by the id of the failed sign-in resolves to this session.
 * - A session UserKit already reports revoked or expired is not brought back ({@see self::canRestore()}).
 *
 * Stateless (worker safe). What listeners did outside the session on `LoginSuccessEvent` (mail, audit rows) is not undone.
 */
final readonly class SocialHandlerSignInRollback
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private ProfileRegistry $profiles,
        private ?UserSessionManager $userSessions = null,
        private ?AccountVault $vault = null,
        private ?Security $security = null,
    ) {
    }

    /**
     * Runs a sign-in. If it throws after the token changed (a handler, a `LoginSuccessEvent` listener, a commit), the
     * sign-in is undone and the exception goes on. A token that became null is a deliberate sign-out: nothing to undo.
     *
     * @template T
     *
     * @param callable(): T $signIn
     *
     * @return T
     */
    public function guard(Request $request, callable $signIn): mixed
    {
        $before     = $this->tokens->getToken();
        $snapshot   = $this->snapshot($request);
        $restorable = $this->canRestore($request, $before);

        try {
            return $signIn();
        } catch (Throwable $exception) {
            $token = $this->tokens->getToken();
            if ($token instanceof TokenInterface && $token !== $before) {
                $this->undo($request, $restorable ? $before : null, $token, $snapshot);
            }

            throw $exception;
        }
    }

    /**
     * The session attributes before the handler ran.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Request $request): array
    {
        return $request->hasSession() ? $request->getSession()->all() : [];
    }

    /**
     * @param array<string, mixed> $snapshot from {@see self::snapshot()}
     */
    public function restore(Request $request, TokenInterface $before, TokenInterface $failed, array $snapshot): void
    {
        // First: whatever fails below, the failed account is no longer the one signed in (GY-L8). Session work next, the
        // database last, so an outage leaves at most a row without a session behind.
        $this->tokens->setToken($before);
        $previousId = null;
        if ($request->hasSession()) {
            $session    = $request->getSession();
            $previousId = $session->getId();
            $this->forgetMarker($session, $failed);
            $this->restoreAttributes($session, $snapshot);
            // @igor-ignore - The request's own session (not an injected service).
            $session->migrate(true);
            $this->userSessions?->rekey($session, $previousId);
        }
        $user = $failed->getUser();
        if ($previousId !== null && $user instanceof UserInterface) {
            $this->userSessions?->endSession($previousId, $user);
        }
        $this->forgetSwitcherEntry($request, $before, $failed);
    }

    /**
     * False when UserKit already reports the earlier session revoked or expired, for instance signed out everywhere while
     * the provider was answering (GY-L7): it is not brought back, the visitor ends anonymous. Asked before the handler
     * runs, because `Security::login()` deletes the row. A revocation after this point is missed until the next stateful
     * request: see docs/SECURITY.md.
     */
    public function canRestore(Request $request, ?TokenInterface $before): bool
    {
        if (!$before instanceof TokenInterface) {
            return false;
        }
        $user = $before->getUser();
        if (!$this->userSessions instanceof UserSessionManager || !$user instanceof UserInterface || !$request->hasSession() || $this->userSessions->markers($request) === []) {
            return true;
        }
        $status = $this->userSessions->sessionState($request->getSession()->getId(), $user)->status;

        return $status !== SessionStatus::Revoked && $status !== SessionStatus::Expired;
    }

    /**
     * Its remember-me cookie never leaves (it waits on the request), an earlier session goes on (GY-L6), and a visitor
     * who was anonymous stays anonymous, with the other accounts of the browser still in the switcher.
     *
     * @param array<string, mixed> $snapshot
     */
    private function undo(Request $request, ?TokenInterface $before, TokenInterface $failed, array $snapshot): void
    {
        $request->attributes->remove(RememberMeResponseListener::COOKIE_ATTR_NAME);
        if ($before instanceof TokenInterface) {
            $this->restore($request, $before, $failed, $snapshot);

            return;
        }
        $request->attributes->set(MultiAccountSubscriber::SCOPE_ATTRIBUTE, 'current');

        try {
            $this->security?->logout(false);
        } finally {
            $this->forceAnonymous($request);
        }
    }

    /**
     * The anonymous path: whatever the firewall's logout listeners did or failed to do, nobody stays signed in (GY-L8).
     */
    public function forceAnonymous(Request $request): void
    {
        $this->tokens->setToken(null);
        if ($request->hasSession()) {
            // @igor-ignore - The request's own session (not an injected service).
            $request->getSession()->invalidate();
        }
    }

    private function forgetSwitcherEntry(Request $request, TokenInterface $before, TokenInterface $failed): void
    {
        $user = $failed->getUser();
        if (!$this->vault instanceof AccountVault || !$user instanceof UserInterface || $failed instanceof MfaPendingToken) {
            return;
        }
        $earlier = $before->getUser();
        if ($earlier instanceof UserInterface && $earlier::class === $user::class && $earlier->getUserIdentifier() === $user->getUserIdentifier()) {
            return;
        }
        $profile = $this->profiles->resolveForObject($user);
        if ($profile instanceof ProfileSettings && $profile->multiAccountEnabled()) {
            $this->vault->forgetInBrowser($request, $profile, $user->getUserIdentifier());
        }
    }

    private function forgetMarker(SessionInterface $session, TokenInterface $failed): void
    {
        $user = $failed->getUser();
        if (!$this->userSessions instanceof UserSessionManager || !$user instanceof UserInterface) {
            return;
        }
        $owner   = $this->userSessions->owner($user)?->key();
        $markers = $this->userSessions->markers($session);
        foreach ($markers as $context => $marker) {
            if ($marker['u'] === $owner) {
                unset($markers[$context]);
            }
        }
        // @igor-ignore - The request's own session (not an injected service).
        $session->set(UserSessionManager::ATTRIBUTE, $markers);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function restoreAttributes(SessionInterface $session, array $snapshot): void
    {
        foreach ($session->all() as $name => $value) {
            if ($this->userSessions instanceof UserSessionManager && $name === UserSessionManager::ATTRIBUTE) {
                continue;
            }
            if (!array_key_exists($name, $snapshot)) {
                // @igor-ignore - The request's own session (not an injected service).
                $session->remove($name);
            } elseif ($value !== $snapshot[$name]) {
                // @igor-ignore - The request's own session (not an injected service).
                $session->set($name, $snapshot[$name]);
            }
        }
    }
}
