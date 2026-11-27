<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Controller\SocialLoginCheckController;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\SocialLoginMode;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcClient;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;

/**
 * Mints a *test sign-in* (2.7.0): the authorization URL of a provider credential whose OAuth state carries a one-shot
 * test marker, kept server-side in the session with the state (and the PKCE verifier and nonce). The callback of that
 * state ({@see SocialLoginCheckController}) runs every check of a real sign-in, then
 * only the host's {@see SocialLoginTestHandlerInterface}: it never signs in, links or provisions an account.
 *
 * There is no URL flag: a test exists only when the host calls this service. Call it from an action only
 * administrators reach (and a POST with a CSRF token: a GET would let another site start a test in their browser).
 * The credential may be disabled (test before you enable); it must exist and the profile's `social_login.mode` must be
 * `enabled`. Starting a test replaces a sign-in pending in the same session, as any start does.
 *
 * Stateless (worker mode, REQ-RUNTIME-003): everything per call lives in the session.
 */
final class SocialLoginTestStart
{
    /**
     * @param iterable<mixed> $testHandlers tagged {@see SocialLoginTestHandlerInterface::TAG}
     */
    public function __construct(
        private readonly RequestProfileResolver $profileResolver,
        private readonly SocialLoginCredentialRepository $credentials,
        private readonly OAuth2Client $oauth2Client,
        private readonly SocialLoginStateStore $stateStore,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly ?OidcClient $oidcClient = null,
        private readonly iterable $testHandlers = [],
    ) {
    }

    /**
     * The URL to send the administrator's browser to (redirect to it).
     *
     * @param Request $request the current request (its session holds the marker; its profile builds the redirect URI)
     * @param string|null $loginHint an e-mail address forwarded as `login_hint` when valid ({@see LoginHint})
     *
     * @throws SocialLoginTestException when no test handler is registered, social login is off, the provider is unknown
     *                                  or cannot be reached
     */
    public function start(Request $request, string $provider, ?string $loginHint = null): string
    {
        if (!$this->hasHandler()) {
            throw new SocialLoginTestException(SocialLoginTestException::REASON_NO_HANDLER, sprintf('No "%s" is registered: a test sign-in would have no handler.', SocialLoginTestHandlerInterface::class));
        }
        $profile = $this->profileResolver->resolve($request);
        if (($profile->socialLogin['mode'] ?? null) !== SocialLoginMode::Enabled->value) {
            throw new SocialLoginTestException(SocialLoginTestException::REASON_SOCIAL_DISABLED, sprintf('Social login is not enabled for the profile "%s".', $profile->name));
        }
        $credential = $this->credentials->findOneByProvider($provider);
        if (!$credential instanceof SocialLoginCredential) {
            throw new SocialLoginTestException(SocialLoginTestException::REASON_UNKNOWN_PROVIDER, 'No social credential has this provider key.');
        }

        $redirectUri = $this->urlGenerator->generate($profile->routes['social_login_check']['name'], ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL);
        /** @var array<string, bool> $pkceOverrides */
        $pkceOverrides = $profile->socialLogin['pkce'] ?? [];
        /** @var array<string, mixed> $authorizationParams */
        $authorizationParams = $profile->socialLogin['authorization_params'] ?? [];
        $loginHint           = LoginHint::normalize($loginHint);

        if ($this->oidcClient instanceof OidcClient && $this->oidcClient->supports($credential)) {
            try {
                return $this->oidcClient->begin($credential, $provider, $redirectUri, $this->stateStore, $pkceOverrides, $authorizationParams, $loginHint, true)['url'];
            } catch (OidcException $exception) {
                throw new SocialLoginTestException(SocialLoginTestException::REASON_PROVIDER, 'The provider could not be reached: ' . $exception->reason . '.', $exception);
            }
        }
        $codeVerifier = $this->oauth2Client->usesPkce($credential, $pkceOverrides) ? Pkce::generateVerifier() : null;
        $state        = $this->stateStore->issue($provider, $codeVerifier, null, true);

        return $this->oauth2Client->buildAuthorizeUrl($credential, $redirectUri, $state, $codeVerifier, $authorizationParams, $loginHint);
    }

    /**
     * Whether a test handler is registered (a host can hide its *Test sign-in* button without one).
     */
    public function hasHandler(): bool
    {
        foreach ($this->testHandlers as $handler) {
            if ($handler instanceof SocialLoginTestHandlerInterface) {
                return true;
            }
        }

        return false;
    }
}
