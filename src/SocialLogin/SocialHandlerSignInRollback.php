<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;

/**
 * A social callback handler signed someone in and then failed, over a session that existed before the callback (GY-L6,
 * 1.37): that session goes on, and nothing of the failed sign-in stays reachable from it.
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
    ) {
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
        $this->forgetSwitcherEntry($request, $before, $failed);
        $this->tokens->setToken($before);
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $this->forgetSessionRow($session, $failed);
        $this->restoreAttributes($session, $snapshot);
        $previousId = $session->getId();
        // @igor-ignore - The request's own session (not an injected service).
        $session->migrate(true);
        $this->userSessions?->rekey($session, $previousId);
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

    private function forgetSessionRow(SessionInterface $session, TokenInterface $failed): void
    {
        $user = $failed->getUser();
        if (!$this->userSessions instanceof UserSessionManager || !$user instanceof UserInterface) {
            return;
        }
        $this->userSessions->endSession($session->getId(), $user);
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
