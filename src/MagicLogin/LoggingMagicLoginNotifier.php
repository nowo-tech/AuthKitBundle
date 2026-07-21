<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sample notifier that logs the magic login URL (development/demo only).
 */
final class LoggingMagicLoginNotifier implements MagicLoginNotifierInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(MagicLoginNotificationContext $context): void
    {
        $this->logger->info('Magic login link requested', [
            'identifier' => $context->maskedIdentifier ?? $context->identifier,
            'login_url'  => $context->loginUrl,
            'expires_at' => $context->expiresAt->format(DateTimeInterface::ATOM),
        ]);
    }
}
