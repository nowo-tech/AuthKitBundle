<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;

/**
 * Context passed to notifiers so apps can send email, SMS, OTP, push, etc.
 *
 * Security: deliver ONLY to `identifier`, the identifier STORED on the account (since 1.31; before it was the value
 * typed in the request form), or to a channel read from `user` (the resolved account, also on the token result).
 * Never to request input.
 */
final readonly class PasswordResetNotificationContext
{
    public function __construct(
        public string $identifier,
        public string $resetUrl,
        public PasswordResetDeliveryMode $deliveryMode,
        public ?string $maskedIdentifier = null,
        /** Recipient's preferred locale (allowed by locale.enabled), or null to use the request locale. */
        public ?string $recipientLocale = null,
        /** The resolved account (since 1.31). */
        public ?object $user = null,
    ) {
    }
}
