<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class MfaManagerUnitTest extends TestCase
{
    public function testAProfileWithoutMfaIsRefusedByName(): void
    {
        $manager = new MfaManager(
            ProfileRegistryFactory::single(TestUser::class),
            new MfaStore($this->createStub(ManagerRegistry::class)),
            new MfaOwnerResolver(),
            new MockClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has mfa.enabled: false');
        $manager->profileNamed('default');
    }

    public function testCompositeIdentifiersCannotHaveMfa(): void
    {
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(false);
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['a' => 1, 'b' => 2]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($factory);
        $em->method('getClassMetadata')->willReturn($metadata);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($em);

        self::assertNull((new MfaOwnerResolver($doctrine))->resolve(new TestUser()));
    }
}
