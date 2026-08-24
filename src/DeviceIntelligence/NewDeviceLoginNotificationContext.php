<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DeviceIntelligence;

/**
 * Host notifier payload after a successful login from a new device cluster.
 */
final readonly class NewDeviceLoginNotificationContext
{
    public function __construct(
        public string $userIdentifier,
        public string $profileName,
    ) {
    }
}
