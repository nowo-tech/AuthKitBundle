<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\ValueObject;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\ValueObject\QrLoginChallengeRef;
use PHPUnit\Framework\TestCase;

use function hash;
use function print_r;

final class QrLoginChallengeRefTest extends TestCase
{
    public function testFromChallengeCopiesTheNonSecretFields(): void
    {
        $expires   = new DateTimeImmutable('+60 seconds');
        $challenge = new QrLoginChallenge('cid', 'CODE1234', hash('sha256', 'cookie'), hash('sha256', 'ip'), hash('sha256', 'ua'), 'Firefox', hash('sha256', 'approve-token'), $expires);

        $ref = QrLoginChallengeRef::fromChallenge($challenge);

        self::assertSame('cid', $ref->id);
        self::assertSame('CODE1234', $ref->publicCode);
        self::assertSame(QrLoginChallengeStatus::Pending, $ref->status);
        self::assertNull($ref->userClass);
        self::assertNull($ref->userId);
        self::assertSame('Firefox', $ref->desktopUaLabel);
        self::assertEquals($expires, $ref->expiresAt);
        self::assertSame($challenge->getCreatedAt(), $ref->createdAt);
        self::assertNull($ref->approvedAt);
        self::assertNull($ref->consumedAt);

        $dump = print_r($ref, true);
        foreach (['cookie', 'ip', 'approve-token'] as $secret) {
            self::assertStringNotContainsString(hash('sha256', $secret), $dump);
        }
    }
}
