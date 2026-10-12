<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Admin;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Admin\AdminUserCreatedEvent;
use Nowo\AuthKitBundle\Admin\AdminUserCreatingEvent;
use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\UnknownProfileException;
use Nowo\AuthKitBundle\Tests\Stub\NoRolesTestUser;
use Nowo\AuthKitBundle\Tests\Stub\RoleWritableUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class AdminUserCreatorTest extends TestCase
{
    public function testCreatesUserWithHashedPasswordRolesAndEvents(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(TestUser::class));
        $entityManager->expects(self::once())->method('flush');

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AdminUserCreatingEvent::class, static function (AdminUserCreatingEvent $event) use (&$events): void {
            $events[] = ['creating', $event->getUser()->getUserIdentifier(), $event->getProfileName()];
        });
        $dispatcher->addListener(AdminUserCreatedEvent::class, static function (AdminUserCreatedEvent $event) use (&$events): void {
            $events[] = ['created', $event->getUser()->getUserIdentifier(), $event->getProfileName(), $event->getRoles()];
        });

        $user = $this->creator(ProfileRegistryFactory::single(TestUser::class), $entityManager, $dispatcher)
            ->create('owner@example.com', 'secret-password', ['ROLE_ADMIN']);

        self::assertInstanceOf(TestUser::class, $user);
        self::assertSame('owner@example.com', $user->getEmail());
        self::assertSame('hashed:secret-password', $user->getPassword());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        self::assertSame([
            ['creating', 'owner@example.com', 'default'],
            ['created', 'owner@example.com', 'default', ['ROLE_ADMIN']],
        ], $events);
    }

    public function testWritesRolesThroughPropertyAccessAndCustomPasswordProperty(): void
    {
        $registry = ProfileRegistryFactory::single(RoleWritableUser::class, [], 'admin');

        $user = $this->creator($registry, $this->createMock(EntityManagerInterface::class))
            ->create('root@example.com', 'pw', ['ROLE_SUPER_ADMIN'], 'admin');

        self::assertInstanceOf(RoleWritableUser::class, $user);
        self::assertSame(['ROLE_SUPER_ADMIN'], $user->roles);
        self::assertSame('hashed:pw', $user->password);

        $noRoles = $this->registry(NoRolesTestUser::class, [
            'user_identifier_field' => 'username',
            'registration_fields'   => FieldConfigNormalizer::normalizeRegistrationFields([
                ['name' => 'username', 'type' => 'text'],
                ['name' => 'plain', 'type' => 'password', 'property' => 'secret', 'hash' => true],
            ]),
        ]);
        $other = $this->creator($noRoles, $this->createMock(EntityManagerInterface::class))
            ->create('root', 'pw', ['ROLE_ADMIN']);

        self::assertInstanceOf(NoRolesTestUser::class, $other);
        self::assertSame('root', $other->username);
        self::assertSame('hashed:pw', $other->secret);
        self::assertSame(['ROLE_USER'], $other->getRoles());
    }

    public function testFallsBackToPasswordPropertyWithoutHashedRegistrationField(): void
    {
        $registry = $this->registry(RoleWritableUser::class, [
            'registration_fields' => FieldConfigNormalizer::normalizeRegistrationFields(['email']),
        ]);

        $user = $this->creator($registry, $this->createMock(EntityManagerInterface::class))
            ->create('root@example.com', 'pw', ['ROLE_ADMIN']);

        self::assertInstanceOf(RoleWritableUser::class, $user);
        self::assertSame('hashed:pw', $user->password);
    }

    public function testExistsLooksUpTheIdentifierField(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?TestUser => $criteria === ['email' => 'taken@example.com'] ? new TestUser('taken@example.com') : null,
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(TestUser::class)->willReturn($repository);

        $creator = $this->creator(ProfileRegistryFactory::single(TestUser::class), $entityManager);

        self::assertTrue($creator->exists('taken@example.com'));
        self::assertFalse($creator->exists('free@example.com', 'default'));
    }

    public function testUnknownProfileThrows(): void
    {
        $this->expectException(UnknownProfileException::class);

        $this->creator(ProfileRegistryFactory::single(TestUser::class), $this->createMock(EntityManagerInterface::class))
            ->profile('missing');
    }

    public function testUniqueViolationBecomesRuntimeException(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException(
            new UniqueConstraintViolationException(new class('Duplicate entry') extends AbstractException { }, null),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin creation failed');

        $this->creator(ProfileRegistryFactory::single(TestUser::class), $entityManager)
            ->create('owner@example.com', 'pw', ['ROLE_ADMIN']);
    }

    /**
     * @param array<string, mixed> $overrides replaced (not merged) top-level profile keys
     */
    private function registry(string $userClass, array $overrides): ProfileRegistry
    {
        return ProfileRegistryFactory::fromProfiles(['default' => $overrides + ProfileRegistryFactory::defaultProfileConfig($userClass)]);
    }

    private function creator(ProfileRegistry $registry, EntityManagerInterface $entityManager, ?EventDispatcher $dispatcher = null): AdminUserCreator
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn (object $user, string $plain): string => 'hashed:' . $plain);

        return new AdminUserCreator($registry, $entityManager, $hasher, new PropertyAccessor(), $dispatcher);
    }
}
