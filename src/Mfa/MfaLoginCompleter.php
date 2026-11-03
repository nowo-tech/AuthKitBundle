<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaLoginCompletedEvent;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

/**
 * Ends a pending sign-in (1.34): {@see self::complete()} restores the first factor's token, migrates the session id
 * (fixation), issues the remember-me cookie the user asked for at the first factor, dispatches the audit events and
 * redirects to the original target; {@see self::abort()} signs the browser out (expired or too many wrong codes).
 */
final readonly class MfaLoginCompleter
{
    /**
     * @param ContainerInterface $rememberMeHandlers the firewalls' remember-me handlers, by firewall name
     */
    public function __construct(
        private TokenStorageInterface $tokens,
        private MfaManager $manager,
        private AuthKitUrlGenerator $urls,
        private AuthKitFlashMessenger $flash,
        private ContainerInterface $rememberMeHandlers,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function complete(Request $request, MfaPendingToken $pending, string $method): RedirectResponse
    {
        $token = $pending->inner();
        $user  = $token->getUser();
        $this->tokens->setToken($token);
        if ($request->hasSession()) {
            $request->getSession()->migrate(true);
        }
        $profile = $this->manager->profileNamed($pending->profile());
        if ($user instanceof UserInterface) {
            if ($pending->wantsRememberMe() && $this->rememberMeHandlers->has($pending->firewall())) {
                $handler = $this->rememberMeHandlers->get($pending->firewall());
                if ($handler instanceof RememberMeHandlerInterface) {
                    $handler->createRememberMeCookie($user);
                }
            }
            $this->dispatcher?->dispatch(new MfaChallengePassedEvent($profile->name, $user, $method, $pending->firstFactor()));
            $this->dispatcher?->dispatch(new MfaLoginCompletedEvent($profile->name, $user, $token, $request, $pending->firstFactor()));
        }

        return new RedirectResponse($pending->targetPath() ?? $this->afterLogin($profile), Response::HTTP_SEE_OTHER);
    }

    /**
     * Signs the pending browser out and sends it back to the sign-in page with a flash.
     */
    public function abort(Request $request, MfaPendingToken $pending, string $flashKey): RedirectResponse
    {
        $this->tokens->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }
        $this->flash->flash($request, 'error', $flashKey);
        $profile = $this->manager->profileNamed($pending->profile());

        return new RedirectResponse($this->urls->generate($profile->routes['login']['name']), Response::HTTP_SEE_OTHER);
    }

    public function afterLogin(ProfileSettings $profile): string
    {
        return $profile->loginSuccessRoute !== null ? $this->urls->generate($profile->loginSuccessRoute) : '/';
    }
}
