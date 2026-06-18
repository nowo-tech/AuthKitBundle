<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sample notifier that logs credentials (development/demo only).
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
            'identifier' => $context->maskedIdentifier ?? $context->identifier,
            'delivery'   => $context->deliveryMode->value,
            'reset_url'  => $context->resetUrl,
            'link_token' => $token->linkToken(),
            'code'       => $token->code(),
            'expires_at' => $token->expiresAt->format(DateTimeInterface::ATOM),
        ]);
    }
}
