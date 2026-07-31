<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use PHPUnit\Framework\TestCase;

final class QrLoginChallengeEntityTest extends TestCase
{
    private function challenge(?DateTimeImmutable $expiresAt = null): QrLoginChallenge
    {
        return new QrLoginChallenge(
            id: '550e8400-e29b-41d4-a716-446655440000',
            publicCode: 'AB7K2M9Q',
            desktopCookieHash: hash('sha256', 'cookie_value'),
            desktopIpHash: hash_hmac('sha256', '192.168.1.1', 'secret'),
            desktopUaHash: hash_hmac('sha256', 'Chrome', 'secret'),
            desktopUaLabel: 'Chrome · Windows',
            approveTokenHash: hash('sha256', 'approve_token'),
            expiresAt: $expiresAt ?? new DateTimeImmutable('+90 seconds'),
        );
    }

    public function testConstructorSetsPendingStatus(): void
    {
        $challenge = $this->challenge();

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $challenge->getId());
        self::assertSame('AB7K2M9Q', $challenge->getPublicCode());
        self::assertSame(QrLoginChallengeStatus::Pending, $challenge->getStatus());
        self::assertNull($challenge->getUserClass());
        self::assertNull($challenge->getUserId());
        self::assertNull($challenge->getPhoneHint());
        self::assertNull($challenge->getApproveTokenUsedAt());
        self::assertNull($challenge->getApprovedAt());
        self::assertNull($challenge->getConsumedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $challenge->getCreatedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $challenge->getUpdatedAt());
    }

    public function testIsExpiredReturnsFalseBeforeExpiry(): void
    {
        $challenge = $this->challenge(new DateTimeImmutable('+60 seconds'));
        self::assertFalse($challenge->isExpired());
    }

    public function testIsExpiredReturnsTrueAfterExpiry(): void
    {
        $challenge = $this->challenge(new DateTimeImmutable('-1 second'));
        self::assertTrue($challenge->isExpired());
    }

    public function testMarkApproved(): void
    {
        $challenge = $this->challenge();
        $challenge->markApproved('App\\Entity\\User', 'user@test.com', '+34 *** 22');

        self::assertSame(QrLoginChallengeStatus::Approved, $challenge->getStatus());
        self::assertSame('App\\Entity\\User', $challenge->getUserClass());
        self::assertSame('user@test.com', $challenge->getUserId());
        self::assertSame('+34 *** 22', $challenge->getPhoneHint());
        self::assertNotNull($challenge->getApprovedAt());
        self::assertNotNull($challenge->getApproveTokenUsedAt());
    }

    public function testMarkDenied(): void
    {
        $challenge = $this->challenge();
        $challenge->markDenied();

        self::assertSame(QrLoginChallengeStatus::Denied, $challenge->getStatus());
        self::assertNotNull($challenge->getApproveTokenUsedAt());
    }

    public function testMarkConsumed(): void
    {
        $challenge = $this->challenge();
        $challenge->markApproved('App\\Entity\\User', 'user@test.com', null);
        $challenge->markConsumed();

        self::assertSame(QrLoginChallengeStatus::Consumed, $challenge->getStatus());
        self::assertNotNull($challenge->getConsumedAt());
    }

    public function testMarkExpired(): void
    {
        $challenge = $this->challenge(new DateTimeImmutable('-1 second'));
        $challenge->markExpired();

        self::assertSame(QrLoginChallengeStatus::Expired, $challenge->getStatus());
    }

    public function testSetStatus(): void
    {
        $challenge = $this->challenge();
        $challenge->setStatus(QrLoginChallengeStatus::Denied);

        self::assertSame(QrLoginChallengeStatus::Denied, $challenge->getStatus());
    }

    public function testSetUserClassAndId(): void
    {
        $challenge = $this->challenge();
        $challenge->setUserClass('App\\User');
        $challenge->setUserId('42');

        self::assertSame('App\\User', $challenge->getUserClass());
        self::assertSame('42', $challenge->getUserId());
    }

    public function testSetPhoneHint(): void
    {
        $challenge = $this->challenge();
        $challenge->setPhoneHint('+34 *** 99');

        self::assertSame('+34 *** 99', $challenge->getPhoneHint());
    }

    public function testDesktopHashes(): void
    {
        $challenge = $this->challenge();

        self::assertSame(hash('sha256', 'cookie_value'), $challenge->getDesktopCookieHash());
        self::assertSame(hash_hmac('sha256', '192.168.1.1', 'secret'), $challenge->getDesktopIpHash());
        self::assertSame(hash_hmac('sha256', 'Chrome', 'secret'), $challenge->getDesktopUaHash());
        self::assertSame('Chrome · Windows', $challenge->getDesktopUaLabel());
    }

    public function testApproveTokenHash(): void
    {
        $challenge = $this->challenge();
        self::assertSame(hash('sha256', 'approve_token'), $challenge->getApproveTokenHash());
    }

    public function testMarkApproveTokenUsed(): void
    {
        $challenge = $this->challenge();
        self::assertNull($challenge->getApproveTokenUsedAt());

        $challenge->markApproveTokenUsed();
        self::assertNotNull($challenge->getApproveTokenUsedAt());
    }

    public function testExpiresAt(): void
    {
        $exp       = new DateTimeImmutable('+120 seconds');
        $challenge = $this->challenge($exp);

        self::assertSame($exp, $challenge->getExpiresAt());
    }
}
