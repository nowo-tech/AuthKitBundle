<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Saml\SamlSpKeyGenerator;
use Nowo\AuthKitBundle\Saml\SamlSpKeyPair;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function str_repeat;

#[CoversClass(SamlSpKeyGenerator::class)]
#[CoversClass(SamlSpKeyPair::class)]
final class SamlSpKeyGeneratorTest extends TestCase
{
    public function testAPairFitsAProviderSlot(): void
    {
        $pair = (new SamlSpKeyGenerator())->generate('sp.example.com', 30);
        $key  = openssl_pkey_get_private($pair->privateKeyPem);
        self::assertNotFalse($key);
        self::assertSame(SamlSpKeyGenerator::KEY_BITS, openssl_pkey_get_details($key)['bits'] ?? null);

        $row = (new SamlProvider())->setSpKeyPair($pair->privateKeyPem, $pair->certificatePem);
        self::assertEquals($pair->notAfter, $row->getSpCertificateNotAfter());
        self::assertGreaterThan(time() + 29 * 86400, $pair->notAfter->getTimestamp());
    }

    public function testInvalidNamesAndDurationsAreRefused(): void
    {
        foreach ([['', 30], [str_repeat('a', 65), 30], ["sp\nexample", 30], ['sp', 29], ['sp', 3651]] as [$name, $days]) {
            try {
                (new SamlSpKeyGenerator())->generate($name, $days);
                self::fail('Generated.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
