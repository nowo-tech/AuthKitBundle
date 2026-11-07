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
use Symfony\Component\HttpFoundation\RequestStack;
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

    /** Session flag: the first factor of a pending MFA sign-in came from an admin-issued magic link (D8, DE-L5). */
    public const ADMIN_LINK_ATTRIBUTE = '_nowo_auth_kit_accounts_admin_link';

    /** Request attribute: the accounts this request's sign-in enrolled, `profile|identifier` (HU-L1). */
    public const ENROLLED_ATTRIBUTE = '_nowo_auth_kit_accounts_enrolled';

    /** Revoke-context key of UserKit's sessions eraser (`SessionsEraser::CONTEXT_ORIGINAL_IDENTIFIER`, UserKit ≥ 1.12). */
    public const CONTEXT_ORIGINAL_IDENTIFIER = 'original_identifier';

    /** UserKit revocations that sign the user out of every session: the user leaves every browser's switcher. */
    private const FORGET_REASONS = ['everywhere', 'admin', 'account_disabled', 'account_changed', 'password_changed'];

    public function __construct(
        private AccountVault $vault,
        private ProfileRegistry $profiles,
        private AuthKitUrlGenerator $urls,
        private ?CacheItemPoolInterface $issuedLinks = null,
        private ?RequestStack $requests = null,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $token   = $event->getAuthenticatedToken();
        $request = $event->getRequest();
        if (!$token instanceof MfaPendingToken) {
            $this->enrol($request, $token);

            return;
        }
        // The second factor completes on another request, without the link: remember where the first one came from.
        if ($request->hasSession() && $this->adminIssued($request)) {
            $request->getSession()->set(self::ADMIN_LINK_ATTRIBUTE, true);
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

    /**
     * Sign-outs of every session remove the user from every browser at once; a revoked single session leaves its
     * browser's switcher on the next list or switch (UserKit ≥ 1.11.1 reports it revoked, never revived).
     */
    public function onSessionsRevoked(UserSessionsRevokedEvent $event): void
    {
        $user    = $event->user;
        $profile = $user instanceof UserInterface ? $this->profileOf($user) : null;
        if ($user instanceof UserInterface && $profile instanceof ProfileSettings && in_array($event->reason->value, self::FORGET_REASONS, true)) {
            foreach ($this->identifiers($user, $event->context) as $identifier) {
                $request = $event->reason->value === 'password_changed' ? $this->requestThatEnrolled($profile, $identifier) : null;
                if ($request instanceof Request) {
                    // HU-L1: the first link replaced the password and UserKit reports it after the commit, so after this
                    // sign-in joined the switcher. Its other browsers go; the entry it just made stays.
                    $this->vault->forgetElsewhere($request, $profile, $identifier);

                    continue;
                }
                $this->vault->forgetUser($profile, $identifier);
            }
        }
    }

    public function onSessionsDeleted(UserSessionsDeletedEvent $event): void
    {
        $user    = $event->user;
        $profile = $user instanceof UserInterface ? $this->profileOf($user) : null;
        if ($user instanceof UserInterface && $profile instanceof ProfileSettings) {
            $this->vault->forgetUser($profile, $user->getUserIdentifier());
        }
    }

    private function enrol(Request $request, TokenInterface $token): void
    {
        $user    = $token->getUser();
        $profile = $user instanceof UserInterface ? $this->profileOf($user) : null;
        $flagged = false;
        if ($request->hasSession() && $request->getSession()->has(self::ADMIN_LINK_ATTRIBUTE)) {
            $flagged = $request->getSession()->remove(self::ADMIN_LINK_ATTRIBUTE) === true;
        }
        if ($profile instanceof ProfileSettings) {
            $this->vault->enrol($request, $profile, $token, $flagged || $this->adminIssued($request));
            $enrolled                                                     = $request->attributes->get(self::ENROLLED_ATTRIBUTE);
            $enrolled                                                     = is_array($enrolled) ? $enrolled : [];
            $enrolled[$profile->name . '|' . $token->getUserIdentifier()] = true;
            $request->attributes->set(self::ENROLLED_ATTRIBUTE, $enrolled);
        }
    }

    private function requestThatEnrolled(ProfileSettings $profile, string $identifier): ?Request
    {
        $request  = $this->requests?->getMainRequest();
        $enrolled = $request?->attributes->get(self::ENROLLED_ATTRIBUTE);

        return is_array($enrolled) && isset($enrolled[$profile->name . '|' . $identifier]) ? $request : null;
    }

    private function adminIssued(Request $request): bool
    {
        return $this->issuedLinks instanceof CacheItemPoolInterface && AdminMagicLinkIssuer::isAdminIssued($this->issuedLinks, $request);
    }

    /**
     * The user's current identifier and, during a UserKit erasure (≥ 1.12), the one captured before an identity eraser
     * anonymised it (`context['original_identifier']`, DATA-AUTHKIT-VAULT-ERASE).
     *
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    private function identifiers(UserInterface $user, array $context): array
    {
        $original = $context[self::CONTEXT_ORIGINAL_IDENTIFIER] ?? null;
        $current  = $user->getUserIdentifier();

        return is_string($original) && $original !== '' && $original !== $current ? [$original, $current] : [$current];
    }

    private function profileOf(UserInterface $user): ?ProfileSettings
    {
        $profile = $this->profiles->resolveForObject($user);

        return $profile?->multiAccountEnabled() === true ? $profile : null;
    }
}
