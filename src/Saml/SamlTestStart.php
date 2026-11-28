<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SamlProviderRepository;
use Nowo\AuthKitBundle\SocialLogin\LoginHint;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestException;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestStart;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;

/**
 * Mints a SAML *test sign-in* (2.10.0), as {@see SocialLoginTestStart} does for OAuth:
 * the AuthnRequest URL of a provider row whose pending request carries a one-shot test marker in the session. Its
 * response runs every check of a real sign-in, then only the host's {@see SocialLoginTestHandlerInterface} (provider
 * `saml:<key>`): never signs in, links or provisions. No URL flag starts a test.
 *
 * Call it from an action only administrators reach (a POST with a CSRF token). The row may be disabled (test before you
 * enable); it must exist and be complete, and the profile's `saml.mode` must be `enabled`. Stateless.
 */
final class SamlTestStart
{
    /**
     * @param iterable<mixed> $testHandlers tagged {@see SocialLoginTestHandlerInterface::TAG}
     */
    public function __construct(
        private readonly RequestProfileResolver $profileResolver,
        private readonly SamlProviderRepository $providers,
        private readonly SamlLoginStart $loginStart,
        private readonly iterable $testHandlers = [],
    ) {
    }

    /**
     * The URL to send the administrator's browser to.
     *
     * @param string|null $loginHint an e-mail address forwarded as `login_hint` when valid
     *
     * @throws SocialLoginTestException `no_handler`, `saml_disabled`, `unknown_provider`, `provider` (incomplete row)
     */
    public function start(Request $request, string $provider, ?string $loginHint = null): string
    {
        if (!$this->hasHandler()) {
            throw new SocialLoginTestException(SocialLoginTestException::REASON_NO_HANDLER, sprintf('No "%s" is registered: a test sign-in would have no handler.', SocialLoginTestHandlerInterface::class));
        }
        $profile = $this->profileResolver->resolve($request);
        if (!$profile->samlEnabled()) {
            throw new SocialLoginTestException(SocialLoginTestException::REASON_SAML_DISABLED, sprintf('SAML is not enabled for the profile "%s".', $profile->name));
        }
        $row = $this->providers->findOneByProvider($provider);
        if (!$row instanceof SamlProvider) {
            throw new SocialLoginTestException(SocialLoginTestException::REASON_UNKNOWN_PROVIDER, 'No SAML provider has this key.');
        }

        try {
            return $this->loginStart->url($request, $profile, $row, LoginHint::normalize($loginHint), true);
        } catch (SamlFailure $failure) {
            throw new SocialLoginTestException(SocialLoginTestException::REASON_PROVIDER, 'The SAML provider is not completely set up: ' . $failure->reason . '.', $failure);
        }
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
