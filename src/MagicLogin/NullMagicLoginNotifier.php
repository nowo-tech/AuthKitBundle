<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

/**
 * Default notifier — no delivery (apps must alias their own implementation).
 */
final class NullMagicLoginNotifier implements MagicLoginNotifierInterface
{
    public function notify(MagicLoginNotificationContext $context): void
    {
    }
}
