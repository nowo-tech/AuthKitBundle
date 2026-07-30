<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Completes the OAuth authorization-code callback and logs the user in.
 */
final class SocialLoginCheckController
{
    public function __construct(
        private readonly SocialLoginGate $socialLoginGate,
        private readonly SocialLoginCredentialRepository $credentials,
        private readonly OAuth2Client $oauth2Client,
        private readonly SocialLoginStateStore $stateStore,
        private readonly SocialAccountLinker $accountLinker,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly Security $security,
    ) {
    }

    public function check(Request $request, string $provider): RedirectResponse
    {
        $profile  = $this->profileResolver->resolve($request);
        $loginUrl = $this->urlGenerator->generate($profile->routes['login']['name']);

        if (!$this->socialLoginGate->isEnabled($profile->name)) {
            return new RedirectResponse($loginUrl);
        }

        $credential = $this->credentials->findOneByProvider($provider);
        if (!$credential instanceof SocialLoginCredential || !$credential->isEnabled()) {
            return new RedirectResponse($loginUrl);
        }

        $state = (string) $request->query->get('state', '');
        $code  = (string) $request->query->get('code', '');
        if ($code === '' || $state === '' || !$this->stateStore->consume($provider, $state)) {
            $this->addFlash($request, 'error', 'social.error.invalid_state');

            return new RedirectResponse($loginUrl);
        }

        if ($request->query->has('error')) {
            $this->addFlash($request, 'error', 'social.error.provider_denied');

            return new RedirectResponse($loginUrl);
        }

        try {
            $redirectUri = $this->urlGenerator->generate(
                $profile->routes['social_login_check']['name'],
                ['provider' => $provider],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $tokens = $this->oauth2Client->exchangeCode($credential, $code, $redirectUri);
            $social = $this->oauth2Client->fetchUserProfile($credential, $tokens['access_token']);
            $user   = $this->accountLinker->linkOrCreate($profile, $provider, $social, $tokens);
            $this->security->login($user, null, $profile->firewall);
        } catch (RuntimeException) {
            $this->addFlash($request, 'error', 'social.error.failed');

            return new RedirectResponse($loginUrl);
        }

        $target = $profile->loginSuccessRoute ?? 'homepage';

        return new RedirectResponse($this->urlGenerator->generate($target));
    }

    private function addFlash(Request $request, string $type, string $message): void
    {
        if ($request->hasSession() && $request->getSession() instanceof FlashBagAwareSessionInterface) {
            $request->getSession()->getFlashBag()->add($type, $message);
        }
    }
}
