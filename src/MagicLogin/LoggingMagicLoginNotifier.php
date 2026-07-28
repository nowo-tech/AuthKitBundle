<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sample notifier that logs request metadata (development/demo only).
 *
 * Never logs magic login URLs or tokens (REQ-OBS-001).
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
            'bundle'     => 'nowo-tech/auth-kit-bundle',
            'action'     => 'magic_login_notify',
            'identifier' => $context->maskedIdentifier ?? '[redacted]',
            'expires_at' => $context->expiresAt->format(DateTimeInterface::ATOM),
        ]);
    }
}
