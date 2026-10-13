<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Pkce;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Starts the OAuth authorization-code redirect for a social provider.
 */
final class SocialLoginStartController
{
    public function __construct(
        private readonly SocialLoginGate $socialLoginGate,
        private readonly SocialLoginCredentialRepository $credentials,
        private readonly OAuth2Client $oauth2Client,
        private readonly SocialLoginStateStore $stateStore,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function start(Request $request, string $provider): RedirectResponse
    {
        $profile = $this->profileResolver->resolve($request);
        if (!$this->socialLoginGate->isEnabled($profile->name)) {
            return new RedirectResponse($this->urlGenerator->generate($profile->routes['login']['name']));
        }

        $credential = $this->credentials->findOneByProvider($provider);
        if (!$credential instanceof SocialLoginCredential || !$credential->isEnabled()) {
            return new RedirectResponse($this->urlGenerator->generate($profile->routes['login']['name']));
        }

        $redirectUri = $this->urlGenerator->generate(
            $profile->routes['social_login_check']['name'],
            ['provider' => $provider],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        /** @var array<string, bool> $pkceOverrides */
        $pkceOverrides = $profile->socialLogin['pkce'] ?? [];
        $codeVerifier  = $this->oauth2Client->usesPkce($credential, $pkceOverrides) ? Pkce::generateVerifier() : null;
        $state         = $this->stateStore->issue($provider, $codeVerifier);
        $url           = $this->oauth2Client->buildAuthorizeUrl($credential, $redirectUri, $state, $codeVerifier);

        return new RedirectResponse($url);
    }
}
