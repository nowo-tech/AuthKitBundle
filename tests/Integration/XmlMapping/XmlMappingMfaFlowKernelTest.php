<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\XmlMapping;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaFlowKernelTest;

/**
 * Every test of {@see MfaFlowKernelTest} again with `doctrine.mapping_type: xml` and the private entity manager
 * (1.46.0), on an `auto_mapping` default manager.
 */
final class XmlMappingMfaFlowKernelTest extends MfaFlowKernelTest
{
    protected static function getKernelClass(): string
    {
        return XmlMfaTestKernel::class;
    }

    public function testTheAuthKitEntitiesAreMappedFromXml(): void
    {
        self::assertTrue(self::getContainer()->hasParameter(NowoAuthKitExtension::XML_MAPPING_ENABLED_PARAMETER));
        self::assertFalse(self::getContainer()->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));
        $chain = $this->em()->getConfiguration()->getMetadataDriverImpl();
        if ($chain instanceof MappingDriver) {
            $chain = $chain->getDriver();
        }
        self::assertInstanceOf(MappingDriverChain::class, $chain);
        self::assertInstanceOf(XmlDriver::class, $chain->getDrivers()['Nowo\\AuthKitBundle\\Entity'] ?? null);
    }
}
