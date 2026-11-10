<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\User;

use Nowo\AuthKitBundle\Tests\Stub\__CG__\Nowo\AuthKitBundle\Tests\Stub\TestUser as ProxyNamedUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\User\UserIds;
use PHPUnit\Framework\TestCase;

/**
 * KL-I7: `UserIds::className()` drops a Doctrine proxy prefix, so events carry the real user class.
 */
final class UserIdsClassNameTest extends TestCase
{
    public function testTheProxyPrefixIsDropped(): void
    {
        self::assertSame(TestUser::class, UserIds::className(new ProxyNamedUser()));
        self::assertSame(TestUser::class, UserIds::className(new TestUser('a@example.com')));
    }
}
