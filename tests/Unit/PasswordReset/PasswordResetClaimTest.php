<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetLinkUsedException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\ProfilePasswordResetTokenManager;
use Nowo\AuthKitBundle\Tests\Stub\ClaimingResetStore;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * 2.2.0 (REQ-RUNTIME-003): the completer stores a password only for the request that claimed the credential.
 */
final class PasswordResetClaimTest extends TestCase
{
    public function testAClaimedCredentialStoresThePassword(): void
    {
        $user  = new TestUser();
        $store = new ClaimingResetStore(true);

        $this->completer($store)->complete($user, 'new-password');

        self::assertSame('hashed', $user->getPassword());
        self::assertSame([[$user], []], [$store->claimed, $store->cleared]);
    }

    public function testACredentialAnotherRequestUsedStoresNothing(): void
    {
        $user = new TestUser();
        $user->setPassword('old');
        $store = new ClaimingResetStore(false);

        try {
            $this->completer($store)->complete($user, 'new-password');
            self::fail('A used credential must be refused.');
        } catch (PasswordResetLinkUsedException $used) {
            self::assertSame('The password reset credential was already used.', $used->getMessage());
        }
        self::assertSame('old', $user->getPassword());
    }

    public function testTheProfileStoreDelegatesTheClaim(): void
    {
        $user     = new TestUser();
        $claiming = new ClaimingResetStore(false);
        $profiles = ProfileRegistryFactory::single(TestUser::class);

        self::assertFalse((new ProfilePasswordResetTokenManager($profiles, $claiming, new ClaimingResetStore(true)))->claimForUser($user));
        self::assertSame([$user], $claiming->claimed);
    }

    public function testAProfileStoreWithoutAClaimClearsAsBefore(): void
    {
        $user  = new TestUser();
        $plain = $this->createMock(PasswordResetTokenManagerInterface::class);
        $plain->expects(self::once())->method('clearForUser')->with($user);
        $profiles = ProfileRegistryFactory::single(TestUser::class);

        self::assertTrue((new ProfilePasswordResetTokenManager($profiles, $plain, new ClaimingResetStore(true)))->claimForUser($user));
    }

    private function completer(PasswordResetTokenManagerInterface $store): PasswordResetCompleter
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        return new PasswordResetCompleter($this->createMock(EntityManagerInterface::class), $hasher, new PropertyAccessor(), $store, ProfileRegistryFactory::single(TestUser::class));
    }
}
