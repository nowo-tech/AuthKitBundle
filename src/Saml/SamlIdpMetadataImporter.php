<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use Throwable;

use function is_array;
use function is_string;
use function strlen;

/**
 * Fills a provider row from IdP metadata XML (2.10.0): the entity id, the SingleSignOnService of the HTTP-Redirect
 * binding and the signing certificates (the first two: current and rollover). The host fetches the document (upload,
 * or its own HTTP client with its own SSRF policy); AuthKit does not fetch metadata URLs in 2.10.0. XML is parsed
 * without DTD or external entities. Nothing is written to the row unless every value is accepted. Stateless.
 */
final class SamlIdpMetadataImporter
{
    public const MAX_METADATA_BYTES = 1048576;

    /**
     * @param string|null $entityId the entity to read in an aggregate (several EntityDescriptor); null: the first one
     *
     * @throws InvalidArgumentException when the document cannot be read or lacks an entity id, a redirect SSO URL or a
     *                                  signing certificate
     */
    public function import(SamlProvider $provider, string $xml, ?string $entityId = null): void
    {
        if ($xml === '' || strlen($xml) > self::MAX_METADATA_BYTES) {
            throw new InvalidArgumentException('The IdP metadata is empty or larger than 1 MiB.');
        }

        try {
            $parsed = IdPMetadataParser::parseXML($xml, $entityId, null, Constants::BINDING_HTTP_REDIRECT, Constants::BINDING_HTTP_REDIRECT);
        } catch (Throwable) {
            throw new InvalidArgumentException('The IdP metadata cannot be parsed.');
        }
        $idp          = is_array($parsed['idp'] ?? null) ? $parsed['idp'] : [];
        $entity       = $idp['entityId'] ?? null;
        $sso          = is_array($idp['singleSignOnService'] ?? null) ? $idp['singleSignOnService'] : [];
        $url          = $sso['url'] ?? null;
        $certificates = [];
        if (is_string($idp['x509cert'] ?? null) && $idp['x509cert'] !== '') {
            $certificates[] = $idp['x509cert'];
        }
        foreach ((is_array($idp['x509certMulti']['signing'] ?? null) ? $idp['x509certMulti']['signing'] : []) as $certificate) {
            if (is_string($certificate) && $certificate !== '') {
                $certificates[] = $certificate;
            }
        }
        if (!is_string($entity) || $entity === '' || !is_string($url) || ($sso['binding'] ?? null) !== Constants::BINDING_HTTP_REDIRECT || $certificates === []) {
            throw new InvalidArgumentException('The IdP metadata needs an entity id, a SingleSignOnService of the HTTP-Redirect binding and a signing certificate.');
        }
        $current = $certificates[0];
        $next    = $certificates[1] ?? null;

        // Validated on a scratch row first: the host's row changes only when every value is accepted.
        (new SamlProvider())->setIdpEntityId($entity)->setIdpSsoUrl($url)->setIdpCertificates($current, $next);
        $provider->setIdpEntityId($entity)->setIdpSsoUrl($url)->setIdpCertificates($current, $next);
    }
}
