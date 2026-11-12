<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use LogicException;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

use function sprintf;

/**
 * Asks a host {@see SocialCallbackStepInterface} (1.46.0) where the social callback asks the deprecated
 * {@see SocialCallbackHandlerInterface}: the request becomes an {@see AuthRequestContext}
 * and a {@see SocialCallbackResult} becomes the sign-in (`Security::login()` on the profile's firewall with the
 * authenticator AuthKit's own social sign-in uses) and the redirect. Built per call by the controller; the controller's
 * guards (state checks before, sign-in rollback and failure path after) are unchanged.
 *
 * @internal
 */
final readonly class SocialCallbackStepGate implements SocialCallbackHandlerInterface
{
    public function __construct(
        private SocialCallbackStepInterface $step,
        private ProfileSettings $profile,
        private Security $security,
        private ProgrammaticLoginAuthenticator $loginAuthenticator,
    ) {
    }

    public function step(): SocialCallbackStepInterface
    {
        return $this->step;
    }

    public function supports(Request $request, string $provider): bool
    {
        return $this->step->supports($this->context($request), $provider) === true;
    }

    public function handle(Request $request, SocialCallbackContext $context): Response
    {
        return $this->respond($this->step->handle($this->context($request), $context));
    }

    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response
    {
        $result = $this->step->handleFailure($this->context($request), $provider, $reason);

        return $result instanceof SocialCallbackResult ? $this->respond($result) : null;
    }

    /**
     * @throws LogicException when a sign-in is asked and the firewall cannot sign anyone in, or the reference carries
     *                        no security user
     */
    private function respond(SocialCallbackResult $result): Response
    {
        if ($result->signIn !== null) {
            $firewall = $this->profile->firewall;
            if (!$this->loginAuthenticator->canSignIn($firewall)) {
                throw new LogicException(sprintf('The firewall "%s" has no authenticator AuthKit can sign a social user in with.', $firewall));
            }
            $user     = AuthUserRefs::securityUser($result->signIn, SocialCallbackStepInterface::class . '::handle()');
            $badges   = $result->rememberMe ? [(new RememberMeBadge())->enable()] : [];
            $response = $this->security->login($user, $this->loginAuthenticator->nameFor($firewall), $firewall, $badges);
            if ($response instanceof Response) {
                return $response;
            }
        }

        return new RedirectResponse($result->redirectUrl);
    }

    private function context(Request $request): AuthRequestContext
    {
        return AuthRequestContexts::fromRequest($request, $this->profile->firewall, $this->profile->name);
    }
}
