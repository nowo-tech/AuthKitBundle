<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\EventSubscriber;

use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer;
use Nowo\AuthKitBundle\Mfa\Event\MfaLoginCompletedEvent;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\MultiAccount\VaultCookie;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsDeletedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function in_array;
use function is_array;
use function is_string;
use function time;

/**
 * Glue of the account switcher (1.34):
 *
 * - `LoginSuccessEvent` (firewall dispatchers, priority -512, after UserKit) and `MfaLoginCompletedEvent`: a complete
 *   sign-in joins or re-binds the browser's switcher ({@see AccountVault::enrol()});
 * - `LogoutEvent` (firewall dispatchers, priority 32: after the default target, before the session is invalidated):
 *   `logout_scope: all` ends every account of the browser; `current` ends the active one and shows the chooser when
 *   others remain (request attribute {@see self::SCOPE_ATTRIBUTE} overrides the scope);
 * - `kernel.response`: writes the queued vault cookie and the remember-me cookie of a switch;
 * - UserKit: signed out everywhere / by an administrator / disabled / password changed, or removed → the user leaves
 *   every browser's switcher.
 */
final readonly class MultiAccountSubscriber
{
    public const SCOPE_ATTRIBUTE = '_nowo_auth_kit_accounts_logout_scope';

    public function __construct(
        private AccountVault $vault,
        private ProfileRegistry $profiles,
        private AuthKitUrlGenerator $urls,
        private ?CacheItemPoolInterface $issuedLinks = null,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $token = $event->getAuthenticatedToken();
        if (!$token instanceof MfaPendingToken) {
            $this->enrol($event->getRequest(), $token);
        }
    }

    public function onMfaCompleted(MfaLoginCompletedEvent $event): void
    {
        $this->enrol($event->request, $event->token);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $user    = $event->getToken()?->getUser();
        $profile = $user instanceof UserInterface ? $this->profileOf($user) : null;
        if (!$profile instanceof ProfileSettings || !$request->hasSession()) {
            return;
        }
        $scope = $request->attributes->get(self::SCOPE_ATTRIBUTE, $profile->multiAccount['logout_scope'] ?? 'all');
        if ($scope === 'all') {
            $this->vault->endAll($request, $profile, AccountRemovalReason::Logout);

            return;
        }
        if ($this->vault->forgetActive($request, $profile)) {
            $event->setResponse(new RedirectResponse($this->urls->generate((string) $profile->multiAccount['routes']['chooser']['name'])));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request  = $event->getRequest();
        $response = $event->getResponse();
        VaultCookie::write($request, $response, $this->profiles->all());
        $rm     = $request->attributes->get(AccountVault::REMEMBER_ME_ATTRIBUTE);
        $cookie = is_array($rm) && is_string($rm['firewall'] ?? null) ? $this->vault->rememberMeCookie($rm['firewall']) : null;
        if (!is_array($rm) || $cookie === null) {
            return;
        }
        $secure = $cookie['secure'] === null || $cookie['secure'] === 'auto' ? $request->isSecure() : (bool) $cookie['secure'];
        $value  = $rm['value'] ?? null;
        if (!is_string($value)) {
            $response->headers->clearCookie($cookie['name'], $cookie['path'], $cookie['domain'], $secure, true, $cookie['samesite']);

            return;
        }
        /** @var 'lax'|'none'|'strict'|null $sameSite the firewall's remember-me option (validated by SecurityBundle) */
        $sameSite = $cookie['samesite'];
        $response->headers->setCookie(Cookie::create($cookie['name'], $value, time() + $cookie['lifetime'], $cookie['path'], $cookie['domain'], $secure, true, false, $sameSite));
    }

    public function onSessionsRevoked(UserSessionsRevokedEvent $event): void
    {
        if ($event->user instanceof UserInterface && in_array($event->reason->value, ['everywhere', 'admin', 'account_disabled', 'password_changed'], true)) {
            $this->vault->forgetUser($event->user->getUserIdentifier());
        }
    }

    public function onSessionsDeleted(UserSessionsDeletedEvent $event): void
    {
        if ($event->user instanceof UserInterface) {
            $this->vault->forgetUser($event->user->getUserIdentifier());
        }
    }

    private function enrol(Request $request, TokenInterface $token): void
    {
        $user    = $token->getUser();
        $profile = $user instanceof UserInterface ? $this->profileOf($user) : null;
        if ($profile instanceof ProfileSettings) {
            $admin = $this->issuedLinks instanceof CacheItemPoolInterface && AdminMagicLinkIssuer::isAdminIssued($this->issuedLinks, $request);
            $this->vault->enrol($request, $profile, $token, $admin);
        }
    }

    private function profileOf(UserInterface $user): ?ProfileSettings
    {
        $profile = $this->profiles->resolveForObject($user);

        return $profile?->multiAccountEnabled() === true ? $profile : null;
    }
}
