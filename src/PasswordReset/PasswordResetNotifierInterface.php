<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

/**
 * Delivers the reset credential (email, SMS, OTP app, webhook, etc.).
 *
 * Implement this interface in your application or use the bundled samples.
 */
interface PasswordResetNotifierInterface
{
    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void;
}
