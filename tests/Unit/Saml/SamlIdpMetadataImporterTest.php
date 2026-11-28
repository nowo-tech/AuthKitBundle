<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Saml\SamlCertificate;
use Nowo\AuthKitBundle\Saml\SamlIdpMetadataImporter;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function preg_replace;
use function str_repeat;
use function trim;

#[CoversClass(SamlIdpMetadataImporter::class)]
final class SamlIdpMetadataImporterTest extends TestCase
{
    public function testTheEntitySsoUrlAndTwoSigningCertificatesAreImported(): void
    {
        $row = new SamlProvider();
        (new SamlIdpMetadataImporter())->import($row, $this->metadata(['idp', 'idp-next', 'attacker']));

        self::assertSame('https://idp.example.com/saml', $row->getIdpEntityId());
        self::assertSame('https://idp.example.com/sso/redirect', $row->getIdpSsoUrl());
        self::assertSame(SamlCertificate::fromPem(SamlKeys::pair('idp')['cert'])->sha256, $row->getIdpCertificateSha256());
        self::assertSame(SamlCertificate::fromPem(SamlKeys::pair('idp-next')['cert'])->sha256, $row->getIdpNextCertificateSha256());

        (new SamlIdpMetadataImporter())->import($row, $this->metadata(['idp']));
        self::assertNull($row->getIdpNextCertificate());
    }

    public function testIncompleteOrUnreadableMetadataChangesNothing(): void
    {
        $row = (new SamlProvider())->setIdpEntityId('https://before.example.com');
        foreach (['', str_repeat('a', SamlIdpMetadataImporter::MAX_METADATA_BYTES + 1), '<not-xml', $this->metadata([]), $this->metadata(['idp'], 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'), $this->metadata(['idp'], null, 'http://idp.example.com/sso')] as $xml) {
            try {
                (new SamlIdpMetadataImporter())->import($row, $xml);
                self::fail('Imported.');
            } catch (InvalidArgumentException) {
                self::assertSame('https://before.example.com', $row->getIdpEntityId());
            }
        }
    }

    /**
     * @param list<string> $certificates names of throwaway pairs
     */
    private function metadata(array $certificates, ?string $binding = null, string $url = 'https://idp.example.com/sso/redirect'): string
    {
        $keys = '';
        foreach ($certificates as $name) {
            $body = trim((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', SamlKeys::pair($name)['cert']));
            $keys .= '<md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>' . $body . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>';
        }

        return '<?xml version="1.0"?><md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="https://idp.example.com/saml">'
            . '<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">' . $keys
            . '<md:SingleSignOnService Binding="' . ($binding ?? 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect') . '" Location="' . $url . '"/>'
            . '</md:IDPSSODescriptor></md:EntityDescriptor>';
    }
}
