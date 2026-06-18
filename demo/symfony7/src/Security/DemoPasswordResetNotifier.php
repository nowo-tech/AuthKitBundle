<?php

declare(strict_types=1);

namespace App\Security;

use Nowo\AuthKitBundle\PasswordReset\LoggingPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;

/**
 * Sample password reset delivery for the demo app.
 */
final class DemoPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function __construct(
        private readonly LoggingPasswordResetNotifier $loggingNotifier,
    ) {
    }

    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        $this->loggingNotifier->notify($token, $context);
    }
}
