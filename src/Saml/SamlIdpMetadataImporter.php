<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use Throwable;

use function array_values;
use function count;
use function is_array;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function sprintf;
use function strlen;

/**
 * Fills a provider row from IdP metadata XML (2.10.0): the entity id, the SingleSignOnService of the HTTP-Redirect
 * binding and the signing certificates (current and rollover; more than two distinct ones are refused). The host
 * fetches the document (upload,
 * or its own HTTP client with its own SSRF policy); AuthKit does not fetch metadata URLs in 2.10.0. XML is parsed
 * without DTD or external entities. Nothing is written to the row unless every value is accepted. Stateless.
 */
final class SamlIdpMetadataImporter
{
    public function __construct(
        private readonly ?SamlIssuerGuard $issuerGuard = null,
    ) {
    }

    public const MAX_METADATA_BYTES = 1048576;

    /**
     * Reads the document and writes the row (only when every value is accepted).
     *
     * @param string|null $entityId the entity to read in an aggregate (several EntityDescriptor); null: the first one
     *
     * @throws InvalidArgumentException see {@see self::parse()}; or when the row refuses a value
     * @throws LogicException when the entity id would change while `saml:<key>` links exist ({@see SamlIssuerGuard})
     */
    public function import(SamlProvider $provider, string $xml, ?string $entityId = null): SamlIdpMetadata
    {
        $metadata = $this->parse($xml, $entityId);
        // Validated on a scratch row first: the host's row changes only when every value is accepted.
        (new SamlProvider())->setIdpEntityId($metadata->entityId)->setIdpSsoUrl($metadata->ssoUrl);
        $this->issuerGuard?->assertEntityIdChangeAllowed($provider, $metadata->entityId);
        $provider->setIdpEntityId($metadata->entityId)->setIdpSsoUrl($metadata->ssoUrl)->setIdpCertificates($metadata->certificates[0]->pem, ($metadata->certificates[1] ?? null)?->pem);

        return $metadata;
    }

    /**
     * Reads the document without writing anything (a dry run for an administrator's preview). Identical certificates
     * (listed as `x509cert` and again in `x509certMulti`, or twice) count once.
     *
     * @throws InvalidArgumentException when the document cannot be read, lacks an entity id, a redirect SSO URL or a
     *                                  signing certificate, holds an unusable certificate or more than two distinct ones
     */
    public function parse(string $xml, ?string $entityId = null): SamlIdpMetadata
    {
        if ($xml === '' || strlen($xml) > self::MAX_METADATA_BYTES) {
            throw new InvalidArgumentException('The IdP metadata is empty or larger than 1 MiB.');
        }
        $internalErrors = libxml_use_internal_errors(true);

        try {
            $parsed = IdPMetadataParser::parseXML($xml, $entityId, null, Constants::BINDING_HTTP_REDIRECT, Constants::BINDING_HTTP_REDIRECT);
        } catch (Throwable) {
            throw new InvalidArgumentException('The IdP metadata cannot be parsed.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }
        $idp          = is_array($parsed['idp'] ?? null) ? $parsed['idp'] : [];
        $entity       = $idp['entityId'] ?? null;
        $sso          = is_array($idp['singleSignOnService'] ?? null) ? $idp['singleSignOnService'] : [];
        $url          = $sso['url'] ?? null;
        $values       = [$idp['x509cert'] ?? null, ...(is_array($idp['x509certMulti']['signing'] ?? null) ? $idp['x509certMulti']['signing'] : [])];
        $certificates = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $certificate = SamlCertificate::fromPem($value);
                $certificates[$certificate->sha256] ??= $certificate;
            }
        }
        if (!is_string($entity) || $entity === '' || !is_string($url) || ($sso['binding'] ?? null) !== Constants::BINDING_HTTP_REDIRECT || $certificates === []) {
            throw new InvalidArgumentException('The IdP metadata needs an entity id, a SingleSignOnService of the HTTP-Redirect binding and a signing certificate.');
        }
        if (count($certificates) > 2) {
            throw new InvalidArgumentException(sprintf('The IdP metadata lists %d distinct signing certificates; a provider row accepts two (current and rollover).', count($certificates)));
        }

        return new SamlIdpMetadata($entity, $url, array_values($certificates));
    }
}
