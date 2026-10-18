<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;

/**
 * Keeps the last reset credential so kernel tests can follow the link / enter the code.
 */
final class CapturingPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public ?PasswordResetTokenResult $last = null;

    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        $this->last = $token;
    }
}
