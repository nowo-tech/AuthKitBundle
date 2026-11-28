<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;

/**
 * A complete provider row for the SAML tests (2.10.0): the throwaway IdP certificate, the throwaway SP pair and the
 * attribute mapping of {@see SamlResponseBuilder}'s default attributes.
 */
final class SamlProviders
{
    public static function acme(string $provider = 'acme'): SamlProvider
    {
        $sp = SamlKeys::pair('sp');

        return (new SamlProvider())
            ->setProvider($provider)
            ->setLabel('Acme')
            ->setEnabled(true)
            ->setIdpEntityId(SamlResponseBuilder::IDP_ENTITY_ID)
            ->setIdpSsoUrl('https://idp.example.com/sso')
            ->setIdpCertificates(SamlKeys::pair('idp')['cert'])
            ->setSpKeyPair($sp['key'], $sp['cert'])
            ->setAttributeMapping(['email' => 'mail', 'first_name' => 'givenName', 'last_name' => 'sn', 'groups' => 'groups']);
    }
}
