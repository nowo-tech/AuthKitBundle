<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

/**
 * IdP metadata as read by {@see SamlIdpMetadataImporter::parse()} (2.10.0), before anything is written: the entity id,
 * the SingleSignOnService of the HTTP-Redirect binding and the distinct signing certificates (one or two). Immutable.
 */
final readonly class SamlIdpMetadata
{
    /**
     * @param list<SamlCertificate> $certificates distinct, in document order (current first)
     */
    public function __construct(
        public string $entityId,
        public string $ssoUrl,
        public array $certificates,
    ) {
    }
}
