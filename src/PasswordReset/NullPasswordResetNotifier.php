<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

/**
 * No-op notifier for tests or when delivery is handled only via events.
 */
final class NullPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
    }
}
