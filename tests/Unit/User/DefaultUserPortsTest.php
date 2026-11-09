<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\User;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Tests\Stub\HostDomainUser;
use Nowo\AuthKitBundle\Tests\Stub\RoleWritableUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\User\CredentialsChangedListener;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use Nowo\AuthKitBundle\User\Event\UserRegisteredEvent;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\PropertyAccessUserFactory;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserIds;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/** 1.41: the default user ports reproduce the Doctrine + PropertyAccessor behaviour of earlier releases. */
final class DefaultUserPortsTest extends TestCase
{
    public function testDoctrineLookupFindsByIdWithTheNumericCastAndRefreshes(): void
    {
        $profile = ProfileRegistryFactory::single(TestUser::class)->getDefault();
        $user    = new TestUser('a@example.com');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class, int|string $id): ?object => match ($id) {
            7       => $user,
            'abc'   => new stdClass(),
            default => null,
        });
        $em->method('contains')->willReturn(true);
        $em->expects(self::once())->method('refresh')->with($user);

        $lookup = new DoctrineUserLookup($em);
        self::assertSame($user, $lookup->findById($profile, '7'));
        self::assertNull($lookup->findById($profile, 'abc'), 'A non-user row is not a user.');
        self::assertNull($lookup->findById($profile, '8'));
    }

    public function testDoctrineLookupIgnoresNonUsersAndCounts(): void
    {
        $profile         = ProfileRegistryFactory::single(TestUser::class)->getDefault();
        $notAUser        = new stdClass();
        $notAUser->email = 'a@example.com';
        $repository      = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$notAUser]);
        $repository->method('count')->willReturn(3);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $lookup = new DoctrineUserLookup($em);
        self::assertNull($lookup->findByIdentifier($profile, 'a@example.com'));
        self::assertSame(3, $lookup->countUsers($profile));
    }

    public function testDoctrineLookupCaseVariantNeedsAMappedField(): void
    {
        $profile = ProfileRegistryFactory::single(TestUser::class)->getDefault();

        $unmapped = $this->createMock(ClassMetadata::class);
        $unmapped->method('hasField')->willReturn(false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($unmapped);
        $em->expects(self::never())->method('createQueryBuilder');
        self::assertFalse((new DoctrineUserLookup($em))->hasCaseVariant($profile, 'email', 'A@example.com'));

    }

    public function testDoctrineWriterPersistsFlushesAndWritesThePasswordProperty(): void
    {
        $user = new TestUser('a@example.com');
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('persist')->with($user);
        $em->expects(self::once())->method('remove')->with($user);
        $em->expects(self::exactly(3))->method('flush');

        $writer  = new DoctrineUserWriter($em, PropertyAccess::createPropertyAccessor());
        $profile = ProfileRegistryFactory::single(TestUser::class)->getDefault();
        $writer->add($profile, $user);
        $writer->changePassword($profile, $user, 'hash-1');
        $writer->save($profile, $user);
        $writer->remove($profile, $user);
        self::assertSame('hash-1', $user->getPassword());

        // Without a password registration field the property is "password".
        $noPasswordField = ProfileRegistryFactory::single(RoleWritableUser::class, [
            'registration_fields' => FieldConfigNormalizer::normalizeRegistrationFields(['email']),
        ])->getDefault();
        $other = new RoleWritableUser();
        (new DoctrineUserWriter($this->createMock(EntityManagerInterface::class), PropertyAccess::createPropertyAccessor()))
            ->changePassword($noPasswordField, $other, 'hash-2');
        self::assertSame('hash-2', $other->password);
    }

    public function testPropertyAccessFactoryBuildsSocialUsers(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn (object $u, string $plain): string => 'hashed:' . $plain);
        $factory = new PropertyAccessUserFactory($hasher, PropertyAccess::createPropertyAccessor());
        $profile = ProfileRegistryFactory::single(RoleWritableUser::class)->getDefault();

        $user = $factory->create($profile, NewUser::forSocial('s@example.com', 'Sam', 'random', ['ROLE_USER']));
        self::assertInstanceOf(RoleWritableUser::class, $user);
        self::assertSame('hashed:random', $user->password);
        self::assertSame(['ROLE_USER'], $user->roles);
    }

    public function testNewUserNamedConstructors(): void
    {
        $form = NewUser::fromForm(['email' => 'a@example.com'], ['ROLE_USER']);
        self::assertSame(UserCreationSource::Form, $form->source);
        self::assertSame(['email' => 'a@example.com'], $form->formData);

        $admin = NewUser::forAdmin('root', 'pw', ['ROLE_ADMIN']);
        self::assertSame([UserCreationSource::Admin, 'root', 'pw', null], [$admin->source, $admin->identifier, $admin->plainPassword, $admin->displayName]);

        $social = NewUser::forSocial('s@example.com', 'Sam', 'rnd', []);
        self::assertSame([UserCreationSource::Social, 'Sam'], [$social->source, $social->displayName]);
    }

    public function testUserIdsAndRegisteredEvent(): void
    {
        $withId = new TestUser('a@example.com');
        $withId->setId(42);
        self::assertSame('42', UserIds::of($withId));
        self::assertNull(UserIds::of(new TestUser('b@example.com')));

        $property     = new stdClass();
        $property->id = new class implements Stringable {
            public function __toString(): string
            {
                return 'uuid-x';
            }
        };
        self::assertSame('uuid-x', UserIds::of($property));

        $event = UserRegisteredEvent::of('main', $withId, UserCreationSource::Invitation);
        self::assertSame(['main', TestUser::class, '42', 'a@example.com', UserCreationSource::Invitation, ['ROLE_USER']], [
            $event->profile, $event->userClass, $event->userId, $event->userIdentifier, $event->source, $event->roles,
        ]);
    }

    public function testCredentialsChangedClearsTheResetCredentialOfProfilesWithResetOn(): void
    {
        $user = HostDomainUser::register('uuid-1', 'a@example.com', 'h', []);

        $tokens = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokens->expects(self::once())->method('clearForUser')->with($user);
        $enabled = ProfileRegistryFactory::single(HostDomainUser::class, ['password_reset' => ['mode' => 'enabled']]);
        (new CredentialsChangedListener($enabled, $tokens))(new CredentialsChangedEvent($user));

        $untouched = $this->createMock(PasswordResetTokenManagerInterface::class);
        $untouched->expects(self::never())->method('clearForUser');
        (new CredentialsChangedListener(ProfileRegistryFactory::single(HostDomainUser::class), $untouched))(new CredentialsChangedEvent($user, false));
        (new CredentialsChangedListener(ProfileRegistryFactory::single(TestUser::class), $untouched))(new CredentialsChangedEvent($user));
    }
}
