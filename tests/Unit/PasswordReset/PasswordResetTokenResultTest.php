<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PasswordResetTokenResultTest extends TestCase
{
    public function testLinkDeliveryHelpers(): void
    {
        $result = new PasswordResetTokenResult(
            new stdClass(),
            'abc123',
            new DateTimeImmutable(),
            PasswordResetDeliveryMode::Link,
        );

        self::assertSame('abc123', $result->linkToken());
        self::assertNull($result->code());
    }

    public function testCodeDeliveryHelpers(): void
    {
        $result = new PasswordResetTokenResult(
            new stdClass(),
            '654321',
            new DateTimeImmutable(),
            PasswordResetDeliveryMode::Code,
        );

        self::assertNull($result->linkToken());
        self::assertSame('654321', $result->code());
    }

    public function testBothDeliveryHelpers(): void
    {
        $result = new PasswordResetTokenResult(
            new stdClass(),
            'deadbeef:654321',
            new DateTimeImmutable(),
            PasswordResetDeliveryMode::Both,
        );

        self::assertSame('deadbeef', $result->linkToken());
        self::assertSame('654321', $result->code());
    }
}
