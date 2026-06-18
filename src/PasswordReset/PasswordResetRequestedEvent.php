<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a reset credential is created and before notifiers run.
 */
final class PasswordResetRequestedEvent extends Event
{
    public function __construct(
        private readonly PasswordResetTokenResult $tokenResult,
        private readonly PasswordResetNotificationContext $context,
    ) {
    }

    public function getTokenResult(): PasswordResetTokenResult
    {
        return $this->tokenResult;
    }

    public function getContext(): PasswordResetNotificationContext
    {
        return $this->context;
    }
}
