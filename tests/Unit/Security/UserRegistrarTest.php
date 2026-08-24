<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Stub\RoleWritableUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class UserRegistrarTest extends TestCase
{
    public function testRegisterPersistsUserWithHashedPasswordAndRole(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(TestUser::class));
        $entityManager->expects(self::once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-secret');

        $registrar = new UserRegistrar(
            ProfileRegistryFactory::single(TestUser::class, [
                'registration_role'   => 'ROLE_ADMIN',
                'registration_fields' => [[
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
            ]),
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
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(RoleWritableUser::class));
        $entityManager->expects(self::once())->method('flush');

        $registrar = new UserRegistrar(
            ProfileRegistryFactory::single(RoleWritableUser::class, [
                'registration_role'   => 'ROLE_EDITOR',
                'registration_fields' => [[
                    'name'          => 'email',
                    'type'          => 'email',
                    'property'      => 'email',
                    'hash'          => false,
                    'required'      => true,
                    'security_name' => null,
                ]],
            ]),
            $entityManager,
            $this->createMock(UserPasswordHasherInterface::class),
            new PropertyAccessor(),
        );

        $user = $registrar->register(['email' => 'editor@example.com']);

        self::assertInstanceOf(RoleWritableUser::class, $user);
        self::assertSame(['ROLE_EDITOR'], $user->roles);
    }

    public function testFirstUserOnlyRollsBackWhenRaceDetected(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturnOnConsecutiveCalls(0, 2);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('remove')->with(self::isInstanceOf(TestUser::class));
        $entityManager->expects(self::exactly(2))->method('flush');

        $registrar = new UserRegistrar(
            ProfileRegistryFactory::single(TestUser::class, [
                'registration_mode'   => 'first_user_only',
                'registration_fields' => [[
                    'name'          => 'email',
                    'type'          => 'email',
                    'property'      => 'email',
                    'hash'          => false,
                    'required'      => true,
                    'security_name' => null,
                ]],
            ]),
            $entityManager,
            $this->createMock(UserPasswordHasherInterface::class),
            new PropertyAccessor(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Registration race');

        $registrar->register(['email' => 'race@example.com']);
    }

    public function testFirstUserOnlyRejectsWhenUsersAlreadyExist(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');

        $registrar = new UserRegistrar(
            ProfileRegistryFactory::single(TestUser::class, [
                'registration_mode'   => 'first_user_only',
                'registration_fields' => [[
                    'name'          => 'email',
                    'type'          => 'email',
                    'property'      => 'email',
                    'hash'          => false,
                    'required'      => true,
                    'security_name' => null,
                ]],
            ]),
            $entityManager,
            $this->createMock(UserPasswordHasherInterface::class),
            new PropertyAccessor(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Registration is closed');

        $registrar->register(['email' => 'late@example.com']);
    }

    public function testRegisterSkipsUnmappedFields(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (TestUser $user): bool {
                return $user->getUserIdentifier() === 'user@example.com';
            },
        ));
        $entityManager->expects(self::once())->method('flush');

        $registrar = new UserRegistrar(
            ProfileRegistryFactory::single(TestUser::class, [
                'registration_fields' => [[
                    'name'             => 'email',
                    'type'             => 'email',
                    'property'         => 'email',
                    'hash'             => false,
                    'required'         => true,
                    'mapped'           => true,
                    'slide_to_confirm' => false,
                    'security_name'    => null,
                ], [
                    'name'             => 'terms',
                    'type'             => 'checkbox',
                    'property'         => 'terms',
                    'hash'             => false,
                    'required'         => true,
                    'mapped'           => false,
                    'slide_to_confirm' => true,
                    'security_name'    => null,
                ]],
            ]),
            $entityManager,
            $this->createMock(UserPasswordHasherInterface::class),
            new PropertyAccessor(),
        );

        $user = $registrar->register([
            'email' => 'user@example.com',
            'terms' => true,
        ]);

        self::assertSame('user@example.com', $user->getUserIdentifier());
    }
}
