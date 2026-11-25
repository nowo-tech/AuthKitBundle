<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcClient;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Pkce;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_int;

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
        private readonly ?OidcClient $oidcClient = null,
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AuthKitAttemptLimiter $attemptLimiter = null,
    ) {
    }

    #[Route(path: '/login/social/{provider}', name: 'nowo_auth_kit_social_login_start', requirements: ['provider' => '[a-z0-9_\\-]+'], methods: ['GET'])]
    public function start(Request $request, string $provider): RedirectResponse
    {
        $profile = $this->profileResolver->resolve($request);
        if (!$this->socialLoginGate->isEnabled($profile->name)) {
            return new RedirectResponse($this->urlGenerator->generate($profile->routes['login']['name']));
        }

        // 2.3.0 (audit ZBP-L3): starts are bounded per client IP (an OpenID Connect start fetches the discovery document).
        if ($this->attemptLimiter instanceof AuthKitAttemptLimiter && !$this->attemptLimiter->consume(
            'social_start:' . $profile->name . ':' . ($request->getClientIp() ?? 'unknown'),
            $this->intSetting($profile->socialLogin['start_rate_limit'] ?? null, 20),
            $this->intSetting($profile->socialLogin['start_rate_window'] ?? null, 60),
        )) {
            $this->flashMessenger->flash($request, 'error', 'social.error.rate_limited');

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
        /** @var array<string, mixed> $authorizationParams */
        $authorizationParams = $profile->socialLogin['authorization_params'] ?? [];
        // 2.3.0: OpenID Connect providers (generic issuer, GitLab, Apple, LinkedIn, Slack) start through discovery.
        if ($this->oidcClient instanceof OidcClient && $this->oidcClient->supports($credential)) {
            try {
                return new RedirectResponse($this->oidcClient->begin($credential, $provider, $redirectUri, $this->stateStore, $pkceOverrides, $authorizationParams)['url']);
            } catch (OidcException $exception) {
                // Logged by class and reason only: the message never carries a secret, but the reason is enough.
                $this->logger?->warning('AuthKit social sign-in could not start for provider {provider} ({reason}).', ['provider' => $provider, 'reason' => $exception->reason, 'exception' => $exception::class]);
                $this->flashMessenger->flash($request, 'error', 'social.error.failed');

                return new RedirectResponse($this->urlGenerator->generate($profile->routes['login']['name']));
            }
        }
        $codeVerifier = $this->oauth2Client->usesPkce($credential, $pkceOverrides) ? Pkce::generateVerifier() : null;
        $state        = $this->stateStore->issue($provider, $codeVerifier);
        $url          = $this->oauth2Client->buildAuthorizeUrl($credential, $redirectUri, $state, $codeVerifier, $authorizationParams);

        return new RedirectResponse($url);
    }

    private function intSetting(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }
}
