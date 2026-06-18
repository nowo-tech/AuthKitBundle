<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;

/**
 * Plain reset credential returned to notifiers (never persist the plain value as-is).
 */
final readonly class PasswordResetTokenResult
{
    public function __construct(
        public object $user,
        public string $plainToken,
        public DateTimeImmutable $expiresAt,
        public PasswordResetDeliveryMode $deliveryMode,
    ) {
    }

    /**
     * URL-safe token when delivery includes a link.
     */
    public function linkToken(): ?string
    {
        if ($this->deliveryMode === PasswordResetDeliveryMode::Code) {
            return null;
        }

        if ($this->deliveryMode === PasswordResetDeliveryMode::Both) {
            return explode(':', $this->plainToken, 2)[0];
        }

        return $this->plainToken;
    }

    /**
     * Short code when delivery includes OTP/SMS/email code.
     */
    public function code(): ?string
    {
        if ($this->deliveryMode === PasswordResetDeliveryMode::Link) {
            return null;
        }

        if ($this->deliveryMode === PasswordResetDeliveryMode::Both) {
            return explode(':', $this->plainToken, 2)[1] ?? null;
        }

        return $this->plainToken;
    }
}
