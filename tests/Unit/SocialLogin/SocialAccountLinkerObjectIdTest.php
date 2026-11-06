<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A host whose id is an object (a Symfony Uid) gets the id stored, not the user identifier.
 */
final class SocialAccountLinkerObjectIdTest extends TestCase
{
    public function testAnObjectIdFromGetIdIsStoredAsItsString(): void
    {
        $user = new class($this->uid()) extends ObjectIdUser {
            public function getId(): Stringable
            {
                return $this->uid;
            }
        };

        self::assertSame('01890000-uid', $this->resolve($user));
    }

    public function testAnObjectIdFromAPublicPropertyIsStoredAsItsString(): void
    {
        $user = new class($this->uid()) extends ObjectIdUser {
            public Stringable $id;

            public function __construct(Stringable $uid)
            {
                parent::__construct($uid);
                $this->id = $uid;
            }
        };

        self::assertSame('01890000-uid', $this->resolve($user));
    }

    private function uid(): Stringable
    {
        return new class implements Stringable {
            public function __toString(): string
            {
                return '01890000-uid';
            }
        };
    }

    private function resolve(UserInterface $user): mixed
    {
        $linker = new SocialAccountLinker(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(SocialLoginAccountRepository::class),
            PropertyAccess::createPropertyAccessor(),
            $this->createStub(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        return (new ReflectionMethod($linker, 'resolveUserId'))->invoke($linker, $user);
    }
}

abstract class ObjectIdUser implements UserInterface
{
    public function __construct(protected Stringable $uid)
    {
    }

    public function getUserIdentifier(): string
    {
        return 'someone@example.com';
    }

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }
}
