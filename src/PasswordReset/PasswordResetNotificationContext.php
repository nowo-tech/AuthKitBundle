<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;

/**
 * Context passed to notifiers so apps can send email, SMS, OTP, push, etc.
 */
final readonly class PasswordResetNotificationContext
{
    public function __construct(
        public string $identifier,
        public string $resetUrl,
        public PasswordResetDeliveryMode $deliveryMode,
        public ?string $maskedIdentifier = null,
    ) {
    }
}
