<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function print_r;
use function serialize;
use function unserialize;
use function var_export;

final class AuthUserRefTest extends TestCase
{
    private function user(): TestUser
    {
        $user = new TestUser('ada@example.com');
        $user->setId(7);
        $user->setPassword('$2y$13$SECRET-HASH');

        return $user;
    }

    public function testOfKeepsTheUserObjectHidden(): void
    {
        $user = $this->user();
        $ref  = AuthUserRef::of($user, TestUser::class, '7', 'ada@example.com', ['ROLE_USER', 3, 'ROLE_ADMIN']);

        self::assertSame($user, $ref->user());
        self::assertSame(TestUser::class, $ref->userClass);
        self::assertSame('7', $ref->id);
        self::assertSame('ada@example.com', $ref->identifier);
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $ref->roles);
        self::assertTrue($ref->hasId());
        self::assertTrue($ref->hasRole('ROLE_ADMIN'));
        self::assertFalse($ref->hasRole('ROLE_ROOT'));

        foreach ([var_export($ref, true), print_r($ref, true), (string) json_encode($ref), serialize($ref), print_r((array) $ref, true)] as $dump) {
            self::assertStringNotContainsString('SECRET-HASH', $dump);
        }
    }

    public function testCloneAndUnserializedCopyCarryNoUser(): void
    {
        $ref = AuthUserRef::of($this->user(), TestUser::class, '7', 'ada@example.com');

        self::assertNull((clone $ref)->user());
        $copy = unserialize(serialize($ref));
        self::assertInstanceOf(AuthUserRef::class, $copy);
        self::assertNull($copy->user());
        self::assertSame('7', $copy->id);
    }

    public function testOfIdHasNoUserObject(): void
    {
        $ref = AuthUserRef::ofId('App\\User', '42', 'bob@example.com', ['ROLE_USER']);

        self::assertNull($ref->user());
        self::assertSame(['ROLE_USER'], $ref->roles);
        self::assertSame('', AuthUserRef::ofId('App\\User', '1')->identifier);
    }

    public function testNullIdIsAllowedButBlankIdIsRefused(): void
    {
        $ref = new AuthUserRef('App\\User', null, 'x');
        self::assertFalse($ref->hasId());
        self::assertSame([], $ref->roles);

        $this->expectException(InvalidArgumentException::class);
        new AuthUserRef('App\\User', '  ');
    }

    public function testOfIdRefusesAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuthUserRef::ofId('App\\User', '');
    }

    public function testIsSameUserAs(): void
    {
        $a = AuthUserRef::ofId('App\\User', '1');

        self::assertTrue($a->isSameUserAs(AuthUserRef::ofId('App\\User', '1', 'other')));
        self::assertFalse($a->isSameUserAs(AuthUserRef::ofId('App\\Admin', '1')));
        self::assertFalse($a->isSameUserAs(AuthUserRef::ofId('App\\User', '01')));
        self::assertFalse((new AuthUserRef('App\\User', null))->isSameUserAs(new AuthUserRef('App\\User', null)));
    }
}
