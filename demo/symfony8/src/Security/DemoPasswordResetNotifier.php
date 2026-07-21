<?php

declare(strict_types=1);

namespace App\Security;

use Nowo\AuthKitBundle\PasswordReset\LoggingPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;

/**
 * Demo password-reset delivery: logs + stores the link/code in session for UI try-out.
 */
final class DemoPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function __construct(
        private readonly LoggingPasswordResetNotifier $loggingNotifier,
        private readonly DemoDeliveryInbox $inbox,
    ) {
    }

    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        $this->loggingNotifier->notify($token, $context);
        $this->inbox->rememberPasswordReset($token, $context);
    }
}
