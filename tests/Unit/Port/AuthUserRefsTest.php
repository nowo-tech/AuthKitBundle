<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Port;

use LogicException;
use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuthUserRefsTest extends TestCase
{
    public function testFromSymfonyUser(): void
    {
        $user = new TestUser('ada@example.com');
        $user->setId(5);
        $user->setRoles(['ROLE_ADMIN']);

        $ref = AuthUserRefs::fromUser($user);

        self::assertSame(TestUser::class, $ref->userClass);
        self::assertSame('5', $ref->id);
        self::assertSame('ada@example.com', $ref->identifier);
        self::assertContains('ROLE_ADMIN', $ref->roles);
        self::assertSame($user, $ref->user());
    }

    public function testFromUserWithoutIdAndFromPlainObject(): void
    {
        $ref = AuthUserRefs::fromUser(new InMemoryUser('bob', null, ['ROLE_USER']));
        self::assertNull($ref->id);
        self::assertSame('bob', $ref->identifier);

        $object = new stdClass();
        $plain  = AuthUserRefs::fromUser($object);
        self::assertSame(stdClass::class, $plain->userClass);
        self::assertNull($plain->id);
        self::assertSame('', $plain->identifier);
        self::assertSame([], $plain->roles);
        self::assertSame($object, $plain->user());
    }

    public function testABlankIdCountsAsNone(): void
    {
        $ref = AuthUserRefs::fromUser(new class {
            public function getId(): string
            {
                return '  ';
            }
        });

        self::assertNull($ref->id);
    }

    public function testFromNullableUser(): void
    {
        self::assertNull(AuthUserRefs::fromNullableUser(null));
        self::assertInstanceOf(AuthUserRef::class, AuthUserRefs::fromNullableUser(new TestUser()));
    }

    public function testSecurityAndPasswordUser(): void
    {
        $user = new TestUser('ada@example.com');
        $ref  = AuthUserRefs::fromUser($user);

        self::assertSame($user, AuthUserRefs::securityUser($ref, 'Port'));
        self::assertSame($user, AuthUserRefs::passwordUser($ref, 'Port'));
    }

    public function testSecurityUserRefusesAReferenceWithoutASecurityUser(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('UserDirectoryInterface returned a user reference without a Symfony security user object');
        AuthUserRefs::securityUser(AuthUserRef::ofId('App\\User', '1'), 'UserDirectoryInterface');
    }

    public function testPasswordUserRefusesAUserWithoutPassword(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('UserCreatorInterface returned a user that does not implement PasswordAuthenticatedUserInterface');
        AuthUserRefs::passwordUser(AuthUserRefs::fromUser(new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'x';
            }
        }), 'UserCreatorInterface');
    }
}
