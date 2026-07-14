<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class RegistrationGateTest extends TestCase
{
    public function testDisabledMode(): void
    {
        $gate = new RegistrationGate(
            $this->createMock(EntityManagerInterface::class),
            ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'disabled']),
        );

        self::assertFalse($gate->isRegistrationAllowed());
    }

    public function testAlwaysMode(): void
    {
        $gate = new RegistrationGate(
            $this->createMock(EntityManagerInterface::class),
            ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'always']),
        );

        self::assertTrue($gate->isRegistrationAllowed());
    }

    public function testFirstUserOnlyWhenEmpty(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $gate = new RegistrationGate(
            $entityManager,
            ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'first_user_only']),
        );

        self::assertTrue($gate->isRegistrationAllowed());
    }

    public function testFirstUserOnlyWhenUsersExist(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(2);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $gate = new RegistrationGate(
            $entityManager,
            ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'first_user_only']),
        );

        self::assertFalse($gate->isRegistrationAllowed());
    }
}
