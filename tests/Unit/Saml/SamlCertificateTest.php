<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Saml\SamlCertificate;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function hash;
use function preg_replace;
use function str_repeat;
use function trim;

#[CoversClass(SamlCertificate::class)]
final class SamlCertificateTest extends TestCase
{
    public function testAPemAndItsBareBodyGiveTheSameCertificate(): void
    {
        $pem         = SamlKeys::pair('idp', 365)['cert'];
        $certificate = SamlCertificate::fromPem("  \n" . $pem);
        $body        = trim((string) preg_replace('/-----[A-Z ]+-----/', '', $pem));

        self::assertSame($certificate->pem, SamlCertificate::fromPem($body)->pem);
        self::assertSame(hash('sha256', (string) base64_decode((string) preg_replace('/\s+/', '', $body), true)), $certificate->sha256);
        self::assertLessThan($certificate->notAfter, $certificate->notBefore);
        self::assertGreaterThan(360 * 86400, $certificate->notAfter->getTimestamp() - $certificate->notBefore->getTimestamp());
    }

    public function testThePrivateKeyMustBelongToTheCertificate(): void
    {
        $idp         = SamlKeys::pair('idp');
        $certificate = SamlCertificate::fromPem($idp['cert']);

        self::assertTrue($certificate->matchesPrivateKey($idp['key']));
        self::assertFalse($certificate->matchesPrivateKey(SamlKeys::pair('sp')['key']));
        self::assertFalse($certificate->matchesPrivateKey('not a key'));
    }

    public function testShortRsaAndOtherKeyTypesAreRefused(): void
    {
        foreach ([SamlKeys::pair('short', 365, 1024)['cert'], SamlKeys::pair('ec', 365, 0, 'ec')['cert']] as $pem) {
            try {
                SamlCertificate::fromPem($pem);
                self::fail('Accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('2048', $exception->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('A', SamlCertificate::MAX_PEM_LENGTH + 1)];
        yield 'another block' => ["-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----"];
        yield 'not base64' => ['@@@@'];
        yield 'bad base64' => ['A'];
        yield 'not a certificate' => ['QUJDRA=='];
    }

    #[DataProvider('unreadable')]
    public function testUnreadableValuesAreRefused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        SamlCertificate::fromPem($value);
    }
}
