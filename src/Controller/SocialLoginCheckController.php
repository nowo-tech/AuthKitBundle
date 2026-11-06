<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Completes the OAuth authorization-code callback and logs the user in. A host
 * {@see SocialCallbackHandlerInterface} can own the last step (1.37): every check below still runs first.
 */
final class SocialLoginCheckController
{
    /**
     * @param iterable<SocialCallbackHandlerInterface> $callbackHandlers
     */
    public function __construct(
        private readonly SocialLoginGate $socialLoginGate,
        private readonly SocialLoginCredentialRepository $credentials,
        private readonly OAuth2Client $oauth2Client,
        private readonly SocialLoginStateStore $stateStore,
        private readonly SocialAccountLinker $accountLinker,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly Security $security,
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly iterable $callbackHandlers = [],
    ) {
    }

    #[Route(path: '/login/social/{provider}/check', name: 'nowo_auth_kit_social_login_check', requirements: ['provider' => '[a-z0-9_\\-]+'], methods: ['GET'])]
    public function check(Request $request, string $provider): Response
    {
        $profile  = $this->profileResolver->resolve($request);
        $loginUrl = $this->urlGenerator->generate($profile->routes['login']['name']);
        $handler  = $this->handlerFor($request, $provider);

        if (!$this->socialLoginGate->isEnabled($profile->name)) {
            return $this->failed($request, $provider, $handler, SocialCallbackFailure::Unavailable, $loginUrl);
        }

        $credential = $this->credentials->findOneByProvider($provider);
        if (!$credential instanceof SocialLoginCredential || !$credential->isEnabled()) {
            return $this->failed($request, $provider, $handler, SocialCallbackFailure::Unavailable, $loginUrl);
        }

        $state = (string) $request->query->get('state', '');
        $code  = (string) $request->query->get('code', '');

        // A denied/failed authorization comes back with `error` and no `code` (RFC 6749 §4.1.2.1):
        // handle it before the code/state check so users see the provider_denied message.
        if ($request->query->has('error')) {
            if ($state !== '') {
                // Burn the pending state so it cannot be replayed with a code later.
                $this->stateStore->consume($provider, $state);
            }

            return $this->failed($request, $provider, $handler, SocialCallbackFailure::ProviderDenied, $loginUrl, 'social.error.provider_denied');
        }

        $pending = $code !== '' && $state !== '' ? $this->stateStore->consumePending($provider, $state) : null;
        if ($pending === null) {
            return $this->failed($request, $provider, $handler, SocialCallbackFailure::InvalidState, $loginUrl, 'social.error.invalid_state');
        }

        try {
            $redirectUri = $this->urlGenerator->generate(
                $profile->routes['social_login_check']['name'],
                ['provider' => $provider],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $tokens = $this->oauth2Client->exchangeCode($credential, $code, $redirectUri, $pending['code_verifier']);
            $social = $this->oauth2Client->fetchUserProfile($credential, $tokens['access_token']);
            if ($handler instanceof SocialCallbackHandlerInterface) {
                MfaFirstFactor::mark($request, 'social');

                return $handler->handle($request, new SocialCallbackContext($profile, $provider, $social, $tokens));
            }
            $user = $this->accountLinker->linkOrCreate($profile, $provider, $social, $tokens);
            MfaFirstFactor::mark($request, 'social');
            $this->security->login($user, null, $profile->firewall);
        } catch (RuntimeException) {
            return $this->failed($request, $provider, $handler, SocialCallbackFailure::Failed, $loginUrl, 'social.error.failed');
        }

        $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

        return new RedirectResponse($this->urlGenerator->generate($target));
    }

    private function handlerFor(Request $request, string $provider): ?SocialCallbackHandlerInterface
    {
        foreach ($this->callbackHandlers as $handler) {
            if ($handler->supports($request, $provider)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * The handler's answer to a failed callback; otherwise (no handler, or it returned null) the flash and the redirect.
     */
    private function failed(Request $request, string $provider, ?SocialCallbackHandlerInterface $handler, SocialCallbackFailure $reason, string $loginUrl, ?string $flash = null): Response
    {
        $response = $handler?->handleFailure($request, $provider, $reason);
        if ($response instanceof Response) {
            return $response;
        }
        if ($flash !== null) {
            $this->flashMessenger->flash($request, 'error', $flash);
        }

        return new RedirectResponse($loginUrl);
    }
}
