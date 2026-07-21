<?php

declare(strict_types=1);

namespace App\Security;

use Nowo\AuthKitBundle\MagicLogin\LoggingMagicLoginNotifier;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;

/**
 * Sample magic-login delivery for the demo app (logs the URL).
 */
final class DemoMagicLoginNotifier implements MagicLoginNotifierInterface
{
    public function __construct(
        private readonly LoggingMagicLoginNotifier $loggingNotifier,
    ) {
    }

    public function notify(MagicLoginNotificationContext $context): void
    {
        $this->loggingNotifier->notify($context);
    }
}
