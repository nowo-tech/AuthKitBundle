<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestedEvent;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PasswordResetRequestedEventTest extends TestCase
{
    public function testAccessors(): void
    {
        $token   = new PasswordResetTokenResult(new stdClass(), 'x', new DateTimeImmutable(), PasswordResetDeliveryMode::Link);
        $context = new PasswordResetNotificationContext('a@b.c', 'https://x', PasswordResetDeliveryMode::Link);
        $event   = new PasswordResetRequestedEvent($token, $context);

        self::assertSame($token, $event->getTokenResult());
        self::assertSame($context, $event->getContext());
    }
}
