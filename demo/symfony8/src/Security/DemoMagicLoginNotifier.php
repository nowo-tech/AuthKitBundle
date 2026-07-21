<?php

declare(strict_types=1);

namespace App\Security;

use Nowo\AuthKitBundle\MagicLogin\LoggingMagicLoginNotifier;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;

/**
 * Demo magic-login delivery: logs + stores the signed URL in session for UI try-out.
 */
final class DemoMagicLoginNotifier implements MagicLoginNotifierInterface
{
    public function __construct(
        private readonly LoggingMagicLoginNotifier $loggingNotifier,
        private readonly DemoDeliveryInbox $inbox,
    ) {
    }

    public function notify(MagicLoginNotificationContext $context): void
    {
        $this->loggingNotifier->notify($context);
        $this->inbox->rememberMagicLogin($context);
    }
}
