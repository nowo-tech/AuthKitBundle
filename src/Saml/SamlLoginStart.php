<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use OneLogin\Saml2\Auth;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function is_int;

/**
 * Builds the signed AuthnRequest redirect of an SP-initiated sign-in (2.10.0, HTTP-Redirect binding, RSA-SHA256 with the
 * current SP key) and stores its request id and RelayState as the session's pending request ({@see SamlRequestStore}).
 * An e-mail hint (already normalised by the caller) goes to the IdP as `login_hint`. Stateless: the onelogin objects are
 * built per call.
 *
 * @internal
 */
final class SamlLoginStart
{
    public function __construct(
        private readonly SamlSettingsFactory $settingsFactory,
        private readonly SamlRequestStore $requestStore,
        private readonly SamlUrls $urls,
    ) {
    }

    /**
     * @throws SamlFailure `unavailable` when the row is incomplete or refused by the library
     */
    public function url(Request $request, ProfileSettings $profile, SamlProvider $provider, ?string $loginHint, bool $test = false): string
    {
        if (!$provider->isComplete()) {
            throw new SamlFailure(SamlFailure::UNAVAILABLE, 'The SAML provider is not completely set up.');
        }
        $relayState = SamlRequestStore::newRelayState();

        try {
            $auth = new Auth($this->settingsFactory->toArray($provider, $this->urls->spEntityId($profile, $provider), $this->urls->acsUrl($profile, $provider->getProvider())));
            $url  = $auth->login($relayState, $loginHint !== null ? ['login_hint' => $loginHint] : [], false, false, true);
            $id   = $auth->getLastRequestID();
        } catch (Throwable) {
            throw new SamlFailure(SamlFailure::UNAVAILABLE, 'The SAML request could not be built from the provider settings.');
        }
        $lifetime = $profile->saml['request_lifetime'] ?? 600;
        $this->requestStore->issue($request->getSession(), $profile->name, $provider->getProvider(), (string) $id, $relayState, is_int($lifetime) ? $lifetime : 600, $test);

        return $url;
    }
}
