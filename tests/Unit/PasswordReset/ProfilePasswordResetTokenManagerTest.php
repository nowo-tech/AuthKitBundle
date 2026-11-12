<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\ProfilePasswordResetTokenManager;
use Nowo\AuthKitBundle\Tests\Stub\ChildTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.46.0: each call goes to the store of the user's / named profile; users of no profile and `user_entity` profiles to
 * the user-entity store.
 */
final class ProfilePasswordResetTokenManagerTest extends TestCase
{
    public function testRoutesEachCallToTheProfileStore(): void
    {
        $profiles = ProfileRegistryFactory::fromProfiles([
            'default' => ProfileRegistryFactory::defaultProfileConfig(ChildTestUser::class),
            'table'   => ['password_reset' => ['storage' => 'table'] + ProfileRegistryFactory::defaultProfileConfig(TestUser::class)['password_reset']] + ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
        ]);
        $result = new PasswordResetTokenResult(new TestUser(), 'plain', new DateTimeImmutable(), PasswordResetDeliveryMode::Link);

        $userEntity = $this->createMock(PasswordResetTokenManagerInterface::class);
        $table      = $this->createMock(PasswordResetTokenManagerInterface::class);
        $table->expects(self::once())->method('createForUser')->willReturn($result);
        $table->expects(self::once())->method('clearForUser');
        $table->expects(self::once())->method('resolveUserByLinkToken')->with('t', 'table')->willReturn(null);
        $table->expects(self::once())->method('resolveUserByIdentifierAndCode')->with('i', 'c', 'table')->willReturn(null);
        $userEntity->expects(self::once())->method('createForUser')->willReturn($result);
        $userEntity->expects(self::exactly(2))->method('clearForUser');
        $userEntity->expects(self::once())->method('resolveUserByLinkToken')->with('t', null)->willReturn(null);
        $userEntity->expects(self::once())->method('resolveUserByIdentifierAndCode')->with('i', 'c', null)->willReturn(null);

        $manager = new ProfilePasswordResetTokenManager($profiles, $userEntity, $table);
        self::assertSame($result, $manager->createForUser(new TestUser()));
        self::assertSame($result, $manager->createForUser(new ChildTestUser()));
        $manager->clearForUser(new TestUser());
        $manager->clearForUser(new ChildTestUser());
        $manager->clearForUser(new stdClass());
        self::assertNull($manager->resolveUserByLinkToken('t', 'table'));
        self::assertNull($manager->resolveUserByLinkToken('t'));
        self::assertNull($manager->resolveUserByIdentifierAndCode('i', 'c', 'table'));
        self::assertNull($manager->resolveUserByIdentifierAndCode('i', 'c'));
    }
}
