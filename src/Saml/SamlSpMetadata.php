<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use OneLogin\Saml2\Settings;

/**
 * The SP metadata XML of a provider row (2.10.0): entity id, ACS (HTTP-POST), `AuthnRequestsSigned="true"`,
 * `WantAssertionsSigned="true"` (IdPs then sign the assertion; a signed Response alone is accepted too), the requested
 * NameID format and the current certificate for signing and encryption, plus the next one during a rotation. No
 * private key ever appears. Stateless.
 */
final class SamlSpMetadata
{
    public function __construct(
        private readonly SamlSettingsFactory $settingsFactory,
        private readonly SamlUrls $urls,
    ) {
    }

    /**
     * @throws \OneLogin\Saml2\Error when the row's values are refused by the library
     */
    public function build(SamlProvider $provider, ProfileSettings $profile): string
    {
        $settings                                     = $this->settingsFactory->toArray($provider, $this->urls->spEntityId($profile, $provider), $this->urls->acsUrl($profile, $provider->getProvider()));
        $settings['security']['wantAssertionsSigned'] = true;

        return (new Settings($settings, true))->getSPMetadata(true);
    }
}
