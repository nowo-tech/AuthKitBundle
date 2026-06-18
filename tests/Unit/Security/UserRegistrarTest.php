<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Stub\RoleWritableUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class UserRegistrarTest extends TestCase
{
    public function testRegisterPersistsUserWithHashedPasswordAndRole(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(TestUser::class));
        $entityManager->expects(self::once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-secret');

        $registrar = new UserRegistrar(
            TestUser::class,
            'ROLE_ADMIN',
            [[
                'name'          => 'email',
                'type'          => 'email',
                'property'      => 'email',
                'hash'          => false,
                'required'      => true,
                'security_name' => null,
            ], [
                'name'          => 'password',
                'type'          => 'password',
                'property'      => 'password',
                'hash'          => true,
                'required'      => true,
                'security_name' => null,
            ]],
            $entityManager,
            $hasher,
            new PropertyAccessor(),
        );

        $user = $registrar->register([
            'email'    => 'user@example.com',
            'password' => 'plain-password',
        ]);

        self::assertSame('user@example.com', $user->getUserIdentifier());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testRegisterAssignsRoleViaPropertyAccessorWhenSetRolesMissing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(RoleWritableUser::class));
        $entityManager->expects(self::once())->method('flush');

        $registrar = new UserRegistrar(
            RoleWritableUser::class,
            'ROLE_EDITOR',
            [[
                'name'          => 'email',
                'type'          => 'email',
                'property'      => 'email',
                'hash'          => false,
                'required'      => true,
                'security_name' => null,
            ]],
            $entityManager,
            $this->createMock(UserPasswordHasherInterface::class),
            new PropertyAccessor(),
        );

        $user = $registrar->register(['email' => 'editor@example.com']);

        self::assertInstanceOf(RoleWritableUser::class, $user);
        self::assertSame(['ROLE_EDITOR'], $user->roles);
    }
}
