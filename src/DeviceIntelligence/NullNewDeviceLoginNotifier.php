<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DeviceIntelligence;

/**
 * Default notifier — no delivery.
 */
final class NullNewDeviceLoginNotifier implements NewDeviceLoginNotifierInterface
{
    public function notify(NewDeviceLoginNotificationContext $context): void
    {
    }
}
