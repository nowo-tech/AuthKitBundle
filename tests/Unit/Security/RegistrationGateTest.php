<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class RegistrationGateTest extends TestCase
{
    public function testDisabledMode(): void
    {
        $gate = new RegistrationGate(
            $this->createMock(EntityManagerInterface::class),
            TestUser::class,
            'disabled',
        );

        self::assertFalse($gate->isRegistrationAllowed());
    }

    public function testAlwaysMode(): void
    {
        $gate = new RegistrationGate(
            $this->createMock(EntityManagerInterface::class),
            TestUser::class,
            'always',
        );

        self::assertTrue($gate->isRegistrationAllowed());
    }

    public function testFirstUserOnlyWhenEmpty(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $gate = new RegistrationGate($entityManager, TestUser::class, 'first_user_only');

        self::assertTrue($gate->isRegistrationAllowed());
    }

    public function testFirstUserOnlyWhenUsersExist(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(2);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $gate = new RegistrationGate($entityManager, TestUser::class, 'first_user_only');

        self::assertFalse($gate->isRegistrationAllowed());
    }
}
