<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Tests\Stub\ParentTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class PasswordResetCompleterTest extends TestCase
{
    public function testCompleteHashesPasswordAndClearsToken(): void
    {
        $user = new TestUser();
        $user->setPasswordResetToken('hash');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::once())->method('clearForUser')->with($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($user);
        $entityManager->expects(self::once())->method('flush');

        $completer = new PasswordResetCompleter(
            $entityManager,
            $hasher,
            new PropertyAccessor(),
            $tokenManager,
            ProfileRegistryFactory::single(TestUser::class),
        );

        $completer->complete($user, 'new-password');

        self::assertSame('hashed', $user->getPassword());
    }

    public function testCompleteUsesDefaultPasswordProperty(): void
    {
        $user = new TestUser();

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);

        $profileRegistry = ProfileRegistryFactory::fromProfiles([
            'default' => array_replace(
                ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
                ['registration_fields' => [[
                    'name'          => 'email',
                    'type'          => 'email',
                    'property'      => 'email',
                    'hash'          => false,
                    'required'      => true,
                    'security_name' => null,
                ]]],
            ),
        ]);

        $completer = new PasswordResetCompleter(
            $this->createMock(EntityManagerInterface::class),
            $hasher,
            new PropertyAccessor(),
            $tokenManager,
            $profileRegistry,
        );

        $completer->complete($user, 'pw');

        self::assertSame('hashed', $user->getPassword());
    }

    public function testCompleteFallsBackToDefaultProfileForUnknownUserClass(): void
    {
        $user = new ParentTestUser();

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);

        $completer = new PasswordResetCompleter(
            $this->createMock(EntityManagerInterface::class),
            $hasher,
            new PropertyAccessor(),
            $tokenManager,
            ProfileRegistryFactory::single(TestUser::class, [
                'registration_fields' => [[
                    'name'          => 'email',
                    'type'          => 'email',
                    'property'      => 'email',
                    'hash'          => false,
                    'required'      => true,
                    'security_name' => null,
                ]],
            ]),
        );

        $completer->complete($user, 'pw');

        self::assertSame('hashed', $user->getPassword());
    }
}
