<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use PHPUnit\Framework\TestCase;

final class SocialLoginEntitiesTest extends TestCase
{
    public function testCredentialAccessors(): void
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setLabel('Google')
            ->setClientId('id')
            ->setClientSecret('secret')
            ->setEnabled(true)
            ->setEnterpriseSso(false)
            ->setScopes(['email'])
            ->setAuthorizeUrl(null)
            ->setTokenUrl(null)
            ->setUserinfoUrl(null);

        self::assertNull($credential->getId());
        self::assertSame('google', $credential->getProvider());
        self::assertSame('Google', $credential->getLabel());
        self::assertSame('id', $credential->getClientId());
        self::assertSame('secret', $credential->getClientSecret());
        self::assertTrue($credential->isEnabled());
        self::assertFalse($credential->isEnterpriseSso());
        self::assertSame(['email'], $credential->getScopes());
        self::assertTrue($credential->setEnterpriseSso(true)->isEnterpriseSso());
        self::assertInstanceOf(DateTimeImmutable::class, $credential->getCreatedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $credential->getUpdatedAt());
        self::assertGreaterThanOrEqual($credential->getCreatedAt()->getTimestamp(), $credential->getUpdatedAt()->getTimestamp());
    }

    public function testAccountAccessors(): void
    {
        $account = (new SocialLoginAccount())
            ->setProvider('github')
            ->setProviderUserId('42')
            ->setUserClass('App\\Entity\\User')
            ->setUserId('1')
            ->setUserIdentifier('a@b.c')
            ->setAccessToken('tok')
            ->setRefreshToken('ref')
            ->setTokenExpiresAt(null)
            ->setEmail('a@b.c')
            ->setDisplayName('A')
            ->setRawProfile(['id' => '42']);

        self::assertNull($account->getId());
        self::assertSame('github', $account->getProvider());
        self::assertSame('42', $account->getProviderUserId());
        self::assertSame('App\\Entity\\User', $account->getUserClass());
        self::assertSame('1', $account->getUserId());
        self::assertSame('a@b.c', $account->getUserIdentifier());
        self::assertSame('tok', $account->getAccessToken());
        self::assertSame('ref', $account->getRefreshToken());
        self::assertNull($account->getTokenExpiresAt());
        self::assertSame('a@b.c', $account->getEmail());
        self::assertSame('A', $account->getDisplayName());
        self::assertSame(['id' => '42'], $account->getRawProfile());
        self::assertInstanceOf(DateTimeImmutable::class, $account->getCreatedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $account->getUpdatedAt());
        self::assertGreaterThanOrEqual($account->getCreatedAt()->getTimestamp(), $account->getUpdatedAt()->getTimestamp());
    }
}
