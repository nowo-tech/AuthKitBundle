<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\EventSubscriber;

use Nowo\AuthKitBundle\DependencyInjection\MfaConfiguration;
use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

use function is_string;
use function parse_url;
use function str_starts_with;

use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * The two-step sign-in of one firewall (1.34), registered on that firewall's event dispatcher:
 *
 * - `CheckPassportEvent`: remembers which firewall authenticator is the first factor (unless an AuthKit controller
 *   named it already);
 * - `AuthenticationTokenCreatedEvent` (every authenticator, `Security::login()` included, before the token is stored):
 *   when the user must enter a code (MFA on, factor not exempt, browser not trusted) or must enrol now, the token is
 *   replaced by a role-less {@see MfaPendingToken};
 * - `LoginSuccessEvent` priority -48 (after `CheckRememberMeConditionsListener`, before `RememberMeListener`): a
 *   pending sign-in gets no remember-me cookie (the wish is kept for the completion), its target path is kept and the
 *   response goes to the challenge (or enrolment) page.
 */
final readonly class MfaAuthenticationSubscriber
{
    public function __construct(
        private string $firewall,
        private MfaManager $manager,
        private MfaTrustedDevices $devices,
        private RequestStack $requests,
        private AuthKitUrlGenerator $urls,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requests->getMainRequest();
        $factor  = MfaFirstFactor::fromAuthenticator($event->getAuthenticator());
        if ($request instanceof Request && $factor !== null && !$request->attributes->has(MfaFirstFactor::REQUEST_ATTRIBUTE)) {
            MfaFirstFactor::mark($request, $factor);
        }
    }

    public function onTokenCreated(AuthenticationTokenCreatedEvent $event): void
    {
        $token   = $event->getAuthenticatedToken();
        $user    = $token->getUser();
        $profile = $user instanceof UserInterface ? $this->manager->profileFor($user) : null;
        if (!$user instanceof UserInterface || !$profile instanceof ProfileSettings || $profile->firewall !== $this->firewall || $token instanceof MfaPendingToken) {
            return;
        }
        $request = $this->requests->getMainRequest();
        $factor  = $token instanceof RememberMeToken ? MfaFirstFactor::REMEMBER_ME : MfaFirstFactor::of($request);
        $mode    = $this->mode($profile, $user, $factor, $request);
        if ($mode !== null) {
            $event->setAuthenticatedToken(new MfaPendingToken($token, $this->firewall, $profile->name, $factor, $mode, $this->manager->now()->getTimestamp()));
        }
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $token = $event->getAuthenticatedToken();
        if (!$token instanceof MfaPendingToken) {
            return;
        }
        $badge = $event->getPassport()->getBadge(RememberMeBadge::class);
        if ($badge instanceof RememberMeBadge && $badge->isEnabled()) {
            $token->requestRememberMe();
            $badge->disable();
        }
        $token->setTargetPath(self::localTarget($event->getResponse()?->headers->get('Location'), $event->getRequest()));
        $profile = $this->manager->profileNamed($token->profile());
        $event->setResponse(new RedirectResponse($this->urls->generate((string) $profile->mfa['routes'][$token->mode()]['name'])));
    }

    /**
     * Same-origin path (and query) of a redirect target, else null.
     */
    public static function localTarget(?string $location, Request $request): ?string
    {
        if (!is_string($location) || $location === '') {
            return null;
        }
        $host = parse_url($location, PHP_URL_HOST);
        if (is_string($host) && $host !== $request->getHost()) {
            return null;
        }
        $path  = parse_url($location, PHP_URL_PATH);
        $query = parse_url($location, PHP_URL_QUERY);
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    private function mode(ProfileSettings $profile, UserInterface $user, string $factor, ?Request $request): ?string
    {
        if (!$this->manager->isEnabled($user)) {
            return $this->manager->mustEnrolNow($user) ? MfaPendingToken::MODE_SETUP : null;
        }
        if ($factor === MfaFirstFactor::REMEMBER_ME) {
            if (($profile->mfa['remember_me'] ?? MfaConfiguration::RULE_TRUSTED) === MfaConfiguration::RULE_TRUSTED) {
                return null;
            }
        } else {
            $rules = $profile->mfa['first_factors'] ?? [];
            $rule  = $rules[$factor] ?? $rules[MfaFirstFactor::DEFAULT] ?? MfaConfiguration::RULE_REQUIRED;
            if ($rule === MfaConfiguration::RULE_EXEMPT) {
                return null;
            }
        }
        if ($request instanceof Request && $this->devices->isTrusted($request, $user)) {
            $this->dispatcher?->dispatch(new MfaChallengePassedEvent($profile->name, $user, 'trusted_device', $factor));

            return null;
        }

        return MfaPendingToken::MODE_CHALLENGE;
    }
}
