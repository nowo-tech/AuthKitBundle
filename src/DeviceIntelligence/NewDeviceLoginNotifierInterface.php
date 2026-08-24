<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DeviceIntelligence;

/**
 * Delivers a “new browser signed in” notice. Default is a no-op; apps alias their own mailer.
 */
interface NewDeviceLoginNotifierInterface
{
    public function notify(NewDeviceLoginNotificationContext $context): void;
}
