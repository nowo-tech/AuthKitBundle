<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sample notifier that logs request metadata (development/demo only).
 *
 * Never logs reset URLs, link tokens, or OTP codes (REQ-OBS-001).
 */
final class LoggingPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        $this->logger->info('Password reset requested', [
            'bundle'     => 'nowo-tech/auth-kit-bundle',
            'action'     => 'password_reset_notify',
            'identifier' => $context->maskedIdentifier ?? '[redacted]',
            'delivery'   => $context->deliveryMode->value,
            'expires_at' => $token->expiresAt->format(DateTimeInterface::ATOM),
        ]);
    }
}
