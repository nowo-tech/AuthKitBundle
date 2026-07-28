<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\LoggingPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\NullPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

use function array_key_exists;

final class PasswordResetNotifiersTest extends TestCase
{
    public function testNullNotifierIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();
        (new NullPasswordResetNotifier())->notify($this->tokenResult(), $this->context());
    }

    public function testLoggingNotifierWritesContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Password reset requested',
            self::callback(static function (array $context): bool {
                foreach (['reset_url', 'link_token', 'code'] as $secret) {
                    if (array_key_exists($secret, $context)) {
                        return false;
                    }
                }

                return ($context['action'] ?? null) === 'password_reset_notify'
                    && ($context['identifier'] ?? null) === 'u***@example.com';
            }),
        );

        (new LoggingPasswordResetNotifier($logger))->notify($this->tokenResult(), $this->context());
    }

    private function tokenResult(): PasswordResetTokenResult
    {
        return new PasswordResetTokenResult(
            new stdClass(),
            'abc:123456',
            new DateTimeImmutable(),
            PasswordResetDeliveryMode::Both,
        );
    }

    private function context(): PasswordResetNotificationContext
    {
        return new PasswordResetNotificationContext(
            identifier: 'user@example.com',
            resetUrl: 'https://example.test/reset',
            deliveryMode: PasswordResetDeliveryMode::Both,
            maskedIdentifier: 'u***@example.com',
        );
    }
}
