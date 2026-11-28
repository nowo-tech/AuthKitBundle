<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Settings;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * The onelogin/php-saml settings of one provider row (2.10.0), always strict: signed AuthnRequests (RSA-SHA256), the
 * Destination required and matched, unsolicited responses with an InResponseTo refused, schema validation, RSA 1.5 key
 * transport refused, both IdP certificates accepted for the signature. A signature on the Response or the Assertion
 * is always required by the library (`NO_SIGNATURE_FOUND`), so neither `wantMessagesSigned` nor `wantAssertionsSigned`
 * forces one of them (IdPs sign one or the other); {@see SamlResponseValidator} checks it again. Stateless.
 *
 * @internal
 */
final class SamlSettingsFactory
{
    public const SLOT_CURRENT = 'current';

    public const SLOT_NEXT = 'next';

    /**
     * @param string $slot which SP key decrypts ({@see self::SLOT_CURRENT} signs AuthnRequests and is published first)
     *
     * @throws \OneLogin\Saml2\Error when the row's values are refused by the library
     */
    public function create(SamlProvider $provider, string $spEntityId, string $acsUrl, string $slot = self::SLOT_CURRENT): Settings
    {
        return new Settings($this->toArray($provider, $spEntityId, $acsUrl, $slot));
    }

    /**
     * The settings array (for {@see \OneLogin\Saml2\Auth}, which builds its own settings object).
     *
     * @return array<string, mixed>
     */
    public function toArray(SamlProvider $provider, string $spEntityId, string $acsUrl, string $slot = self::SLOT_CURRENT): array
    {
        $next        = $slot === self::SLOT_NEXT;
        $certificate = (string) ($next ? $provider->getSpNextCertificate() : $provider->getSpCertificate());
        $sp          = [
            'entityId'                 => $spEntityId,
            'assertionConsumerService' => ['url' => $acsUrl, 'binding' => Constants::BINDING_HTTP_POST],
            'NameIDFormat'             => $provider->getNameIdFormat() ?? Constants::NAMEID_UNSPECIFIED,
            'x509cert'                 => $certificate,
            'privateKey'               => (string) ($next ? $provider->getSpNextPrivateKey() : $provider->getSpPrivateKey()),
        ];
        // Published in the metadata during a rotation (the library lists x509certNew next to x509cert).
        if (!$next && $provider->getSpNextCertificate() !== null) {
            $sp['x509certNew'] = $provider->getSpNextCertificate();
        }
        $idpCertificates = $provider->getIdpCertificates();

        return [
            'strict'  => true,
            'debug'   => false,
            'baseurl' => null,
            'sp'      => $sp,
            'idp'     => [
                'entityId'            => $provider->getIdpEntityId(),
                'singleSignOnService' => ['url' => $provider->getIdpSsoUrl(), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509cert'            => $idpCertificates[0] ?? '',
                'x509certMulti'       => ['signing' => $idpCertificates, 'encryption' => []],
            ],
            'security' => [
                'authnRequestsSigned'                        => true,
                'logoutRequestSigned'                        => false,
                'logoutResponseSigned'                       => false,
                'signMetadata'                               => false,
                'wantMessagesSigned'                         => false,
                'wantAssertionsSigned'                       => false,
                'wantAssertionsEncrypted'                    => $provider->isWantEncryptedAssertions(),
                'wantNameId'                                 => false,
                'wantNameIdEncrypted'                        => false,
                'nameIdEncrypted'                            => false,
                'requestedAuthnContext'                      => false,
                'wantXMLValidation'                          => true,
                'relaxDestinationValidation'                 => false,
                'requireDestination'                         => true,
                'destinationStrictlyMatches'                 => true,
                'allowRepeatAttributeName'                   => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm'                         => XMLSecurityKey::RSA_SHA256,
                'digestAlgorithm'                            => XMLSecurityDSig::SHA256,
                'allowRSA15KeyTransport'                     => false,
                'lowercaseUrlencoding'                       => false,
            ],
        ];
    }
}
